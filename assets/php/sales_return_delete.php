<?php
/**
 * sales_return_delete.php — حذف سند برگشت فروش (POST + CSRF)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_return_common.php';

require_csrf_or_fail();

$id = trim((string) ($_POST['id'] ?? ''));
if ($id === '' || !ctype_digit($id) || (int) $id <= 0) {
    respond_error('شناسه سند برگشت نامعتبر است.');
}

$result = deleteSalesReturn($mysqli, (int) $id);
if ($result['ok']) {
    respond_json(true, $result['message'], ['return_id' => (int) $id]);
}
respond_error($result['message'], 422);