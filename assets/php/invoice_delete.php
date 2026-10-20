<?php
/**
 * invoice_delete.php — حذف فاکتور (POST + CSRF)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_common.php';

require_csrf_or_fail();

$id = trim((string) ($_POST['id'] ?? ''));
if ($id === '' || !ctype_digit($id)) {
    respond_error('شناسه فاکتور نامعتبر است.');
}

$result = deleteInvoice($mysqli, (int) $id);
if ($result['ok']) {
    respond_json(true, $result['message'], ['invoice_id' => (int) $id]);
}
respond_error($result['message'], 422);
