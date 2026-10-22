<?php
/**
 * Accounting ledger migration. Historical paid flags are preserved as opening
 * settlements; old partial invoices remain unreconciled (NULL), never guessed.
 */
declare(strict_types=1);
function migrate_accounting_ledger(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS accounting_migrations (
        migration_key VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS accounting_cash_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        account_type ENUM('cash','pos','bank_transfer') NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ac_name(name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("INSERT IGNORE INTO accounting_cash_accounts(name,account_type) VALUES
        ('صندوق فروشگاه','cash'),
        ('کارت‌خوان فروشگاه','pos'),
        ('حساب بانکی فروشگاه','bank_transfer')");
    $db->query("CREATE TABLE IF NOT EXISTS account_payments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        party_kind ENUM('customer','supplier') NOT NULL,
        party_id INT NOT NULL,
        direction ENUM('in','out') NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        method ENUM('cash','pos','bank_transfer') NOT NULL,
        payment_date DATE NOT NULL,
        reference_no VARCHAR(120) NULL,
        note TEXT NULL,
        client_token VARCHAR(64) NOT NULL,
        request_hash CHAR(64) NULL,
        cash_account_id INT NULL,
        reversed_payment_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ap_token(client_token),
        KEY idx_ap_account(cash_account_id),
        CONSTRAINT fk_ap_account FOREIGN KEY(cash_account_id) REFERENCES accounting_cash_accounts(id) ON DELETE RESTRICT,
        UNIQUE KEY uq_ap_reversal(reversed_payment_id),
        KEY idx_ap_party(party_kind,party_id,payment_date),
        CONSTRAINT chk_ap_amount CHECK (amount > 0),
        CONSTRAINT fk_ap_reversal FOREIGN KEY (reversed_payment_id) REFERENCES account_payments(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $requestHashCol=$db->query("SHOW COLUMNS FROM account_payments LIKE 'request_hash'");
    if ($requestHashCol && $requestHashCol->num_rows===0)
        $db->query("ALTER TABLE account_payments ADD COLUMN request_hash CHAR(64) NULL");
    $cashColumn=$db->query("SHOW COLUMNS FROM account_payments LIKE 'cash_account_id'");
    if ($cashColumn && $cashColumn->num_rows===0) {
        $db->query("ALTER TABLE account_payments ADD COLUMN cash_account_id INT NULL,
            ADD CONSTRAINT fk_ap_account FOREIGN KEY(cash_account_id) REFERENCES accounting_cash_accounts(id) ON DELETE RESTRICT");
    }
    $db->query("CREATE TABLE IF NOT EXISTS account_payment_allocations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payment_id BIGINT UNSIGNED NOT NULL,
        invoice_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        reversed_at DATETIME NULL,
        UNIQUE KEY uq_apa_pair(payment_id,invoice_id),
        KEY idx_apa_invoice(invoice_id),
        CONSTRAINT fk_apa_payment FOREIGN KEY(payment_id) REFERENCES account_payments(id) ON DELETE RESTRICT,
        CONSTRAINT fk_apa_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
        CONSTRAINT chk_apa_amount CHECK (amount > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $allocationCol=$db->query("SHOW COLUMNS FROM account_payment_allocations LIKE 'reversed_at'");
    if ($allocationCol && $allocationCol->num_rows===0) {
        $db->query("ALTER TABLE account_payment_allocations ADD COLUMN reversed_at DATETIME NULL");
    }
    $db->query("CREATE TABLE IF NOT EXISTS account_return_allocations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        invoice_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ara_pair(return_id,invoice_id),
        KEY idx_ara_invoice(invoice_id),
        CONSTRAINT fk_ara_return FOREIGN KEY(return_id) REFERENCES sales_returns(id) ON DELETE RESTRICT,
        CONSTRAINT fk_ara_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
        CONSTRAINT chk_ara_amount CHECK(amount>0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS account_opening_balances (
        party_kind ENUM('customer','supplier') NOT NULL,
        party_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(party_kind,party_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $column = $db->query("SHOW COLUMNS FROM invoices LIKE 'legacy_paid_amount'");
    if ($column && $column->num_rows === 0) {
        $db->query("ALTER TABLE invoices ADD COLUMN legacy_paid_amount DECIMAL(14,2) NULL DEFAULT NULL");
    }
    // Migration is deliberately one-time: NEVER reinterpret a later status edit.
    $done = $db->query("SELECT migration_key FROM accounting_migrations WHERE migration_key='ledger_v1'");
    if ($done && $done->num_rows === 0) {
        $db->begin_transaction();
        try {
            $db->query("UPDATE invoices SET legacy_paid_amount =
                CASE WHEN payment_status='paid' AND type IN ('sales_invoice','purchase_invoice')
                     THEN payable_amount
                     WHEN payment_status='partial' AND type IN ('sales_invoice','purchase_invoice')
                     THEN NULL ELSE 0 END
                WHERE legacy_paid_amount IS NULL");
            $db->query("INSERT INTO accounting_migrations(migration_key) VALUES('ledger_v1')");
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
