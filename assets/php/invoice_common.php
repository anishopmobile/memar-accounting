<?php

/**
 * invoice_common.php — منطق مشترک ماژول فاکتور فروش/خرید
 *
 * تمام محاسبات مالی با BCMath (Decimal-safe) انجام می‌شود.
 * تمام تغییرات موجودی فقط از طریق applyStockChange و
 * تمام ثبت گردش فقط از طریق createStockMovement انجام می‌شود.
 *
 * بسته به ترتیب بارگذاری endpoint:
 *   boot.php → db.php → invoice_common.php
 * متغیر سراسری $mysqli فرض می‌شود.
 */

require_once __DIR__ . '/jdf.php';
require_once __DIR__ . '/product_common.php';

/* ============================================================
 * ۱) ابزار صحیح محاسبات پولی (BCMath)
 * ========================================================== */

/**
 * گرد کردن مقدار (رشته/عدد) به $scale رقم اعشار (half-up).
 */
function money_round($value, int $scale = 2): string
{
    $value = (string) $value;
    if ($value === '' || $value === null) {
        $value = '0';
    }
    $neg = '';
    if (str_starts_with($value, '-')) {
        $neg = '-';
        $value = substr($value, 1);
    }
    $pow = bcpow('10', (string) $scale);
    $scaled = bcmul($value, $pow, 10);
    $roundedInt = bcadd($scaled, '0.5', 0); // floor(x+0.5) → round half up
    return $neg . bcdiv($roundedInt, $pow, $scale);
}

function money_add($a, $b, int $scale = 2): string
{
    return bcadd((string) $a, (string) $b, $scale);
}
function money_sub($a, $b, int $scale = 2): string
{
    return bcsub((string) $a, (string) $b, $scale);
}
function money_mul($a, $b, int $scale = 2): string
{
    return bcmul((string) $a, (string) $b, $scale);
}
function money_div($a, $b, int $scale = 2): string
{
    if (bccomp((string) $b, '0', 6) === 0) {
        return '0';
    }
    return bcdiv((string) $a, (string) $b, $scale);
}
function money_cmp($a, $b, int $scale = 2): int
{
    return bccomp(money_round($a, $scale), money_round($b, $scale), $scale);
}
function money_abs($a): string
{
    $a = (string) $a;
    return str_starts_with($a, '-') ? substr($a, 1) : $a;
}

/* ============================================================
 * ۲) ثابت‌ها و نگاشت‌های نوع
 * ========================================================== */

define('INVOICE_TYPES', [
    'sales_invoice',
    'sales_proforma',
    'purchase_invoice',
    'purchase_proforma',
]);
define('PAYMENT_TYPES', ['cash', 'pos', 'bank_transfer']);
define('PAYMENT_STATUSES', ['paid', 'unpaid', 'partial']);
define('FINAL_INVOICE_TYPES', ['sales_invoice', 'purchase_invoice']);

function isSales(string $type): bool
{
    return str_starts_with($type, 'sales');
}

function isPurchase(string $type): bool
{
    return str_starts_with($type, 'purchase');
}

function isFinal(string $type): bool
{
    return in_array($type, FINAL_INVOICE_TYPES, true);
}

function partyFkField(string $type): string
{
    return isSales($type) ? 'customer_id' : 'supplier_id';
}

function invoiceNumberPrefix(string $type): string
{
    $map = [
        'sales_invoice'      => 'S-',
        'sales_proforma'     => 'SP-',
        'purchase_invoice'   => 'P-',
        'purchase_proforma'  => 'PP-',
    ];
    return $map[$type] ?? 'X-';
}

/**
 * تعیین نوع و جهت حرکت موجودی
 *
 * @param string $type  نوع فاکتور
 * @param string $op    create | delete | edit
 * @param string $delta تغییر تعداد (new-old) برای edit
 * @return array [type, direction]
 */
function opToMovement(string $type, string $op, string $delta = '0'): array
{
    $sales = isSales($type);
    switch ($op) {
        case 'create':
            if ($type === 'sales_return') {
                return ['sales_return', 'in'];
            }
            if ($type === 'defective_goods') {
                return ['defective', 'out'];
            }
            return $sales ? ['sale', 'out'] : ['purchase', 'in'];
        case 'delete':
            if ($type === 'sales_return') {
                return ['sales_return_cancel', 'out'];
            }
            return $sales ? ['sale_cancel', 'in'] : ['purchase_cancel', 'out'];
        case 'edit':
            if ($type === 'sales_return') {
                return ['sales_return_edit', money_cmp($delta, '0') >= 0 ? 'in' : 'out'];
            }
            if ($sales) {
                return ['sale_edit', money_cmp($delta, '0') >= 0 ? 'out' : 'in'];
            }
            return ['purchase_edit', money_cmp($delta, '0') >= 0 ? 'in' : 'out'];
        case 'return':
            /* برگشت کالای معیوب ترمیم‌شده به موجودی عادی */
            if ($type === 'defective_goods') {
                return ['defective_return', 'in'];
            }
            return ['adjustment', 'in'];
    }
    return ['adjustment', 'in'];
}

/* ============================================================
 * ۳) محاسبات مالی
 * ========================================================== */

function calculateLineTotal($quantity, $unitPrice, $lineDiscount): string
{
    // تخفیف هر ردیف = تخفیف هر واحد × تعداد  →  (قیمت واحد − تخفیف واحد) × تعداد
    return money_round(money_sub(money_mul($quantity, $unitPrice), money_mul($lineDiscount, $quantity)));
}

function calculateTax($taxable, $taxRate, bool $taxEnabled): string
{
    if (!$taxEnabled || money_cmp($taxRate, '0') <= 0) {
        return money_round('0');
    }
    return money_round(money_div(money_mul($taxable, $taxRate), '100'));
}

/**
 * محاسبه همه مبالغ فاکتور در Backend
 */
function calculateInvoiceTotals(array $items, $invoiceDiscount, $taxRate, bool $taxEnabled): array
{
    $subtotal = '0';
    foreach ($items as $item) {
        $subtotal = money_add($subtotal, $item['line_total']);
    }
    $taxable = money_sub($subtotal, $invoiceDiscount);
    $taxAmount = calculateTax($taxable, $taxRate, $taxEnabled);
    $payable = money_add($taxable, $taxAmount);

    return [
        'subtotal'       => money_round($subtotal),
        'taxable'        => money_round($taxable),
        'tax_amount'     => money_round($taxAmount),
        'payable_amount' => money_round($payable),
    ];
}

/* ============================================================
 * ۴) شماره فاکتور (اتمیک) — داخل Transaction
 * ========================================================== */

function generateInvoiceNumber(mysqli $mysqli, string $type): string
{
    $seqKey = $type;
    $stmt = $mysqli->prepare('UPDATE invoice_sequences SET current_value = current_value + 1 WHERE seq_key = ?');
    $stmt->bind_param('s', $seqKey);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare('SELECT current_value FROM invoice_sequences WHERE seq_key = ?');
    $stmt->bind_param('s', $seqKey);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $val = 0;
    if ($res && $row = $res->fetch_assoc()) {
        $val = (int) $row['current_value'];
    }
    return invoiceNumberPrefix($type) . sprintf('%06d', $val);
}

/* ============================================================
 * ۵) Lock محصولات (deterministic) — داخل Transaction
 * ========================================================== */

/**
 * Dedup + sort ASC سپس FOR UPDATE روی products.
 *
 * @return array<int,string> map pid => stock
 * @throws Exception اگر محصولی یافت نشود
 */
