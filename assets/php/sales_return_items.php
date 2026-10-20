<?php
/**
 * sales_return_items.php — بافت فاکتور + اقلام با مقدار قابل‌برگشت (GET بدون CSRF)
 * پارامترها: id (فاکتور)، exclude (شناسه سند برگشت فعلی در حالت ویرایش)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_return_common.php';

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '' || !ctype_digit($id) || (int) $id <= 0) {
    respond_error('شناسه فاکتور نامعتبر است.');
}

$exclude = trim((string) ($_GET['exclude'] ?? ''));
$excludeId = ($exclude !== '' && ctype_digit($exclude) && (int) $exclude > 0) ? (int) $exclude : null;

$ctx = sr_invoice_context($mysqli, (int) $id, $excludeId);
if (!$ctx['ok']) {
    respond_error($ctx['message'], 404);
}

$items = array_values($ctx['items']);
respond_json(true, '', ['invoice' => $ctx['invoice'], 'items' => $items]);