<?php
/**
 * Independent accounts-receivable/payable ledger. Monetary storage: TOMAN DECIMAL(14,2).
 * This schema is intentionally separate from online gateway payment_transactions (IRR).
 */
declare(strict_types=1);
function ledger_migrate(mysqli $db): void {
    $sql = [
        "CREATE TABLE IF NOT EXISTS ledger_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            party_kind ENUM('customer','supplier') NOT NULL,
            party_id INT NOT NULL,
            direction ENUM('receipt','disbursement') NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            method ENUM('cash','pos','bank_transfer') NOT NULL,
            paid_on DATE NOT NULL,
            reference_no VARCHAR(120) NULL,
            note TEXT NULL,
            request_token VARCHAR(64) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            voided_at DATETIME NULL,
            void_reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ledger_request (request_token),
            KEY idx_ledger_party (party_kind,party_id,paid_on),
            CHECK (amount > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ledger_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payment_id BIGINT UNSIGNED NOT NULL,
            invoice_id INT NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ledger_payment_invoice (payment_id,invoice_id),
            KEY idx_ledger_invoice (invoice_id),
            CONSTRAINT fk_ledger_payment FOREIGN KEY (payment_id) REFERENCES ledger_payments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ledger_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
            CHECK (amount > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ledger_credit_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payment_id BIGINT UNSIGNED NOT NULL,
            invoice_id INT NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            request_token VARCHAR(64) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ledger_credit_token (request_token),
            KEY idx_ledger_credit_payment (payment_id),
            KEY idx_ledger_credit_invoice (invoice_id),
            CONSTRAINT fk_ledger_credit_payment FOREIGN KEY (payment_id) REFERENCES ledger_payments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ledger_credit_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
            CHECK (amount > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ledger_proforma_deposits (
            payment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            proforma_invoice_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ledger_proforma (proforma_invoice_id),
            CONSTRAINT fk_ledger_deposit_payment FOREIGN KEY (payment_id) REFERENCES ledger_payments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ledger_deposit_invoice FOREIGN KEY (proforma_invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ledger_openings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            party_kind ENUM('customer','supplier') NOT NULL,
            party_id INT NOT NULL,
            signed_amount DECIMAL(14,2) NOT NULL,
            effective_on DATE NOT NULL,
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ledger_opening (party_kind,party_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ledger_metadata (
            id TINYINT PRIMARY KEY,
            legacy_initialized TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS ledger_reconciliations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            recognized_paid DECIMAL(14,2) NOT NULL,
            explanation VARCHAR(2000) NOT NULL,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_legacy_reconciled_invoice (invoice_id),
            CONSTRAINT fk_legacy_reconcile_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
            CHECK (recognized_paid >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ledger_legacy_invoices (
            invoice_id INT NOT NULL PRIMARY KEY,
            recognized_paid DECIMAL(14,2) NOT NULL,
            classification ENUM('historical_paid','historical_unpaid','requires_reconciliation') NOT NULL,
            reconciled_at DATETIME NULL,
            CONSTRAINT fk_ledger_legacy_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
            CHECK (recognized_paid >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($sql as $statement) {
        if (!$db->query($statement)) throw new RuntimeException('Ledger migration failed: '.$db->error);
    }
}

/**
 * One-time preservation of pre-ledger invoice statuses.
 * Historical partial invoices have UNKNOWN paid amounts and MUST be reconciled.
 * Historical paid invoices are non-cash opening recognition, not receipts.
 */
function ledger_initialize_legacy(mysqli $db): void {
    static $ready = false;
    if($ready) return;
    // Check a previously initialized schema without DDL or row locks first.
    // This keeps simultaneous accounting requests out of the shared DDL/migration path.
    $already=null;
    try {
        $already=$db->query("SELECT legacy_initialized FROM ledger_metadata WHERE id=1 LIMIT 1");
    } catch(mysqli_sql_exception $e) {
        if((int)$e->getCode()!==1146)throw $e; // only missing table is a fresh-install signal
    }
    if($already && ($row=$already->fetch_assoc()) && (int)$row['legacy_initialized']===1){
        $already->free();
        $ready=true;
        return;
    }
    if($already)$already->free();
    ledger_migrate($db);
    $db->begin_transaction();
    try {
        $db->query("INSERT IGNORE INTO ledger_metadata(id,legacy_initialized) VALUES(1,0)");
        $res=$db->query("SELECT legacy_initialized FROM ledger_metadata WHERE id=1 FOR UPDATE");
        $row=$res->fetch_assoc();
        if((int)$row['legacy_initialized']===0){
            $db->query("INSERT IGNORE INTO ledger_legacy_invoices(invoice_id,recognized_paid,classification,reconciled_at)
                SELECT id,
                    CASE WHEN payment_status='paid' THEN payable_amount ELSE 0 END,
                    CASE WHEN payment_status='paid' THEN 'historical_paid'
                         WHEN payment_status='partial' THEN 'requires_reconciliation'
                         ELSE 'historical_unpaid' END,
                    CASE WHEN payment_status='partial' THEN NULL ELSE NOW() END
                FROM invoices WHERE type IN ('sales_invoice','purchase_invoice')");
            $db->query("UPDATE ledger_metadata SET legacy_initialized=1 WHERE id=1");
        }
        $db->commit();
        $ready=true;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
