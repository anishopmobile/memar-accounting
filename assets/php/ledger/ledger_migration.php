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
