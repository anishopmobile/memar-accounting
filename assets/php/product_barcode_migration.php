<?php
/**
 * product_barcode_migration.php — Product barcode schema migration (additive, idempotent).
 *
 * Adds an optional `barcode` column to the existing `products` table and a UNIQUE
 * index on it, without modifying existing product data.
 *
 * Barcode is stored as VARCHAR (string), never as a numeric type, so leading
 * zeros are preserved. Multiple products may have NULL (no barcode), but a
 * non-null barcode must be unique.
 *
 * Follows the project's existing migration conventions (see payment_migration.php):
 *   - CLI-only, never runnable over HTTP
 *   - checks information_schema before altering
 *   - fully idempotent / safe to re-run
 *
 * Run with the web PHP binary that has mysqli enabled, e.g.:
 *   php assets/php/product_barcode_migration.php
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1); // never runnable over HTTP
}

require_once __DIR__ . '/db.php'; // defines global $mysqli

$errors = [];

/* ------------------------------------------------------------
 * 1) Add `barcode` column if it does not exist
 * ---------------------------------------------------------- */
$colStmt = $mysqli->prepare(
    "SELECT COUNT(*) AS c FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'barcode'"
);
$colStmt->execute();
$colRes = $colStmt->get_result();
$colRow = $colRes ? $colRes->fetch_assoc() : null;
$colStmt->close();

if ($colRow && (int) $colRow['c'] === 1) {
    echo " - products.barcode column: already exists (OK)\n";
} else {
    if ($mysqli->query('ALTER TABLE products ADD COLUMN barcode VARCHAR(100) NULL AFTER code')) {
        echo " - products.barcode column: created\n";
    } else {
        $errors[] = 'ALTER TABLE products ADD COLUMN barcode: ' . $mysqli->error;
    }
}

/* ------------------------------------------------------------
 * 2) Add UNIQUE index on barcode if it does not exist
 * ---------------------------------------------------------- */
$idxStmt = $mysqli->prepare(
    "SELECT COUNT(*) AS c FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'products' AND index_name = 'uq_products_barcode'"
);
$idxStmt->execute();
$idxRes = $idxStmt->get_result();
$idxRow = $idxRes ? $idxRes->fetch_assoc() : null;
$idxStmt->close();

if ($idxRow && (int) $idxRow['c'] >= 1) {
    echo " - products UNIQUE KEY uq_products_barcode: already exists (OK)\n";
} else {
    if ($mysqli->query('ALTER TABLE products ADD UNIQUE KEY uq_products_barcode (barcode)')) {
        echo " - products UNIQUE KEY uq_products_barcode: created\n";
    } else {
        $errors[] = 'ALTER TABLE products ADD UNIQUE KEY: ' . $mysqli->error;
    }
}

if ($errors !== []) {
    echo "Product barcode migration FAILED:\n";
    foreach ($errors as $err) {
        echo " - " . $err . "\n";
    }
    exit(1);
}

echo "Product barcode migration applied successfully.\n";

/* --- verification (final state) --- */
$checks = [
    'column products.barcode' =>
        "SELECT COUNT(*) AS c FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'barcode'",
    'index uq_products_barcode' =>
        "SELECT COUNT(*) AS c FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'products' AND index_name = 'uq_products_barcode'",
];
$allOk = true;
foreach ($checks as $label => $sql) {
    $stmt = $mysqli->prepare($sql);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $ok = ($row !== null && (int) $row['c'] >= 1);
    if (!$ok) {
        $allOk = false;
    }
    echo sprintf(" - %-28s %s\n", $label, $ok ? 'OK' : 'MISSING');
}

$mysqli->close();

if (!$allOk) {
    echo "Verification FAILED.\n";
    exit(1);
}

echo "Verification passed.\n";