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
    // Schema work runs once per revision, not for every invoice/image request.
    $schemaRevision='ledger_schema_v8_20261022';
    $ready=$db->prepare('SELECT 1 FROM accounting_migrations WHERE migration_key=? LIMIT 1');
    $ready->bind_param('s',$schemaRevision);$ready->execute();
    $exists=$ready->get_result()->fetch_assoc();$ready->close();
    if($exists)return;
    $lock=$db->query("SELECT GET_LOCK('memar_ledger_schema',30) AS acquired");
    if(!$lock || (int)($lock->fetch_assoc()['acquired']??0)!==1)
        throw new RuntimeException('Accounting migration lock unavailable');
    try {
        // The second check prevents concurrent requests from applying the same DDL.
        $ready=$db->prepare('SELECT 1 FROM accounting_migrations WHERE migration_key=? LIMIT 1');
        $ready->bind_param('s',$schemaRevision);$ready->execute();
        $exists=$ready->get_result()->fetch_assoc();$ready->close();
        if($exists)return;
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
    $db->query("CREATE TABLE IF NOT EXISTS account_payment_batches (
        token VARCHAR(64) PRIMARY KEY,
        request_hash CHAR(64) NOT NULL,
        party_kind ENUM('customer','supplier') NOT NULL,
        party_id INT NOT NULL,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
        source_proforma_id INT NULL,
        batch_token VARCHAR(64) NULL,
        cash_account_id INT NULL,
        reversed_payment_id BIGINT UNSIGNED NULL,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ap_token(client_token),
        KEY idx_ap_account(cash_account_id),
        KEY idx_ap_proforma(source_proforma_id),
        KEY idx_ap_batch(batch_token),
        CONSTRAINT fk_ap_batch FOREIGN KEY(batch_token) REFERENCES account_payment_batches(token) ON DELETE RESTRICT,
        CONSTRAINT fk_ap_proforma FOREIGN KEY(source_proforma_id) REFERENCES invoices(id) ON DELETE RESTRICT,
        CONSTRAINT fk_ap_account FOREIGN KEY(cash_account_id) REFERENCES accounting_cash_accounts(id) ON DELETE RESTRICT,
        UNIQUE KEY uq_ap_reversal(reversed_payment_id),
        KEY idx_ap_party(party_kind,party_id,payment_date),
        CONSTRAINT chk_ap_amount CHECK (amount > 0),
        CONSTRAINT fk_ap_reversal FOREIGN KEY (reversed_payment_id) REFERENCES account_payments(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $requestHashCol=$db->query("SHOW COLUMNS FROM account_payments LIKE 'request_hash'");
    if ($requestHashCol && $requestHashCol->num_rows===0)
        $db->query("ALTER TABLE account_payments ADD COLUMN request_hash CHAR(64) NULL");
    $actorColumn=$db->query("SHOW COLUMNS FROM account_payments LIKE 'created_by'");
    if ($actorColumn && $actorColumn->num_rows===0)
        $db->query("ALTER TABLE account_payments ADD COLUMN created_by INT NULL");
    $actorBatchColumn=$db->query("SHOW COLUMNS FROM account_payment_batches LIKE 'created_by'");
    if ($actorBatchColumn && $actorBatchColumn->num_rows===0)
        $db->query("ALTER TABLE account_payment_batches ADD COLUMN created_by INT NULL");
    $batchColumn=$db->query("SHOW COLUMNS FROM account_payments LIKE 'batch_token'");
    if ($batchColumn && $batchColumn->num_rows===0) {
        $db->query("ALTER TABLE account_payments ADD COLUMN batch_token VARCHAR(64) NULL,
          ADD KEY idx_ap_batch(batch_token),
          ADD CONSTRAINT fk_ap_batch FOREIGN KEY(batch_token) REFERENCES account_payment_batches(token) ON DELETE RESTRICT");
    }
    $sourceColumn=$db->query("SHOW COLUMNS FROM account_payments LIKE 'source_proforma_id'");
    if ($sourceColumn && $sourceColumn->num_rows===0) {
        $db->query("ALTER TABLE account_payments ADD COLUMN source_proforma_id INT NULL,
            ADD KEY idx_ap_proforma(source_proforma_id),
            ADD CONSTRAINT fk_ap_proforma FOREIGN KEY(source_proforma_id) REFERENCES invoices(id) ON DELETE RESTRICT");
    }
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
        client_token VARCHAR(64) NULL,
        reversed_at DATETIME NULL,
        KEY idx_apa_pair(payment_id,invoice_id),
        UNIQUE KEY uq_apa_token(client_token),
        KEY idx_apa_invoice(invoice_id),
        CONSTRAINT fk_apa_payment FOREIGN KEY(payment_id) REFERENCES account_payments(id) ON DELETE RESTRICT,
        CONSTRAINT fk_apa_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
        CONSTRAINT chk_apa_amount CHECK (amount > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // The first release used a unique pair. Removing it allows new immutable
    // allocation rows without rewriting previously reversed historical rows.
    $uniquePair=$db->query("SHOW INDEX FROM account_payment_allocations WHERE Key_name='uq_apa_pair'");
    if ($uniquePair && $uniquePair->num_rows>0) {
        $db->query("ALTER TABLE account_payment_allocations DROP INDEX uq_apa_pair, ADD KEY idx_apa_pair(payment_id,invoice_id)");
    }
    $allocationToken=$db->query("SHOW COLUMNS FROM account_payment_allocations LIKE 'client_token'");
    if ($allocationToken && $allocationToken->num_rows===0)
        $db->query("ALTER TABLE account_payment_allocations ADD COLUMN client_token VARCHAR(64) NULL, ADD UNIQUE KEY uq_apa_token(client_token)");
    $allocationCol=$db->query("SHOW COLUMNS FROM account_payment_allocations LIKE 'reversed_at'");
    if ($allocationCol && $allocationCol->num_rows===0) {
        $db->query("ALTER TABLE account_payment_allocations ADD COLUMN reversed_at DATETIME NULL");
    }
    $db->query("CREATE TABLE IF NOT EXISTS account_return_allocations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        return_id INT NOT NULL,
        invoice_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        client_token VARCHAR(64) NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ara_token(client_token),
        KEY idx_ara_pair(return_id,invoice_id),
        KEY idx_ara_invoice(invoice_id),
        CONSTRAINT fk_ara_return FOREIGN KEY(return_id) REFERENCES sales_returns(id) ON DELETE RESTRICT,
        CONSTRAINT fk_ara_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
        CONSTRAINT chk_ara_amount CHECK(amount>0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $returnToken=$db->query("SHOW COLUMNS FROM account_return_allocations LIKE 'client_token'");
    if ($returnToken && $returnToken->num_rows===0)
        $db->query("ALTER TABLE account_return_allocations ADD COLUMN client_token VARCHAR(64) NULL, ADD UNIQUE KEY uq_ara_token(client_token)");
    $returnPair=$db->query("SHOW INDEX FROM account_return_allocations WHERE Key_name='uq_ara_pair'");
    if ($returnPair && $returnPair->num_rows>0)
        $db->query("ALTER TABLE account_return_allocations DROP INDEX uq_ara_pair, ADD KEY idx_ara_pair(return_id,invoice_id)");
    $returnActorCol=$db->query("SHOW COLUMNS FROM account_return_allocations LIKE 'created_by'");
    if ($returnActorCol && $returnActorCol->num_rows===0)
        $db->query("ALTER TABLE account_return_allocations ADD COLUMN created_by INT NULL");
    // Refunds consume documented credit sources. Otherwise refunded credit
    // could still be allocated to a later invoice and counted twice.
    $db->query("CREATE TABLE IF NOT EXISTS account_refund_sources (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        refund_payment_id BIGINT UNSIGNED NOT NULL,
        source_kind ENUM('payment','return','opening') NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_refund_source(refund_payment_id,source_kind,source_id),
        KEY idx_refund_source(source_kind,source_id),
        CONSTRAINT fk_refund_payment FOREIGN KEY(refund_payment_id) REFERENCES account_payments(id) ON DELETE RESTRICT,
        CONSTRAINT chk_refund_source_amount CHECK(amount>0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS account_opening_balances (
        party_kind ENUM('customer','supplier') NOT NULL,
        party_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(party_kind,party_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $openingActorCol=$db->query("SHOW COLUMNS FROM account_opening_balances LIKE 'created_by'");
    if ($openingActorCol && $openingActorCol->num_rows===0)
        $db->query("ALTER TABLE account_opening_balances ADD COLUMN created_by INT NULL");
    $db->query("CREATE TABLE IF NOT EXISTS account_invoice_revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NULL,
        invoice_number VARCHAR(40) NOT NULL,
        previous_total DECIMAL(14,2) NOT NULL,
        new_total DECIMAL(14,2) NOT NULL,
        previous_status VARCHAR(20) NOT NULL,
        new_status VARCHAR(20) NOT NULL,
        performed_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_air_invoice(invoice_id),
        KEY idx_air_number(invoice_number),
        CONSTRAINT fk_air_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS account_legacy_reconciliations (
        invoice_id INT PRIMARY KEY,
        historical_paid_amount DECIMAL(14,2) NOT NULL,
        old_payment_status ENUM('paid','unpaid','partial') NOT NULL,
        reason TEXT NOT NULL,
        performed_by INT NULL,
        performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_alr_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
        CONSTRAINT chk_alr_paid CHECK(historical_paid_amount>=0)
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
    $finish=$db->prepare('INSERT INTO accounting_migrations(migration_key) VALUES(?)');
    $finish->bind_param('s',$schemaRevision);$finish->execute();$finish->close();
    } finally {
        $db->query("SELECT RELEASE_LOCK('memar_ledger_schema')");
    }
}
