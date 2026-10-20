<?php
/**
 * payment_rate_limit_migration.php — Adds the payment_rate_limits table
 * (additive, idempotent) used to throttle public payment submissions.
 *
 * Run from CLI (web execution is blocked by the sapi guard below).
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1); // never runnable over HTTP
}

require_once __DIR__ . '/../db.php';

$mysqli->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS payment_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope_key VARCHAR(64) NOT NULL,
    window_start DATETIME NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prl_scope (scope_key),
    KEY idx_prl_key (scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

echo "payment_rate_limits table ensured\n";
$mysqli->close();