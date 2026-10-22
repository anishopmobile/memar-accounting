<?php
/**
 * Accounting settlement schema. All values are persisted in base TOMAN.
 * Payment gateway transactions remain a separate subsystem.
 */
declare(strict_types=1);
function ledger_migrate(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS accounting_receipts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      party_kind ENUM('customer','supplier') NOT NULL,
      party_id INT NOT NULL,
      direction ENUM('in','out') NOT NULL,
      amount DECIMAL(14,2) NOT NULL,
      method ENUM('cash','pos','bank_transfer') NOT NULL,
      account_label VARCHAR(120) NULL,
      payment_date DATE NOT NULL,
      reference_number VARCHAR(120) NULL,
      note TEXT NULL,
      state ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
      reversal_of BIGINT UNSIGNED NULL,
      source_proforma_id INT NULL,
      request_key VARCHAR(80) NULL UNIQUE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_ar_party (party_kind,party_id,payment_date),
      UNIQUE KEY uq_ar_reversal (reversal_of),
      KEY idx_ar_proforma (source_proforma_id),
      CONSTRAINT chk_ar_amount CHECK (amount > 0),
      CONSTRAINT fk_ar_reversal FOREIGN KEY (reversal_of) REFERENCES accounting_receipts(id) ON DELETE RESTRICT,
      CONSTRAINT fk_ar_proforma FOREIGN KEY (source_proforma_id) REFERENCES invoices(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS accounting_allocations (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      receipt_id BIGINT UNSIGNED NOT NULL,
      invoice_id INT NOT NULL,
      amount DECIMAL(14,2) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_aa_receipt_invoice (receipt_id,invoice_id),
      KEY idx_aa_invoice (invoice_id),
      CONSTRAINT fk_aa_receipt FOREIGN KEY (receipt_id) REFERENCES accounting_receipts(id) ON DELETE RESTRICT,
      CONSTRAINT fk_aa_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
      CONSTRAINT chk_aa_amount CHECK (amount > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS accounting_openings (
      party_kind ENUM('customer','supplier') NOT NULL,
      party_id INT NOT NULL,
      opening_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      note VARCHAR(255) NULL,
      recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (party_kind,party_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS accounting_legacy_settlements (
      invoice_id INT PRIMARY KEY,
      amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      needs_review TINYINT(1) NOT NULL DEFAULT 0,
      source_status VARCHAR(16) NOT NULL,
      resolved_at DATETIME NULL,
      CONSTRAINT fk_als_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS accounting_schema_versions (
      version_key VARCHAR(80) PRIMARY KEY,
      applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Run legacy backfill exactly once, under a DB lock to prevent concurrent bootstraps.
    $lock = $db->query("SELECT GET_LOCK('memar_accounting_legacy_v1', 10) AS ok");
    $row = $lock ? $lock->fetch_assoc() : null;
    if ((int)($row['ok'] ?? 0) !== 1) { throw new RuntimeException('Account ledger migration lock unavailable'); }
    try {
        $done = $db->query("SELECT 1 FROM accounting_schema_versions WHERE version_key='legacy_settlement_v1' LIMIT 1");
        if ($done && $done->num_rows === 0) {
            $db->begin_transaction();
            try {
                $db->query("INSERT INTO accounting_legacy_settlements (invoice_id,amount,needs_review,source_status)
                  SELECT id, CASE WHEN payment_status='paid' THEN payable_amount ELSE 0 END,
                         CASE WHEN payment_status='partial' THEN 1 ELSE 0 END, payment_status
                  FROM invoices WHERE type IN ('sales_invoice','purchase_invoice') AND payment_status IN ('paid','partial')");
                $db->query("INSERT INTO accounting_schema_versions (version_key) VALUES ('legacy_settlement_v1')");
                $db->commit();
            } catch (Throwable $e) { $db->rollback(); throw $e; }
        }
    } finally { $db->query("SELECT RELEASE_LOCK('memar_accounting_legacy_v1')"); }
}