function lockProductsById(mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    $stocks = [];
    foreach ($ids as $id) {
        $stmt = $mysqli->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if (!$res || !($row = $res->fetch_assoc())) {
            throw new Exception('product_not_found');
        }
        $stocks[$id] = $row['stock'];
    }
    return $stocks;
}

/* ============================================================
 * ۵.۱) نوع کالاها و اعتبارسنجی اقلام فاکتور فروش
 * ========================================================== */

/**
 * دریافت نگاشت نوع کالاها (product|service) برای مجموعه‌ای از شناسه‌ها.
 * منبع حقیقت نوع کالا: ستون products.type
 *
 * @return array<int,string> map pid => 'product'|'service'
 */
function productTypeMap(mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $map = [];
    if (!$ids) {
        return $map;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $mysqli->prepare("SELECT id, type FROM products WHERE id IN ({$in})");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int) $row['id']] = (string) $row['type'];
        }
        $res->free();
    }
    return $map;
}

/**
 * اعتبارسنجی اقلام فاکتور فروش (Backend Enforcement):
 *  - کالای نوع «product» باید موجودی فعلی > 0 داشته باشد؛ در غیر این صورت خطا.
 *  - خدمت («service») بدون محدودیت موجودی قابل ثبت است.
 * برای فاکتورهای خرید هیچ محدودیتی اعمال نمی‌شود (رفتار قبلی حفظ می‌شود).
 * موجودی و نوع هر کالا از جدول products خوانده می‌شود؛ مقدار ارسالی کلاینت
 * هرگز منبع حقیقت نیست.
 *
 * @return array ['ok' => bool, 'message' => string]
 */
function validateSalesProductAvailability(mysqli $mysqli, string $type, array $items): array
{
    if (!isSales($type)) {
        return ['ok' => true, 'message' => ''];
    }
    $pids = array_values(array_unique(array_map('intval', array_column($items, 'product_id'))));
    if (!$pids) {
        return ['ok' => true, 'message' => ''];
    }

    $products = [];
    foreach ($pids as $pid) {
        $stmt = $mysqli->prepare('SELECT id, name, type, product_kind, stock FROM products WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            return ['ok' => false, 'message' => 'کالای انتخاب‌شده در سیستم یافت نشد.'];
        }
        $products[$pid] = $row;
    }

    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $p = $products[$pid] ?? null;
        if (!$p) {
            return ['ok' => false, 'message' => 'کالای انتخاب‌شده در سیستم یافت نشد.'];
        }
        if (($p['type'] ?? '') !== 'product') {
            continue; // خدمت: بدون محدودیت موجودی
        }
        if (($p['product_kind'] ?? 'standard') === 'bundle') {
            continue; // بسته: موجودی مشتق‌شده دارد و جداگانه اعتبارسنجی می‌شود
        }
        if (money_cmp((string) $p['stock'], '0') <= 0) {
            return ['ok' => false, 'message' => 'کالای «' . $p['name'] . '» موجودی فعلی ندارد و قابل ثبت در فاکتور فروش نیست.'];
        }
    }
    return ['ok' => true, 'message' => ''];
}

/* ============================================================
 * ۶) ثبت گردش موجودی
 * ========================================================== */

function createStockMovement(
    mysqli $mysqli,
    int $productId,
    ?int $invoiceId,
    string $type,
    string $direction,
    $quantity,
    $stockBefore,
    $stockAfter,
    ?array $unitSnapshot = null,
    ?int $salesReturnId = null
): void {
    $quantity = money_round($quantity);
    if (money_cmp($quantity, '0') === 0) {
        return;
    }
    $sb = money_round($stockBefore);
    $sa = money_round($stockAfter);
    $unitName = $unitSnapshot['unit_name'] ?? null;
    $txQty = $unitSnapshot['transaction_quantity'] ?? null;
    $factor = $unitSnapshot['conversion_factor'] ?? null;

    /* حرکت ناشی از برگشت فروش: به جای invoice_id به سند برگشت متصل است */
    if ($salesReturnId !== null) {
        $stmt = $mysqli->prepare(
            'INSERT INTO stock_movements
                (product_id, invoice_id, sales_return_id, type, unit_name, direction, quantity, transaction_quantity, conversion_factor, stock_before, stock_after)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'iiisssddddd',
            $productId,
            $invoiceId,
            $salesReturnId,
            $type,
            $unitName,
            $direction,
            $quantity,
            $txQty,
            $factor,
            $sb,
            $sa
        );
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $mysqli->prepare(
        'INSERT INTO stock_movements
            (product_id, invoice_id, type, unit_name, direction, quantity, transaction_quantity, conversion_factor, stock_before, stock_after)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iisssddddd',
        $productId,
        $invoiceId,
        $type,
        $unitName,
        $direction,
        $quantity,
        $txQty,
        $factor,
        $sb,
        $sa
    );
    $stmt->execute();
    $stmt->close();
}

/* ============================================================
 * ۷) اعمال تغییر موجودی (stable + check منفی + ثبت movement)
 * ========================================================== */

/**
 * اعمال یک تغییر موجودی برای یک محصول قفل‌شده.
 *
 * @param int    $productId
 * @param string $currentStock موجودی فعلی (مقدار قفل‌شده)
 * @param string $stockChange  تغییر (مثبت/منفی)
 * @param int    $invoiceId
 * @param string $type         نوع فاکتور
 * @param string $op           create|delete|edit
 * @return string موجودی جدید
 * @throws Exception اگر نتیجه منفی شود
 */
function applyStockChange(
    mysqli $mysqli,
    int $productId,
    $currentStock,
    $stockChange,
    ?int $invoiceId,
    string $type,
    string $op,
    ?array $unitSnapshot = null,
    ?int $salesReturnId = null
): string {
    if (money_cmp($stockChange, '0') === 0) {
        return money_round($currentStock);
    }
    $stockBefore = money_round($currentStock);
    $stockAfter = money_round(money_add($stockBefore, $stockChange));

    if (money_cmp($stockAfter, '0') < 0) {
        throw new Exception('negative_stock');
    }

    $stmt = $mysqli->prepare('UPDATE products SET stock = ? WHERE id = ?');
    $stmt->bind_param('di', $stockAfter, $productId);
    $stmt->execute();
    $stmt->close();

    list($movType, $direction) = opToMovement($type, $op, $stockChange);
    createStockMovement(
        $mysqli,
        $productId,
        $invoiceId,
        $movType,
        $direction,
        money_abs($stockChange),
        $stockBefore,
        $stockAfter,
        $unitSnapshot,
        $salesReturnId
    );

    return $stockAfter;
}

/* ============================================================
 * ۸) نرمال‌سازی/اعتبارسنجی اقلام
 * ========================================================== */

function normalizeItems(mysqli $mysqli, $rawItems): array
{
    if (!is_array($rawItems) || count($rawItems) === 0) {
        return ['ok' => false, 'message' => 'حداقل یک ردیف کالا باید ارسال شود.', 'items' => []];
    }

    $merged = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) {
            return ['ok' => false, 'message' => 'ساختار اقلام نامعتبر است.', 'items' => []];
        }
        $pid = trim((string) ($raw['product_id'] ?? ''));
        if ($pid === '' || !ctype_digit($pid)) {
            return ['ok' => false, 'message' => 'شناسه کالا نامعتبر است.', 'items' => []];
        }
        $pid = (int) $pid;

        $qty = trim((string) ($raw['quantity'] ?? ''));
        if ($qty === '' || !is_numeric($qty) || money_cmp($qty, '0') <= 0) {
            return ['ok' => false, 'message' => "تعداد کالای {$pid} نامعتبر است.", 'items' => []];
        }
        $unit = trim((string) ($raw['unit_price'] ?? ''));
        if ($unit === '' || !is_numeric($unit) || money_cmp($unit, '0') < 0) {
            return ['ok' => false, 'message' => "قیمت واحد کالای {$pid} نامعتبر است.", 'items' => []];
        }
        $disc = trim((string) ($raw['discount'] ?? '0'));
        if ($disc === '' || !is_numeric($disc) || money_cmp($disc, '0') < 0) {
            return ['ok' => false, 'message' => "تخفیف کالای {$pid} نامعتبر است.", 'items' => []];
        }

        $qtyR = money_round($qty);
        $unitR = money_round($unit);
        $discR = money_round($disc);

        /* ---------- وحد اندازه‌گیری انتخابی (منبع حقیقت: دیتابیس) ---------- */
        $unitIdRaw = trim((string) ($raw['unit_id'] ?? ''));
        $baseFactor = '1';
        $unitName = null;
        $unitId = null;
        if ($unitIdRaw !== '' && $unitIdRaw !== '0') {
            if (!ctype_digit($unitIdRaw)) {
                return ['ok' => false, 'message' => "واحد اندازه‌گیری کالای {$pid} نامعتبر است.", 'items' => []];
            }
            // بارگذاری واحد از دیتابیس و اطمینان از تعلق آن به این کالا
            $stmt = $mysqli->prepare(
                'SELECT pu.*, p.type AS product_type FROM product_units pu
                 LEFT JOIN products p ON p.id = pu.product_id
                 WHERE pu.id = ? AND pu.product_id = ? LIMIT 1'
            );
            $unitIdInt = (int) $unitIdRaw;
            $pidInt = $pid;
            $stmt->bind_param('ii', $unitIdInt, $pidInt);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            $row = $res ? $res->fetch_assoc() : null;
            if (!$row) {
                return ['ok' => false, 'message' => "واحد اندازه‌گیری انتخابی برای کالای {$pid} معتبر نیست.", 'items' => []];
            }
            $unitId = (int) $row['id'];
            $unitName = $row['name'];
            $baseFactor = money_round((string) $row['conversion_factor'], 4);
        }
        // بدون واحد (فرم قدیمی / خدمات): ضریب پایه ۱

        $baseQty = money_round(money_mul($qtyR, $baseFactor));

        $key = $pid . '_' . ($unitId ?? '');
        if (isset($merged[$key])) {
            $merged[$key]['quantity'] = money_add($merged[$key]['quantity'], $qtyR);
            $merged[$key]['discount'] = money_add($merged[$key]['discount'], $discR);
            $merged[$key]['base_quantity'] = money_add($merged[$key]['base_quantity'], $baseQty);
        } else {
            $merged[$key] = [
                'product_id' => $pid,
                'unit_id'    => $unitId,
                'unit_name'  => $unitName,
                'conversion_factor' => $baseFactor,
                'quantity'   => $qtyR,
                'base_quantity' => $baseQty,
                'unit_price' => $unitR,
                'discount'   => $discR,
            ];
        }
        if (money_cmp($merged[$key]['discount'], money_mul($merged[$key]['quantity'], $merged[$key]['unit_price'])) > 0) {
            return ['ok' => false, 'message' => "تخفیف کالای {$pid} از مبلغ ردیف بیشتر است.", 'items' => []];
        }
    }

    $items = [];
    foreach ($merged as $row) {
        $row['line_total'] = calculateLineTotal($row['quantity'], $row['unit_price'], $row['discount']);
        $items[] = $row;
    }
    return ['ok' => true, 'message' => '', 'items' => $items];
}

