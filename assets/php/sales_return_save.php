<?php
/**
 * sales_return_save.php — ایجاد / ویرایش سند برگشت فروش (POST + CSRF)
 *
 *   return_id خالی  → createSalesReturn()
 *   return_id عددی → editSalesReturn()
 *
 * همه محاسبات مالی/موجودی فقط در Backend انجام می‌شود.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_return_common.php';

require_csrf_or_fail();

$returnId = trim((string) ($_POST['return_id'] ?? ''));

$items = $_POST['items'] ?? null;
if (is_string($items)) {
    $items = json_decode($items, true);
}
if (!is_array($items)) {
    $items = [];
}

$in = [
    'invoice_id'  => trim((string) ($_POST['invoice_id'] ?? '')),
    'return_date' => trim((string) ($_POST['return_date'] ?? '')),
    'note'        => trim((string) ($_POST['note'] ?? '')),
    'items'       => $items,
];

if ($returnId === '') {
    $result = createSalesReturn($mysqli, $in);
} else {
    if (!ctype_digit($returnId) || (int) $returnId <= 0) {
        respond_error('شناسه سند برگشت نامعتبر است.');
    }
    $result = editSalesReturn($mysqli, (int) $returnId, $in);
}

if ($result['ok']) {
    $data = [
        'return_id' => $result['return_id'] ?? 0,
        'return_number' => $result['return_number'] ?? '',
    ];
    respond_json(true, $result['message'], $data);
}

respond_error($result['message'], 422);