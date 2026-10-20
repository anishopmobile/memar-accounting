<?php
/**
 * current_affairs/migration.php — Current Affairs module schema (additive, idempotent).
 *
 * Creates the dedicated Current Affairs tables inside the existing database.
 * Follows the project's existing conventions (InnoDB, utf8mb4/utf8mb4_unicode_ci,
 * TIMESTAMP defaults, CREATE TABLE IF NOT EXISTS, named constraints/indexes).
 *
 * Design notes:
 *  - `current_affairs_fields.is_reporting_date` is TINYINT NULL (1 = designated,
 *    NULL = not). The UNIQUE KEY (category_id, is_reporting_date) makes it
 *    impossible to designate more than one Reporting Date for the same category
 *    at the database level (MySQL allows multiple NULLs).
 *  - CHECK condition chk_caf_rep_date ensures a designated Reporting Date is a
 *    Date field and is active — non-Date or inactive designations are rejected
 *    by the database itself.
 *  - CHECK chk_caf_totals ensures `include_in_totals` is only ever enabled on
 *    Number/Money fields.
 *
 * Only additive: never drops or alters existing accounting tables/data.
 *
 * Run with a PHP binary that has mysqli enabled, e.g.:
 *   php -d extension=mysqli assets/php/current_affairs/migration.php
 */
declare(strict_types=1);

/**
 * Apply the Current Affairs schema on the given connection (idempotent).
 *
 * @param mysqli $m Connected MySQLi handle (database already selected).
 * @return array List of error strings (empty on success).
 */
function current_affairs_migrate(mysqli $m): array
{
    $statements = [];

    /* ============================================================
     * 1) Categories — dedicated current-affairs categories
     * ========================================================== */
    $statements[] = "
CREATE TABLE IF NOT EXISTS current_affairs_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cac_active (is_active),
    KEY idx_cac_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

    /* ============================================================
     * 2) Custom fields — dynamic field metadata (structured records,
     *    never dynamic SQL columns)
     * ========================================================== */
    $statements[] = "
CREATE TABLE IF NOT EXISTS current_affairs_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    label VARCHAR(255) NOT NULL,
    field_type ENUM('text','textarea','number','money','date','select','yesno') NOT NULL DEFAULT 'text',
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    show_in_table TINYINT(1) NOT NULL DEFAULT 1,
    is_filterable TINYINT(1) NOT NULL DEFAULT 0,
    include_in_totals TINYINT(1) NOT NULL DEFAULT 0,
    is_reporting_date TINYINT(1) NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_caf_cat_name (category_id, name),
    UNIQUE KEY uq_caf_rep_date (category_id, is_reporting_date),
    KEY idx_caf_cat (category_id),
    KEY idx_caf_type (field_type),
    KEY idx_caf_active (is_active),
    KEY idx_caf_order (category_id, sort_order, id),
    CONSTRAINT fk_caf_cat FOREIGN KEY (category_id) REFERENCES current_affairs_categories(id) ON DELETE CASCADE,
    CONSTRAINT chk_caf_rep_date CHECK (
        is_reporting_date IS NULL OR (is_reporting_date = 1 AND field_type = 'date' AND is_active = 1)
    ),
    CONSTRAINT chk_caf_totals CHECK (
        include_in_totals = 0 OR field_type IN ('number','money')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

    /* ============================================================
     * 3) Select dropdown options
     * ========================================================== */
    $statements[] = "
CREATE TABLE IF NOT EXISTS current_affairs_field_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_id INT NOT NULL,
    option_value VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cafo_field (field_id),
    CONSTRAINT fk_cafo_field FOREIGN KEY (field_id) REFERENCES current_affairs_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

    /* ============================================================
     * 4) Records — one row per dynamic record
     * ========================================================== */
    $statements[] = "
CREATE TABLE IF NOT EXISTS current_affairs_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_car_cat (category_id),
    KEY idx_car_created (created_at),
    KEY idx_car_updated (updated_at),
    CONSTRAINT fk_car_cat FOREIGN KEY (category_id) REFERENCES current_affairs_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

    /* ============================================================
     * 5) Values — typed canonical storage per dynamic field
     *    value_text   : text / textarea / select / yesno
     *    value_number : number / money  (DECIMAL, never formatted strings)
     *    value_date   : date            (DATE, Gregorian Y-m-d)
     * ========================================================== */
    $statements[] = "
CREATE TABLE IF NOT EXISTS current_affairs_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    field_id INT NOT NULL,
    value_text TEXT NULL,
    value_number DECIMAL(20,2) NULL,
    value_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cav_record_field (record_id, field_id),
    KEY idx_cav_field (field_id),
    KEY idx_cav_date (value_date),
    KEY idx_cav_number (value_number),
    KEY idx_cav_text_prefix (value_text(191)),
    CONSTRAINT fk_cav_record FOREIGN KEY (record_id) REFERENCES current_affairs_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_cav_field FOREIGN KEY (field_id) REFERENCES current_affairs_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

    $errors = [];
    foreach ($statements as $sql) {
        if (!$m->query($sql)) {
            $errors[] = "{$sql}\n -> " . ($m->error ?? 'unknown');
        }
    }

    return $errors;
}

/* ---- CLI entry (runs only when executed directly) ---- */
if (isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    require_once __DIR__ . '/../db.php'; // defines global $mysqli

    $migrationErrors = ($mysqli->server_info ?? '') !== ''
        ? current_affairs_migrate($mysqli)
        : ['no database connection'];

    if ($migrationErrors !== []) {
        echo "Current Affairs migration FAILED:\n";
        foreach ($migrationErrors as $err) {
            echo " - " . $err . "\n";
        }
        exit(1);
    }

    echo "Current Affairs migration applied successfully.\n";

    /* --- verification --- */
    $tables = [
        'current_affairs_categories',
        'current_affairs_fields',
        'current_affairs_field_options',
        'current_affairs_records',
        'current_affairs_values',
    ];
    foreach ($tables as $t) {
        $stmt = $mysqli->prepare(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->bind_param('s', $t);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        echo sprintf(" - %-32s %s\n", $t, ($row && (int) $row['c'] === 1) ? 'OK' : 'MISSING');
    }

    $mysqli->close();
    exit(0);
}