/* ============================================================
 * ۹) اعتبارسنجی کلی درخواست فاکتور
 * ========================================================== */

function validateInvoice(mysqli $mysqli, array $in): array
{
    $type = $in['type'] ?? '';
    if (!in_array($type, INVOICE_TYPES, true)) {
        return ['ok' => false, 'message' => 'نوع فاکتور نامعتبر است.'];
    }

    $partyId = (int) ($in['party_id'] ?? 0);
    if ($partyId <= 0) {
        return ['ok' => false, 'message' => isSales($type) ? 'مشتری الزامی است.' : 'تامین‌کننده الزامی است.'];
    }
    $table = isSales($type) ? 'customers' : 'suppliers';
    $stmt = $mysqli->prepare("SELECT id FROM {$table} WHERE id = ?");
    $stmt->bind_param('i', $partyId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    if (!$exists) {
        return ['ok' => false, 'message' => isSales($type) ? 'مشتری انتخاب‌شده معتبر نیست.' : 'تامین‌کننده انتخاب‌شده معتبر نیست.'];
    }

    if (!in_array($in['payment_type'] ?? '', PAYMENT_TYPES, true)) {
        return ['ok' => false, 'message' => 'نوع پرداخت نامعتبر است.'];
    }
    if (!in_array($in['payment_status'] ?? '', PAYMENT_STATUSES, true)) {
        return ['ok' => false, 'message' => 'وضعیت پرداخت نامعتبر است.'];
    }
    if (empty($in['invoice_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['invoice_date'])) {
        return ['ok' => false, 'message' => 'تاریخ فاکتور نامعتبر است.'];
    }
    if (!checkdate(
        (int) substr($in['invoice_date'], 5, 2),
        (int) substr($in['invoice_date'], 8, 2),
        (int) substr($in['invoice_date'], 0, 4)
    )) {
        return ['ok' => false, 'message' => 'تاریخ فاکتور معتبر نیست.'];
    }

    $discount = trim((string) ($in['discount'] ?? '0'));
    if ($discount === '' || !is_numeric($discount) || money_cmp($discount, '0') < 0) {
        return ['ok' => false, 'message' => 'تخفیف فاکتور نامعتبر است.'];
    }
    $in['discount'] = money_round($discount);

    return ['ok' => true, 'message' => ''];
}

/* ============================================================
 * ۱۰) تنظیمات مالیات (منبع حقیقت: tax_settings)
 * ========================================================== */

function loadTaxSettings(mysqli $mysqli): array
{
    $enabled = false;
    $rate = '0';
    $res = $mysqli->query('SELECT tax_enabled, tax_rate FROM tax_settings WHERE id = 1 LIMIT 1');
    if ($res && $row = $res->fetch_assoc()) {
        $enabled = (bool) $row['tax_enabled'];
        $rate = money_round($row['tax_rate']);
    }
    return ['tax_enabled' => $enabled, 'tax_rate' => $rate];
}

/* ============================================================
 * ۱۱) خواندن فاکتور + اقلام
 * ========================================================== */

function getInvoice(mysqli $mysqli, int $invoiceId): ?array
{
    $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $inv = $res ? $res->fetch_assoc() : null;
    if (!$inv) {
        return null;
    }

    if (isSales($inv['type'])) {
        $stmt = $mysqli->prepare('SELECT CONCAT(first_name," ",last_name) AS name, phone FROM customers WHERE id = ?');
        $stmt->bind_param('i', $inv['customer_id']);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        $party = $r ? $r->fetch_assoc() : null;
        $inv['party_name'] = $party['name'] ?? '';
        $inv['party_phone'] = $party['phone'] ?? '';
    } else {
        $stmt = $mysqli->prepare(
            'SELECT IF(company_name IS NOT NULL AND company_name <> "", company_name,
                CONCAT(COALESCE(first_name,"")," ",COALESCE(last_name,""))) AS name, phone
             FROM suppliers WHERE id = ?'
        );
        $stmt->bind_param('i', $inv['supplier_id']);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        $party = $r ? $r->fetch_assoc() : null;
        $inv['party_name'] = $party['name'] ?? '';
        $inv['party_phone'] = $party['phone'] ?? '';
    }

    $inv['invoice_date_shamsi'] = $inv['invoice_date']
        ? jdate('j F Y', strtotime($inv['invoice_date']))
        : '';

    $items = [];
    $stmt = $mysqli->prepare(
        'SELECT ii.*, p.code AS product_code, p.name AS product_name, p.unit AS product_unit
         FROM invoice_items ii
         LEFT JOIN products p ON p.id = ii.product_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.id'
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
    }

    // بارگذاری واحدهای اندازه‌گیری برای هر کالای اقلام (برای ویرایش / نمایش)
    if (count($items) > 0) {
        $unitsMap = getUnitsMapForProducts($mysqli, array_column($items, 'product_id'));
        foreach ($items as &$it) {
            $it['units'] = $unitsMap[(int) $it['product_id']] ?? [];
        }
        unset($it);
    }

    $inv['items'] = $items;
    return $inv;
}

/* ============================================================
 * ۱۲) مقایسه اقلام قدیم/جدی (Delta)
 * ========================================================== */

function compareInvoiceItems(array $oldItems, array $newItems): array
{
    $old = [];
    foreach ($oldItems as $it) {
        $pid = (int) $it['product_id'];
        $bq = (string) ($it['base_quantity'] ?? $it['quantity']);
        $old[$pid] = money_add($old[$pid] ?? '0', $bq);
    }
    $new = [];
    foreach ($newItems as $it) {
        $pid = (int) $it['product_id'];
        $bq = (string) ($it['base_quantity'] ?? $it['quantity']);
        $new[$pid] = money_add($new[$pid] ?? '0', $bq);
    }
    $all = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
    $delta = [];
    foreach ($all as $pid) {
        $delta[$pid] = money_sub($new[$pid] ?? '0', $old[$pid] ?? '0');
    }
    return $delta;
}

/* ============================================================
 * ۱۳) درج اقلام در دیتابیس
 * ========================================================== */

function insertInvoiceItems(mysqli $mysqli, int $invoiceId, array $items): array
{
    $stmt = $mysqli->prepare(
        'INSERT INTO invoice_items (invoice_id, product_id, unit_id, unit_name, conversion_factor, quantity, base_quantity, unit_price, discount, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $inserted = [];
    foreach ($items as $it) {
        $iid = $invoiceId;
        $pid = (int) $it['product_id'];
        $uid = $it['unit_id'] ?? null;
        $uname = $it['unit_name'] ?? null;
        $factor = $it['conversion_factor'] ?? '1';
        $qty = money_round($it['quantity']);
        $bq = money_round($it['base_quantity'] ?? $it['quantity']);
        $unit = money_round($it['unit_price']);
        $disc = money_round($it['discount']);
        $lt = money_round($it['line_total']);
        $stmt->bind_param('iiisdddddd', $iid, $pid, $uid, $uname, $factor, $qty, $bq, $unit, $disc, $lt);
        $stmt->execute();
        $inserted[] = [
            'id'                => (int) $stmt->insert_id,
            'product_id'        => $pid,
            'quantity'          => $qty,
            'base_quantity'     => $bq,
            'unit_name'         => $uname,
            'conversion_factor' => $factor,
        ];
    }
    $stmt->close();
    return $inserted;
}

/* ============================================================
 * ۱۴) سرویس ساخت فاکتور جدید
 * ========================================================== */

function createInvoice(mysqli $mysqli, array $in): array
{
    $valid = validateInvoice($mysqli, $in);
    if (!$valid['ok']) {
        return ['ok' => false, 'message' => $valid['message']];
    }
    $norm = normalizeItems($mysqli, $in['items'] ?? []);
    if (!$norm['ok']) {
        return ['ok' => false, 'message' => $norm['message']];
    }
    $items = $norm['items'];

    /* Backend: در فاکتور فروش، کالای بدون موجودی (product با stock <= 0) پذیرفته نمی‌شود */
    $availability = validateSalesProductAvailability($mysqli, $in['type'] ?? '', $items);
    if (!$availability['ok']) {
        return ['ok' => false, 'message' => $availability['message']];
    }

    $type = $in['type'];

    /* بسته یک کالای مجازی فروش است؛ در فاکتور خرید (v1) مجاز نیست */
    $createKindMap = bundleKindMap($mysqli, array_column($items, 'product_id'));
    if (isPurchase($type)) {
        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            if (($createKindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
                return ['ok' => false, 'message' => 'کالای بسته در فاکتور خرید قابل استفاده نیست.'];
            }
        }
    }

    /* Backend: موجودی کالاهای جزء بسته برای فاکتور فروش کافی باشد (منبع: دیتابیس) */
    if (isSales($type)) {
        $bundleAvail = validateBundleStockForItems($mysqli, $items);
        if (!$bundleAvail['ok']) {
            return ['ok' => false, 'message' => $bundleAvail['message']];
        }
    }

    $partyId = (int) $in['party_id'];
    $tax = loadTaxSettings($mysqli);
    if (!$tax['tax_enabled']) {
        $taxRate = '0';
    } else {
        $providedTax = $in['tax_rate'] ?? null;
        if ($providedTax !== null && $providedTax !== '') {
            $taxRate = money_round($providedTax);
            if (money_cmp($taxRate, '0') < 0 || money_cmp($taxRate, '100') > 0) {
                return ['ok' => false, 'message' => 'نرخ مالیات باید بین ۰ تا ۱۰۰ درصد باشد.'];
            }
        } else {
            $taxRate = money_round($tax['tax_rate']);
        }
    }
    $invoiceDiscount = money_round($in['discount']);
    $clientToken = trim((string) ($in['client_token'] ?? ''));
    $clientToken = $clientToken === '' ? null : substr($clientToken, 0, 64);
    $note = ($in['note'] ?? null);
    $note = ($note === '' || $note === null) ? null : (string) $note;

    $allPids = array_map('intval', array_column($items, 'product_id'));

    $mysqli->begin_transaction();
    try {
        // ایدمپوتنسی: اگر client_token قبلاً استفاده شده باشد همان فاکتور برگردد
        if ($clientToken !== null) {
            $stmt = $mysqli->prepare('SELECT id FROM invoices WHERE client_token = ? LIMIT 1');
            $stmt->bind_param('s', $clientToken);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            if ($res && $row = $res->fetch_assoc()) {
                $mysqli->commit();
                return ['ok' => true, 'message' => 'فاکتور قبلاً با همین توکن ثبت شده است.', 'invoice_id' => (int) $row['id']];
            }
        }

        $invoiceNumber = generateInvoiceNumber($mysqli, $type);
        $totals = calculateInvoiceTotals($items, $invoiceDiscount, $taxRate, $tax['tax_enabled']);
        if (money_cmp($invoiceDiscount, $totals['subtotal']) > 0) {
            throw new Exception('discount_exceeds_subtotal');
        }

        $customerId = isSales($type) ? $partyId : null;
        $supplierId = isPurchase($type) ? $partyId : null;

        $stmt = $mysqli->prepare(
            "INSERT INTO invoices
                (invoice_number, type, customer_id, supplier_id, payment_type, payment_status,
                 invoice_date, subtotal, discount, tax_rate, tax_amount, payable_amount, note, client_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'ssiisssdddddss',
            $invoiceNumber,
            $type,
            $customerId,
            $supplierId,
            $in['payment_type'],
            $in['payment_status'],
            $in['invoice_date'],
            $totals['subtotal'],
            $invoiceDiscount,
            $taxRate,
            $totals['tax_amount'],
            $totals['payable_amount'],
            $note,
            $clientToken
        );
        $stmt->execute();
        $invoiceId = (int) $stmt->insert_id;
        $stmt->close();

        $insertedItems = insertInvoiceItems($mysqli, $invoiceId, $items);

        /* snapshot تاریخی ترکیب بسته برای هر ردیف (پیش‌فاکتور و فاکتور قطعی) */
        foreach ($insertedItems as $row) {
            $pid = (int) $row['product_id'];
            if (($createKindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
                insertInvoiceBundleSnapshot($mysqli, (int) $row['id'], $pid, getBundleComponents($mysqli, $pid));
            }
        }

        // موجودی — فقط فاکتور قطعی
        if (isFinal($type)) {
            $typeMap = productTypeMap($mysqli, $allPids);

            /* نیازمندی کالاهای جزء بسته‌ها (فقط فروش) */
            $componentReq = isSales($type) ? bundleComponentRequirementsFromItems($mysqli, $items) : [];

            /* قفل یکجای همه کالاهای درگیر (عادی + اجزای بسته) به‌صورت مرتب */
            $lockIds = $allPids;
            foreach (array_keys($componentReq) as $cpid) {
                $lockIds[] = (int) $cpid;
            }
            $stocks = $lockIds ? lockProductsById($mysqli, $lockIds) : [];

            /* کالاهای عادی — رفتار قبلی بدون تغییر */
            foreach ($items as $it) {
                $pid = (int) $it['product_id'];
                if (($createKindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
                    continue; // بسته خودش موجودی فیزیکی ندارد
                }
                if (isSales($type) && ($typeMap[$pid] ?? '') === 'service') {
                    continue; // خدمت در فاکتور فروش موجودی را کاهش نمی‌دهد
                }
                $baseQty = (string) ($it['base_quantity'] ?? $it['quantity']);
                $change = isSales($type) ? money_mul('-1', $baseQty) : $baseQty;
                $snapshot = [
                    'unit_name'          => $it['unit_name'] ?? null,
                    'transaction_quantity' => $it['quantity'],
                    'conversion_factor'  => $it['conversion_factor'] ?? null,
                ];
                $stocks[$pid] = applyStockChange($mysqli, $pid, $stocks[$pid], $change, $invoiceId, $type, 'create', $snapshot);
            }

            /* کالاهای جزء بسته — مصرف به‌ازای هر بسته فروخته‌شده */
            foreach ($componentReq as $cpid => $need) {
                if (money_cmp($need, '0') === 0) {
                    continue;
                }
                $stocks[$cpid] = applyStockChange($mysqli, (int) $cpid, $stocks[$cpid], money_mul('-1', $need), $invoiceId, $type, 'create');
            }
        }

        $mysqli->commit();
        return ['ok' => true, 'message' => 'فاکتور با موفقیت ثبت شد.', 'invoice_id' => $invoiceId];
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = invoiceErrorMessage($e, $mysqli, $clientToken);
        if ($msg === 'DOUBLE_SUBMIT_REUSE') {
            return [
                'ok' => true,
                'message' => 'فاکتور قبلاً با همین توکن ثبت شده است.',
                'invoice_id' => $GLOBALS['__invoice_idempotent_id'] ?? 0,
            ];
        }
        return ['ok' => false, 'message' => $msg];
    }
}

/* --- نگاشت خطای داخلی به پیام کاربر و استثنای ایدمپوتنسی --- */
function invoiceErrorMessage(Exception $e, mysqli $mysqli, ?string $clientToken): string
{
    $msg = $e->getMessage();
    if ($msg === 'negative_stock') {
        return 'موجودی کافی برای این عملیات وجود ندارد.';
    }
    if ($msg === 'discount_exceeds_subtotal') {
        return 'تخفیف فاکتور نمی‌تواند از جمع ردیف‌ها بیشتر باشد.';
    }
    if ($msg === 'product_not_found') {
        return 'یکی از کالاهای انتخاب‌شده معتبر نیست.';
    }
    if ($mysqli->errno === 1062 && $clientToken !== null) {
        // تداخل UNIQUE روی client_token: فاکتور موازی ثبت‌شده را برگردان
        $stmt = $mysqli->prepare('SELECT id FROM invoices WHERE client_token = ? LIMIT 1');
        $stmt->bind_param('s', $clientToken);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if ($res && $row = $res->fetch_assoc()) {
            $GLOBALS['__invoice_idempotent_id'] = (int) $row['id'];
            return 'DOUBLE_SUBMIT_REUSE';
        }
    }
    return 'خطا در ذخیره‌سازی: ' . $msg;
}

/* ============================================================
 * ۱۵) سرویس ویرایش فاکتور (Delta-based)
 * ========================================================== */

function editInvoice(mysqli $mysqli, int $invoiceId, array $in): array
{
    $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $old = $res ? $res->fetch_assoc() : null;
    if (!$old) {
        return ['ok' => false, 'message' => 'فاکتور موردنظر یافت نشد.'];
    }
    $oldType = $old['type'];

    /* فاکتوری که برای آن برگشت فروش ثبت شده است باید تاریخی و غیرقابل‌ویرایش بماند */
    $stmt = $mysqli->prepare('SELECT 1 FROM sales_returns WHERE invoice_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $stmt->store_result();
        $hasReturn = $stmt->num_rows > 0;
        $stmt->close();
        if ($hasReturn) {
            return ['ok' => false, 'message' => 'فاکتوری که برای آن برگشت فروش ثبت شده است قابل ویرایش نیست.'];
        }
    }

    $valid = validateInvoice($mysqli, $in);
    if (!$valid['ok']) {
        return ['ok' => false, 'message' => $valid['message']];
    }
    $newType = $in['type'];

    if (isFinal($oldType) !== isFinal($newType)) {
        return ['ok' => false, 'message' => 'تبدیل بین فاکتور قطعی و پیش‌فاکتور باید از عملیات «تبدیل» انجام شود.'];
    }
    if (isSales($oldType) !== isSales($newType)) {
        return ['ok' => false, 'message' => 'تغییر جهت فروش/خرید فاکتور مجاز نیست.'];
    }

    $norm = normalizeItems($mysqli, $in['items'] ?? []);
    if (!$norm['ok']) {
        return ['ok' => false, 'message' => $norm['message']];
    }
    $newItems = $norm['items'];

    /* Backend: در فاکتور فروش، کالای بدون موجودی (product با stock <= 0) پذیرفته نمی‌شود */
    $availability = validateSalesProductAvailability($mysqli, $newType, $newItems);
    if (!$availability['ok']) {
        return ['ok' => false, 'message' => $availability['message']];
    }

    $oldItems = [];
    $stmt = $mysqli->prepare('SELECT id, product_id, quantity, base_quantity, unit_name, conversion_factor FROM invoice_items WHERE invoice_id = ? FOR UPDATE');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    while ($res && ($row = $res->fetch_assoc())) {
        $oldItems[] = $row;
    }

    /* ---------- بسته‌ها: نوع، snapshot تاریخی و ترکیب مورداستفاده ---------- */
    $editAllPids = array_merge(
        array_map('intval', array_column($oldItems, 'product_id')),
        array_map('intval', array_column($newItems, 'product_id'))
    );
    $editKindMap = bundleKindMap($mysqli, $editAllPids);

    if (isPurchase($newType)) {
        foreach ($newItems as $it) {
            $pid = (int) $it['product_id'];
            if (($editKindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
                return ['ok' => false, 'message' => 'کالای بسته در فاکتور خرید قابل استفاده نیست.'];
            }
        }
    }

    /* ترکیب تاریخیِ هر بسته در این فاکتور (حفظ snapshot هنگام ویرایش) */
    $oldItemsAttached = bundleAttachSnapshots($mysqli, $oldItems, $editKindMap);
    $oldBundleComposition = [];
    foreach ($oldItemsAttached as $it) {
        $pid = (int) $it['product_id'];
        if (($editKindMap[$pid] ?? PRODUCT_KIND_STANDARD) !== PRODUCT_KIND_BUNDLE) {
            continue;
        }
        $snap = [];
        foreach (($it['components'] ?? []) as $c) {
            $snap[] = [
                'component_product_id' => (int) $c['component_product_id'],
                'quantity'             => (string) $c['component_quantity'],
            ];
        }
        if (!isset($oldBundleComposition[$pid]) && $snap) {
            $oldBundleComposition[$pid] = $snap;
        }
    }

    /* اقلام جدید با ترکیب حفظ‌شده (در صورت وجود snapshot قبلی) */
    $newItemsComposed = [];
    foreach ($newItems as $it) {
        $pid = (int) $it['product_id'];
        if (($editKindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE && isset($oldBundleComposition[$pid])) {
            $it['components'] = $oldBundleComposition[$pid];
        }
        $newItemsComposed[] = $it;
    }

    /* اعتبارسنجی موجودی اجزای بسته در ویرایش فاکتور فروش */
    if (isSales($newType)) {
        $bundleAvail = validateBundleStockForItems($mysqli, $newItemsComposed);
        if (!$bundleAvail['ok']) {
            return ['ok' => false, 'message' => $bundleAvail['message']];
        }
    }

    $tax = loadTaxSettings($mysqli);
    if (!$tax['tax_enabled']) {
        $taxRate = '0';
    } else {
        $providedTax = $in['tax_rate'] ?? null;
        if ($providedTax !== null && $providedTax !== '') {
            $taxRate = money_round($providedTax);
            if (money_cmp($taxRate, '0') < 0 || money_cmp($taxRate, '100') > 0) {
                return ['ok' => false, 'message' => 'نرخ مالیات باید بین ۰ تا ۱۰۰ درصد باشد.'];
            }
        } else {
            $taxRate = money_round($tax['tax_rate']);
        }
    }
    $invoiceDiscount = money_round($in['discount']);
    $totals = calculateInvoiceTotals($newItems, $invoiceDiscount, $taxRate, $tax['tax_enabled']);
    if (money_cmp($invoiceDiscount, $totals['subtotal']) > 0) {
        return ['ok' => false, 'message' => 'تخفیف فاکتور نمی‌تواند از جمع ردیف‌ها بیشتر باشد.'];
    }

    $allPids = [];
    foreach ($oldItems as $it) {
        $allPids[] = (int) $it['product_id'];
    }
    foreach ($newItems as $it) {
        $allPids[] = (int) $it['product_id'];
    }

    $mysqli->begin_transaction();
    try {
        if (isFinal($newType)) {
            /* کالاهای عادی (به‌جز بسته‌ها) — همان منطق دلتم قبلی */
            $oldNorm = [];
            foreach ($oldItems as $it) {
                if (($editKindMap[(int) $it['product_id']] ?? PRODUCT_KIND_STANDARD) !== PRODUCT_KIND_BUNDLE) {
                    $oldNorm[] = $it;
                }
            }
            $newNorm = [];
            foreach ($newItems as $it) {
                if (($editKindMap[(int) $it['product_id']] ?? PRODUCT_KIND_STANDARD) !== PRODUCT_KIND_BUNDLE) {
                    $newNorm[] = $it;
                }
            }
            $deltas = compareInvoiceItems($oldNorm, $newNorm);

            /* دلتم کالاهای جزء بسته‌ها (بر اساس snapshot تاریخی) */
            if (isSales($newType)) {
                $oldCompReq = bundleComponentRequirementsFromItems($mysqli, $oldItemsAttached);
                $newCompReq = bundleComponentRequirementsFromItems($mysqli, $newItemsComposed);
                $compPids = array_unique(array_merge(array_keys($oldCompReq), array_keys($newCompReq)));
                foreach ($compPids as $cpid) {
                    $cpid = (int) $cpid;
                    $deltas[$cpid] = money_sub($newCompReq[$cpid] ?? '0', $oldCompReq[$cpid] ?? '0');
                }
            }

            /* قفل یکجای همه کالاهای درگیر (عادی + اجزای بسته) */
            $lockIds = $allPids;
            foreach (array_keys($deltas) as $dp) {
                $lockIds[] = (int) $dp;
            }
            $stocks = $lockIds ? lockProductsById($mysqli, $lockIds) : [];
            $typeMap = productTypeMap($mysqli, $allPids);

            foreach ($deltas as $pid => $delta) {
                $pid = (int) $pid;
                if (money_cmp($delta, '0') === 0) {
                    continue; // فقط قیمت/تخفیف/شماره تغییر کرده → موجودی تغییر نکند
                }
                if (isSales($newType) && ($typeMap[$pid] ?? '') === 'service') {
                    continue; // خدمت در فاکتور فروش موجودی را تغییر نمی‌دهد
                }
                $change = isSales($newType) ? money_mul('-1', $delta) : $delta;
                $stocks[$pid] = applyStockChange($mysqli, $pid, $stocks[$pid], $change, $invoiceId, $newType, 'edit');
            }
        } else {
            $stocks = $allPids ? lockProductsById($mysqli, $allPids) : [];
        }

        // بازنویسی اقلام
        $stmt = $mysqli->prepare('DELETE FROM invoice_items WHERE invoice_id = ?');
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $stmt->close();
        $insertedItems = insertInvoiceItems($mysqli, $invoiceId, $newItems);

        /* snapshot تاریخی بسته‌ها (حفظ ترکیب قبلی در صورت وجود) */
        foreach ($insertedItems as $row) {
            $pid = (int) $row['product_id'];
            if (($editKindMap[$pid] ?? PRODUCT_KIND_STANDARD) !== PRODUCT_KIND_BUNDLE) {
                continue;
            }
            $comps = $oldBundleComposition[$pid] ?? getBundleComponents($mysqli, $pid);
            insertInvoiceBundleSnapshot($mysqli, (int) $row['id'], $pid, $comps);
        }

        $partyId = (int) $in['party_id'];
        $customerId = isSales($newType) ? $partyId : null;
        $supplierId = isPurchase($newType) ? $partyId : null;
        $note = ($in['note'] ?? null);
        $note = ($note === '' || $note === null) ? null : (string) $note;

        $stmt = $mysqli->prepare(
            'UPDATE invoices SET
                customer_id = ?, supplier_id = ?, payment_type = ?, payment_status = ?,
                invoice_date = ?, subtotal = ?, discount = ?, tax_rate = ?, tax_amount = ?,
                payable_amount = ?, note = ?
             WHERE id = ?'
        );
        $stmt->bind_param(
            'iisssdddddsi',
            $customerId,
            $supplierId,
            $in['payment_type'],
            $in['payment_status'],
            $in['invoice_date'],
            $totals['subtotal'],
            $invoiceDiscount,
            $taxRate,
            $totals['tax_amount'],
            $totals['payable_amount'],
            $note,
            $invoiceId
        );
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
        return ['ok' => true, 'message' => 'فاکتور با موفقیت ویرایش شد.', 'invoice_id' => $invoiceId];
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'negative_stock') {
            $msg = 'موجودی کافی برای اعمال این ویرایش وجود ندارد.';
        } elseif ($msg === 'product_not_found') {
            $msg = 'یکی از کالاهای انتخاب‌شده معتبر نیست.';
        }
        return ['ok' => false, 'message' => $msg];
    }
}

/* ============================================================
 * ۱۶) سرویس حذف فاکتور
 * ========================================================== */

function deleteInvoice(mysqli $mysqli, int $invoiceId): array
{
    $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $inv = $res ? $res->fetch_assoc() : null;
    if (!$inv) {
        return ['ok' => false, 'message' => 'فاکتور موردنظر یافت نشد.'];
    }
    $type = $inv['type'];

    /* فاکتوری که برای آن برگشت فروش ثبت شده است قابل حذف نیست (حفظ سوابق) */
    $stmt = $mysqli->prepare('SELECT 1 FROM sales_returns WHERE invoice_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $stmt->store_result();
        $hasReturn = $stmt->num_rows > 0;
        $stmt->close();
        if ($hasReturn) {
            return ['ok' => false, 'message' => 'فاکتوری که برای آن برگشت فروش ثبت شده است قابل حذف نیست.'];
        }
    }

    $oldItems = [];
    $stmt = $mysqli->prepare('SELECT id, product_id, quantity, base_quantity, unit_name, conversion_factor FROM invoice_items WHERE invoice_id = ? FOR UPDATE');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    while ($res && ($row = $res->fetch_assoc())) {
        $oldItems[] = $row;
    }
    $allPids = array_map('intval', array_column($oldItems, 'product_id'));
    $deleteKindMap = bundleKindMap($mysqli, $allPids);

    /* ترکیب تاریخی بسته‌ها (هرگز از ترکیب فعلی برای معکوس‌سازی استفاده نمی‌کنیم) */
    $oldItemsAttached = bundleAttachSnapshots($mysqli, $oldItems, $deleteKindMap);
    $deleteCompReq = isSales($type) ? bundleComponentRequirementsFromItems($mysqli, $oldItemsAttached) : [];

    $mysqli->begin_transaction();
    try {
        $lockIds = $allPids;
        foreach (array_keys($deleteCompReq) as $cpid) {
            $lockIds[] = (int) $cpid;
        }
        $stocks = $lockIds ? lockProductsById($mysqli, $lockIds) : [];

        // فقط فاکتور قطعی روی موجودی اثر داشته؛ حذف آن برعکس اعمال می‌شود
        if (isFinal($type)) {
            $typeMap = productTypeMap($mysqli, $allPids);

            /* کالاهای عادی — رفتار قبلی بدون تغییر */
            foreach ($oldItems as $it) {
                $pid = (int) $it['product_id'];
                if (($deleteKindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
                    continue; // بسته خودش موجودی فیزیکی نداشته است
                }
                if (isSales($type) && ($typeMap[$pid] ?? '') === 'service') {
                    continue; // خدمت در فاکتور فروش هیچ تغییری در موجودی نداشته است
                }
                // Sales: stock += base_qty | Purchase: stock -= base_qty (با چک منفی)
                $baseQty = (string) ($it['base_quantity'] ?? $it['quantity']);
                $change = isSales($type) ? $baseQty : money_mul('-1', $baseQty);
                $snapshot = [
                    'unit_name'          => $it['unit_name'] ?? null,
                    'transaction_quantity' => $it['quantity'],
                    'conversion_factor'  => $it['conversion_factor'] ?? null,
                ];
                $stocks[$pid] = applyStockChange($mysqli, $pid, $stocks[$pid], $change, $invoiceId, $type, 'delete', $snapshot);
            }

            /* بازگردانی موجودی کالاهای جزء بسته (بر اساس snapshot تاریخی) */
            foreach ($deleteCompReq as $cpid => $qty) {
                if (money_cmp($qty, '0') === 0) {
                    continue;
                }
                $stocks[$cpid] = applyStockChange($mysqli, (int) $cpid, $stocks[$cpid], $qty, $invoiceId, $type, 'delete');
            }
        } elseif ($inv['type'] === 'sales_proforma' || $inv['type'] === 'purchase_proforma') {
            // پیش‌فاکتور: بدون تغییر موجودی
        }

        // اقلام با CASCADE حذف می‌شوند؛ stock_movements با ON DELETE SET NULL باقی می‌ماند
        $stmt = $mysqli->prepare('DELETE FROM invoices WHERE id = ?');
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
        return ['ok' => true, 'message' => 'فاکتور با موفقیت حذف شد.', 'invoice_id' => $invoiceId];
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'negative_stock') {
            $msg = 'موجودی فعلی کافی برای حذف این فاکتور نیست؛ عملیات لغو شد.';
        } elseif ($msg === 'product_not_found') {
            $msg = 'یکی از کالاهای فاکتور دیگر معتبر نیست.';
        }
        return ['ok' => false, 'message' => $msg];
    }
}

/* ============================================================
 * ۱۷) سرویس تبدیل پیش‌فاکتور → فاکتور قطعی (یک‌بار)
 * ========================================================== */

function convertInvoice(mysqli $mysqli, int $invoiceId): array
{
    $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $inv = $res ? $res->fetch_assoc() : null;
    if (!$inv) {
        return ['ok' => false, 'message' => 'فاکتور موردنظر یافت نشد.'];
    }
    if (isFinal($inv['type'])) {
        return ['ok' => false, 'message' => 'این فاکتور قبلاً به فاکتور قطعی تبدیل شده است.'];
    }

    $type = $inv['type'];
    $finalType = isSales($type) ? 'sales_invoice' : 'purchase_invoice';
    $isSales = isSales($type);

    $items = [];
    $stmt = $mysqli->prepare('SELECT id, product_id, quantity, base_quantity, unit_name, conversion_factor FROM invoice_items WHERE invoice_id = ?');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = $row;
    }
    $allPids = array_map('intval', array_column($items, 'product_id'));
    $convKindMap = bundleKindMap($mysqli, $allPids);

    /* ترکیب تاریخی بسته‌ها در پیش‌فاکتور (نه ترکیب فعلی) */
    $itemsAttached = bundleAttachSnapshots($mysqli, $items, $convKindMap);
    $convCompReq = $isSales ? bundleComponentRequirementsFromItems($mysqli, $itemsAttached) : [];

    $mysqli->begin_transaction();
    try {
        $lockIds = $allPids;
        foreach (array_keys($convCompReq) as $cpid) {
            $lockIds[] = (int) $cpid;
        }
        $stocks = $lockIds ? lockProductsById($mysqli, $lockIds) : [];
        $newNumber = generateInvoiceNumber($mysqli, $finalType);
        $typeMap = productTypeMap($mysqli, $allPids);

        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            if (($convKindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
                continue; // بسته خودش موجودی فیزیکی ندارد؛ اجزایش جداگانه مصرف می‌شود
            }
            if ($isSales && ($typeMap[$pid] ?? '') === 'service') {
                continue; // خدمت در تبدیل پیش‌فاکتور فروش موجودی را تغییر نمی‌دهد
            }
            $baseQty = (string) ($it['base_quantity'] ?? $it['quantity']);
            $change = $isSales ? money_mul('-1', $baseQty) : $baseQty;
            $snapshot = [
                'unit_name'          => $it['unit_name'] ?? null,
                'transaction_quantity' => $it['quantity'],
                'conversion_factor'  => $it['conversion_factor'] ?? null,
            ];
            $stocks[$pid] = applyStockChange($mysqli, $pid, $stocks[$pid], $change, $invoiceId, $finalType, 'create', $snapshot);
        }

        /* مصرف اجزای بسته بر اساس snapshot ذخیره‌شده در پیش‌فاکتور */
        foreach ($convCompReq as $cpid => $need) {
            if (money_cmp($need, '0') === 0) {
                continue;
            }
            $stocks[$cpid] = applyStockChange($mysqli, (int) $cpid, $stocks[$cpid], money_mul('-1', $need), $invoiceId, $finalType, 'create');
        }

        $stmt = $mysqli->prepare('UPDATE invoices SET type = ?, invoice_number = ? WHERE id = ?');
        $stmt->bind_param('ssi', $finalType, $newNumber, $invoiceId);
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
        return ['ok' => true, 'message' => 'پیش‌فاکتور با موفقیت به فاکتور قطعی تبدیل شد.', 'invoice_id' => $invoiceId];
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'negative_stock') {
            $msg = 'موجودی کافی برای تبدیل به فاکتور قطعی وجود ندارد.';
        } elseif ($msg === 'product_not_found') {
            $msg = 'یکی از کالاهای فاکتور معتبر نیست.';
        }
        return ['ok' => false, 'message' => $msg];
    }
}

/* ============================================================
 * ۱۸) سرویس لیست فاکتورها
 * ========================================================== */

function listInvoices(mysqli $mysqli, array $filter): array
{
    $where = [];
    $types = '';
    $params = [];

    $typeGroup = $filter['type_group'] ?? ''; // sales | purchase | ''
    if ($typeGroup === 'sales') {
        $where[] = "type IN ('sales_invoice','sales_proforma')";
    } elseif ($typeGroup === 'purchase') {
        $where[] = "type IN ('purchase_invoice','purchase_proforma')";
    }

    if (!empty($filter['search'])) {
        $search = '%' . $filter['search'] . '%';

        if ($typeGroup === 'sales') {
            // جستجوی فاکتور فروش بر اساس:
            // شماره فاکتور، یادداشت، نام/نام خانوادگی مشتری
            $where[] = '(
            invoices.invoice_number LIKE ?
            OR invoices.note LIKE ?
            OR EXISTS (
                SELECT 1
                FROM customers c
                WHERE c.id = invoices.customer_id
                  AND (
                      c.first_name LIKE ?
                      OR c.last_name LIKE ?
                      OR CONCAT(c.first_name, " ", c.last_name) LIKE ?
                  )
            )
        )';

            $types .= 'sssss';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        } elseif ($typeGroup === 'purchase') {
            // جستجوی فاکتور خرید بر اساس:
            // شماره فاکتور، یادداشت، نام شرکت، نام و نام خانوادگی تامین‌کننده
            $where[] = '(
            invoices.invoice_number LIKE ?
            OR invoices.note LIKE ?
            OR EXISTS (
                SELECT 1
                FROM suppliers s
                WHERE s.id = invoices.supplier_id
                  AND (
                      s.company_name LIKE ?
                      OR s.first_name LIKE ?
                      OR s.last_name LIKE ?
                      OR CONCAT(s.first_name, " ", s.last_name) LIKE ?
                  )
            )
        )';

            $types .= 'ssssss';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        } else {
            // حالت عمومی؛ برای جلوگیری از تغییر رفتار سایر استفاده‌های تابع
            $where[] = '(invoices.invoice_number LIKE ? OR invoices.note LIKE ?)';
            $types .= 'ss';
            $params[] = $search;
            $params[] = $search;
        }
    }
    if (!empty($filter['payment_status']) && in_array($filter['payment_status'], PAYMENT_STATUSES, true)) {
        $where[] = 'payment_status = ?';
        $types .= 's';
        $params[] = $filter['payment_status'];
    }
    if (!empty($filter['type']) && in_array($filter['type'], INVOICE_TYPES, true)) {
        $where[] = 'type = ?';
        $types .= 's';
        $params[] = $filter['type'];
    }

    /* ---------- صفحه‌بندی سمت سرور (حداکثر ۱۵ فاکتور در هر صفحه) ---------- */
    $perPage = max(1, min(15, (int) ($filter['per_page'] ?? 15)));
    $page = max(1, (int) ($filter['page'] ?? 1));

    /* تعداد کل منطبق با فیلترها (همان شرط‌های کوئری اصلی) */
    $countSql = 'SELECT COUNT(*) AS c FROM invoices';
    if ($where) {
        $countSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $total = 0;
    if ($types !== '') {
        $stmt = $mysqli->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    } else {
        $res = $mysqli->query($countSql);
    }
    if ($res && ($row = $res->fetch_assoc())) {
        $total = (int) $row['c'];
    }

    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT * FROM invoices';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id DESC LIMIT ? OFFSET ?';

    $rows = [];
    if ($types !== '') {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    } else {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $partyCache = [];
    foreach ($rows as &$r) {
        if (isSales($r['type'])) {
            $cid = (int) $r['customer_id'];
            if (!isset($partyCache['c'][$cid])) {
                $stmt = $mysqli->prepare('SELECT CONCAT(first_name," ",last_name) AS name FROM customers WHERE id = ?');
                $stmt->bind_param('i', $cid);
                $stmt->execute();
                $q = $stmt->get_result();
                $stmt->close();
                $partyCache['c'][$cid] = ($q && ($p = $q->fetch_assoc())) ? $p['name'] : '';
            }
            $r['party_name'] = $partyCache['c'][$cid];
        } else {
            $sid = (int) $r['supplier_id'];
            if (!isset($partyCache['s'][$sid])) {
                $stmt = $mysqli->prepare(
                    'SELECT IF(company_name IS NOT NULL AND company_name <> "", company_name,
                        CONCAT(COALESCE(first_name,"")," ",COALESCE(last_name,""))) AS name
                     FROM suppliers WHERE id = ?'
                );
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $q = $stmt->get_result();
                $stmt->close();
                $partyCache['s'][$sid] = ($q && ($p = $q->fetch_assoc())) ? $p['name'] : '';
            }
            $r['party_name'] = $partyCache['s'][$sid];
        }
        $r['invoice_date_shamsi'] = $r['invoice_date'] ? jdate('j F Y', strtotime($r['invoice_date'])) : '';
        unset($r);
    }

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

/* ============================================================
 * بارگذاری ماژول بسته‌بندی برای پشتیبانی از کالاهای مجازی (bundle)
 * (require_once چرخشی ایمن است؛ توابع در زمان فراخوانی resolve می‌شوند.)
 * ========================================================== */
require_once __DIR__ . '/bundle_common.php';
