<?php
/**
 * payment_public_migration.php — Additive DB changes for the public payment
 * experience (configurable default payment form). Idempotent.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1); // never runnable over HTTP
}

require_once __DIR__ . '/../db.php';

$col = $mysqli->query("SHOW COLUMNS FROM payment_settings LIKE 'default_form_id'");
if ($col && $col->num_rows === 0) {
    $mysqli->query(
        "ALTER TABLE payment_settings ADD COLUMN default_form_id INT NULL AFTER zarinpal_default_description"
    );
    echo "added default_form_id column\n";
} else {
    echo "default_form_id column already present\n";
}
$mysqli->close();