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
    $initialPayment = parseMoneyInput(trim((string)($_POST['initial_payment'] ?? '0')) ?: '0');
    if (bccomp($initialPayment, '0', 2) < 0) throw new InvalidArgumentException('مبلغ پرداخت نمی‌تواند منفی باشد.');
    $initialPayments = [];
    if (isset($_POST['initial_payments']) && (string)$_POST['initial_payments'] !== '') {
        $rawPayments=json_decode((string)$_POST['initial_payments'],true,8,JSON_THROW_ON_ERROR);
        if (!is_array($rawPayments) || count($rawPayments)>20)
            throw new InvalidArgumentException('حداکثر ۲۰ پرداخت اولیه مجاز است.');
        foreach($rawPayments as $pay) {
            if(!is_array($pay))throw new InvalidArgumentException('ردیف پرداخت نامعتبر است.');
            $amount=parseMoneyInput((string)($pay['amount']??''));
            if(bccomp($amount,'0',2)<=0)
                throw new InvalidArgumentException('مبلغ پرداخت باید بزرگ‌تر از صفر باشد.');
            $method=trim((string)($pay['method']??''));
            if(!in_array($method,['cash','pos','bank_transfer'],true))
                throw new InvalidArgumentException('روش پرداخت اولیه نامعتبر است.');
            $initialPayments[]=[
                'amount'=>$amount,'method'=>$method,
                'date'=>trim((string)($pay['date']??'')),
                'reference'=>trim((string)($pay['reference']??'')),
                'account'=>trim((string)($pay['account']??'')),
                'note'=>trim((string)($pay['note']??'')),
            ];
        }
    }
    if ($invoiceId !== '' && (bccomp($initialPayment, '0', 2) !== 0 || $initialPayments)) {
        throw new InvalidArgumentException('برای دریافت یا پرداخت بعدی از ثبت تراکنش مستقل استفاده کنید.');
    }
} catch (InvalidArgumentException|JsonException $e) {
    respond_error($e->getMessage(), 422);
}


$in = [
    'type'           => $type,
    'party_id'       => (int) ($_POST[$partyField] ?? 0),
    'payment_type'   => trim((string) ($_POST['payment_type'] ?? 'pos')),
    'payment_status' => 'unpaid', // Derived from allocations by accounting/ledger.php.
    'invoice_date'   => trim((string) ($_POST['invoice_date'] ?? '')),
    'discount'       => $documentDiscount,
    'initial_payment' => $initialPayment,
    'initial_payments' => $initialPayments,
    'initial_payment_date' => trim((string)($_POST['initial_payment_date']??'')),
    'initial_payment_account' => trim((string)($_POST['initial_payment_account']??'')),
    'initial_payment_reference' => trim((string)($_POST['initial_payment_reference']??'')),
    'initial_payment_note' => trim((string)($_POST['initial_payment_note']??'')),
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
