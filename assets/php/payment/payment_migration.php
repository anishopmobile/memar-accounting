<?php
/**
 * payment_migration.php — Payment module schema migration (additive, idempotent).
 *
 * Creates the payment tables inside the existing `memar_accounting` database.
 * Follows the project's existing conventions (InnoDB, utf8mb4/utf8mb4_unicode_ci,
 * TIMESTAMP defaults, IF NOT EXISTS, single-row settings table).
 *
 * Only additive: never drops or alters existing accounting tables/data.
 *
 * Run with the web PHP binary that has mysqli enabled, e.g.:
 *   php assets/php/payment/payment_migration.php
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1); // never runnable over HTTP
}

require_once __DIR__ . '/../db.php'; // defines global $mysqli

$statements = [];

/* ============================================================
 * 1) Payment forms
 * ========================================================== */
$statements[] = "
CREATE TABLE IF NOT EXISTS payment_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    amount_mode ENUM('fixed','custom','selectable') NOT NULL DEFAULT 'fixed',
    fixed_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    min_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    max_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    purpose VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at DATETIME NULL,
    success_message TEXT NULL,
    redirect_url VARCHAR(500) NULL,
    redirect_delay_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    notify_customer TINYINT(1) NOT NULL DEFAULT 0,
    notify_admin TINYINT(1) NOT NULL DEFAULT 0,
    theme VARCHAR(80) NOT NULL DEFAULT 'default',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pf_slug (slug),
    KEY idx_pf_active (is_active),
    KEY idx_pf_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

/* ============================================================
 * 2) Dynamic form fields — structured records, never dynamic SQL columns
 * ========================================================== */
$statements[] = "
CREATE TABLE IF NOT EXISTS payment_form_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    field_key VARCHAR(80) NOT NULL,
    label VARCHAR(255) NOT NULL,
    type ENUM('name','mobile','email','description','text','textarea','number','select','radio','checkbox') NOT NULL DEFAULT 'text',
    required TINYINT(1) NOT NULL DEFAULT 0,
    options TEXT NULL,
    placeholder VARCHAR(255) NULL,
    default_value VARCHAR(255) NULL,
    validation VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pff_form_key (form_id, field_key),
    KEY idx_pff_form (form_id),
    CONSTRAINT fk_pff_form FOREIGN KEY (form_id) REFERENCES payment_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

/* ============================================================
 * 3) Selectable amount options
 * ========================================================== */
$statements[] = "
CREATE TABLE IF NOT EXISTS payment_form_amounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    label VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pfa_form (form_id),
    CONSTRAINT fk_pfa_form FOREIGN KEY (form_id) REFERENCES payment_forms(id) ON DELETE CASCADE,
    CONSTRAINT chk_pfa_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

/* ============================================================
 * 4) Payment transactions — the authoritative record.
 *    Integer monetary amounts in IRR (ریال).
 *    Unique authority = idempotency guard against duplicate callbacks.
 * ========================================================== */
$statements[] = "
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    customer_id INT NULL,
    transaction_number VARCHAR(40) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'IRR',
    status ENUM('created','pending','redirected','callback_received','verifying','paid','failed','cancelled','expired') NOT NULL DEFAULT 'created',
    gateway VARCHAR(40) NOT NULL DEFAULT 'zarinpal',
    authority VARCHAR(255) NULL,
    ref_id VARCHAR(255) NULL,
    gateway_response TEXT NULL,
    failure_reason VARCHAR(255) NULL,
    customer_name VARCHAR(255) NULL,
    customer_mobile VARCHAR(40) NULL,
    customer_email VARCHAR(255) NULL,
    submitted_data JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    callback_at DATETIME NULL,
    verified_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pt_number (transaction_number),
    UNIQUE KEY uq_pt_authority (authority),
    KEY idx_pt_form (form_id),
    KEY idx_pt_status (status),
    KEY idx_pt_created (created_at),
    KEY idx_pt_mobile (customer_mobile),
    KEY idx_pt_ref (ref_id),
    KEY idx_pt_customer (customer_id),
    CONSTRAINT fk_pt_form FOREIGN KEY (form_id) REFERENCES payment_forms(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pt_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT chk_pt_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

/* ============================================================
 * 5) Notification log — payment success and SMS delivery are
 *    independent states. Unique (transaction, type, provider)
 *    prevents duplicate notifications.
 * ========================================================== */
$statements[] = "
CREATE TABLE IF NOT EXISTS payment_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    type ENUM('customer','admin') NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'farazsms',
    status ENUM('pending','sent','failed','disabled') NOT NULL DEFAULT 'pending',
    provider_request_id VARCHAR(255) NULL,
    message TEXT NULL,
    error_message VARCHAR(255) NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    UNIQUE KEY uq_pn_dedup (transaction_id, type, provider),
    KEY idx_pn_tx (transaction_id),
    KEY idx_pn_status (status),
    CONSTRAINT fk_pn_tx FOREIGN KEY (transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

/* ============================================================
 * 6) Payment settings (single row, id=1) — ZarinPal + Faraz SMS.
 *    Credentials are stored server-side only and masked in UI.
 * ========================================================== */
$statements[] = "
CREATE TABLE IF NOT EXISTS payment_settings (
    id INT PRIMARY KEY,
    zarinpal_merchant_id VARCHAR(64) NULL,
    zarinpal_sandbox TINYINT(1) NOT NULL DEFAULT 0,
    zarinpal_gateway_enabled TINYINT(1) NOT NULL DEFAULT 1,
    zarinpal_callback_url VARCHAR(500) NULL,
    zarinpal_default_description VARCHAR(255) NULL,
    sms_api_key VARCHAR(255) NULL,
    sms_sender_line VARCHAR(40) NULL,
    sms_customer_enabled TINYINT(1) NOT NULL DEFAULT 0,
    sms_admin_enabled TINYINT(1) NOT NULL DEFAULT 0,
    sms_success_pattern_code VARCHAR(80) NULL,
    sms_admin_pattern_code VARCHAR(80) NULL,
    sms_admin_mobile VARCHAR(40) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

/* ============================================================
 * 7) Reuse the existing atomic sequence table for transaction numbers.
 * ========================================================== */
$statements[] = "
INSERT IGNORE INTO invoice_sequences (seq_key, current_value) VALUES ('payment_transaction', 0)
";

$errors = [];
foreach ($statements as $sql) {
    if (!$mysqli->query($sql)) {
        $errors[] = $mysqli->error;
    }
}

if ($errors !== []) {
    echo "Payment migration FAILED:\n";
    foreach ($errors as $err) {
        echo " - " . $err . "\n";
    }
    exit(1);
}

echo "Payment migration applied successfully.\n";

/* --- verification --- */
$tables = [
    'payment_forms',
    'payment_form_fields',
    'payment_form_amounts',
    'payment_transactions',
    'payment_notifications',
    'payment_settings',
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
    echo sprintf(" - %-28s %s\n", $t, ($row && (int) $row['c'] === 1) ? 'OK' : 'MISSING');
}

$stmt = $mysqli->prepare("SELECT current_value FROM invoice_sequences WHERE seq_key = 'payment_transaction'");
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();
echo ' - invoice_sequences payment_transaction seed: ' . (($row !== null) ? 'OK' : 'MISSING') . "\n";
$mysqli->close();