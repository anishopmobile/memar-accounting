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
require_once __DIR__ . '/currency.php';

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

/* Normalize prices and discounts from selected unit to database TOMAN.
 * Quantities, unit conversion factors, and tax percentage are NOT money. */
try {
    foreach ($items as &$item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('ردیف فاکتور نامعتبر است.');
        }
        foreach (['unit_price', 'discount'] as $key) {
            $raw = trim((string) ($item[$key] ?? '0'));
            $item[$key] = parseMoneyInput($raw === '' ? '0' : $raw);
        }
    }
    unset($item);
    $documentDiscount = trim((string) ($_POST['discount'] ?? '0'));
    $documentDiscount = parseMoneyInput($documentDiscount === '' ? '0' : $documentDiscount);
} catch (InvalidArgumentException $e) {
    respond_error($e->getMessage(), 422);
}


$in = [
    'type'           => $type,
    'party_id'       => (int) ($_POST[$partyField] ?? 0),
    'payment_type'   => trim((string) ($_POST['payment_type'] ?? 'pos')),
    'payment_status' => trim((string) ($_POST['payment_status'] ?? 'unpaid')),
    'invoice_date'   => trim((string) ($_POST['invoice_date'] ?? '')),
    'discount'       => $documentDiscount,
    'note'           => trim((string) ($_POST['note'] ?? '')),
    'client_token'   => trim((string) ($_POST['client_token'] ?? '')),
    'tax_rate'       => isset($_POST['tax_rate']) ? trim((string) $_POST['tax_rate']) : null,
    'items'          => $items,
];

if ($invoiceId === '') {
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $in['client_token'])) {
        respond_error('شناسه درخواست نامعتبر است؛ صفحه را تازه کنید.', 422);
    }
    $result = createInvoice($mysqli, $in);
} else {
    if (!ctype_digit($invoiceId)) {
        respond_error('شناسه فاکتور نامعتبر است.');
    }
    $result = editInvoice($mysqli, (int) $invoiceId, $in);
}

if ($result['ok']) {
    $data = ['invoice_id' => $result['invoice_id'] ?? 0];
    // Suppress the manual payment dialog only when an automatic settlement
    // actually exists for this newly created invoice. On idempotent retries,
    // query the authoritative ledger rather than trusting a client-side flag.
    if($invoiceId==='' && !empty($result['invoice_id'])) {
        if(array_key_exists('auto_settled',$result)) {
            $data['auto_settled']=(bool)$result['auto_settled'];
        }else{
            require_once __DIR__ . '/accounting_auto_settlement.php';
            $data['auto_settled']=acct_auto_invoice_has_receipt($mysqli,(int)$result['invoice_id']);
        }
    }
    // Backward compatibility: older cached invoice.js versions open the
    // payment modal whenever the response includes data.invoice, without
    // checking auto_settled. Omit that popup-only payload when the server has
    // already settled this sale. The invoice remains accessible through
    // invoice_get.php and the list's accounting-details action.
    if (empty($data['auto_settled']) && isset($result['invoice_id']) && $result['invoice_id']) {
        $full = getInvoice($mysqli, (int) $result['invoice_id']);
        if ($full) {
            $data['invoice'] = $full;
        }
    }
    respond_json(true, $result['message'], $data);
}

respond_error($result['message'], 422);
