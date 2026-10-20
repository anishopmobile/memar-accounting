<?php
/**
 * invoice_save.php — Create / Edit فاکتور (فروش/خرید/پیش‌فاکتور)
 *
 * روش:
 *   invoice_id خالی  → createInvoice()
 *   invoice_id عددی → editInvoice()
 *
 * همه محاسبات مالی و موجودی در Backend (invoice_common.php) انجام می‌شود.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_common.php';

// تمام POSTهای تغییردهنده الزاماً CSRF دارند
require_csrf_or_fail();

$invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
$type = trim((string) ($_POST['type'] ?? ''));

// تعیین فیلد طرف معامله بر اساس نوع (fallback = customer)
$sales = str_starts_with($type, 'sales');
$partyField = $sales ? 'customer_id' : 'supplier_id';

$items = $_POST['items'] ?? null;
if (is_string($items)) {
    $items = json_decode($items, true);
}
if (!is_array($items)) {
    $items = [];
}

$in = [
    'type'           => $type,
    'party_id'       => (int) ($_POST[$partyField] ?? 0),
    'payment_type'   => trim((string) ($_POST['payment_type'] ?? 'pos')),
    'payment_status' => trim((string) ($_POST['payment_status'] ?? 'unpaid')),
    'invoice_date'   => trim((string) ($_POST['invoice_date'] ?? '')),
    'discount'       => trim((string) ($_POST['discount'] ?? '0')),
    'note'           => trim((string) ($_POST['note'] ?? '')),
    'client_token'   => trim((string) ($_POST['client_token'] ?? '')),
    'tax_rate'       => isset($_POST['tax_rate']) ? trim((string) $_POST['tax_rate']) : null,
    'items'          => $items,
];

if ($invoiceId === '') {
    $result = createInvoice($mysqli, $in);
} else {
    if (!ctype_digit($invoiceId)) {
        respond_error('شناسه فاکتور نامعتبر است.');
    }
    $result = editInvoice($mysqli, (int) $invoiceId, $in);
}

if ($result['ok']) {
    $data = ['invoice_id' => $result['invoice_id'] ?? 0];
    if (isset($result['invoice_id']) && $result['invoice_id']) {
        $full = getInvoice($mysqli, (int) $result['invoice_id']);
        if ($full) {
            $data['invoice'] = $full;
        }
    }
    respond_json(true, $result['message'], $data);
}

respond_error($result['message'], 422);
