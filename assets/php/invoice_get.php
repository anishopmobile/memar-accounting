<?php
/**
 * invoice_get.php — جزئیات یک فاکتور + اقلام (GET بدون CSRF)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_common.php';

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '' || !ctype_digit($id)) {
    respond_error('شناسه فاکتور نامعتبر است.');
}

$invoice = getInvoice($mysqli, (int) $id);
if (!$invoice) {
    respond_error('فاکتور یافت نشد.', 404);
}
respond_json(true, '', $invoice);
