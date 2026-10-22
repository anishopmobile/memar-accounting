<?php
/**
 * invoice_get.php — جزئیات یک فاکتور + اقلام (GET بدون CSRF)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_common.php';
require_once __DIR__ . '/accounting_ledger.php';
require_once __DIR__ . '/media_common.php';

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '' || !ctype_digit($id)) {
    respond_error('شناسه فاکتور نامعتبر است.');
}

$invoice = getInvoice($mysqli, (int) $id);
if (!$invoice) {
    respond_error('فاکتور یافت نشد.', 404);
}
$flag = str_starts_with((string)$invoice['type'], 'sales') ? 'sales_ui' : 'purchase_ui';
if ((int)(media_settings($mysqli)[$flag] ?? 0) === 1) {
    $map = media_primary_map($mysqli, array_column($invoice['items'], 'product_id'));
    foreach ($invoice['items'] as &$item) {
        $item['media_id'] = (int)($map[(int)$item['product_id']]['id'] ?? 0);
    }
    unset($item);
}
if(in_array($invoice['type'],['sales_invoice','purchase_invoice'],true)) {
    $finance=acct_invoice_outstanding($mysqli,(int)$invoice['id']);
    $invoice['paid_amount']=$finance['paid'];
    $invoice['remaining_amount']=$finance['remaining'];
    $invoice['unreconciled_payment']=$finance['unreconciled'];
}
respond_json(true, '', $invoice);
