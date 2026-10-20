<?php
/**
 * invoice_convert.php — تبدیل پیش‌فاکتور → فاکتور قطعی (POST + CSRF, یک‌بار)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_common.php';

require_csrf_or_fail();

$id = trim((string) ($_POST['id'] ?? ''));
if ($id === '' || !ctype_digit($id)) {
    respond_error('شناسه فاکتور نامعتبر است.');
}

$result = convertInvoice($mysqli, (int) $id);
if ($result['ok']) {
    $invoice = getInvoice($mysqli, (int) $id);
    respond_json(true, $result['message'], ['invoice' => $invoice]);
}
respond_error($result['message'], 422);
