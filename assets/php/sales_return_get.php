<?php
/**
 * sales_return_get.php — جزئیات یک سند برگشت فروش (GET بدون CSRF)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_return_common.php';

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '' || !ctype_digit($id) || (int) $id <= 0) {
    respond_error('شناسه سند برگشت نامعتبر است.');
}

$ret = getSalesReturn($mysqli, (int) $id);
if (!$ret) {
    respond_error('سند برگشت فروش یافت نشد.', 404);
}
respond_json(true, '', $ret);