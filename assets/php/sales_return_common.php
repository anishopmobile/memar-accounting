<?php

/**
 * sales_return_common.php — منطق مشترک ماژول برگشت فروش
 *
 * سندهای برگشت فروش به‌صورت مستقل و متصل به فاکتور فروش قطعی ثبت می‌شوند.
 *  - پرونده‌های پیش‌فاکتور هرگز قابل برگشت نیستند.
 *  - مقادیر مالی از snapshot فاکتور اصلی (invoice_items) گرفته می‌شود نه قیمت فعلی کالا.
 *  - مبالغ فقط با helperهای BCMath محاسبه می‌شود (money_* در invoice_common.php).
 *  - تغییرات موجودی فقط از طریق applyStockChange (معماری متمرکز موجود) اعمال می‌شود.
 *
 * ترتیب بارگذاری: boot.php → db.php → invoice_common.php → sales_return_common.php
 */

require_once __DIR__ . '/invoice_common.php';

/* ============================================================
 * ۱) شماره سند برگشت فروش (اتمیک — reuse جدول invoice_sequences)
 * ========================================================== */

function sr_return_prefix(): string
{
    return 'SR-';
}

/**
 * تولید شماره یکتا و اتمیک سند برگشت فروش (داخل Transaction).
 */
function sr_generate_return_number(mysqli $mysqli): string
{
    $seqKey = 'sales_return';
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
    return sr_return_prefix() . sprintf('%06d', $val);
}

/* ============================================================
 * ۲) اعتبارسنجی ورودی‌های پایه
 * ========================================================== */

/**
 * @return array ['ok' => bool, 'message' => string]
 */
function sr_validate_basic_input(array $in): array
{
    $invoiceId = trim((string) ($in['invoice_id'] ?? ''));
    if ($invoiceId === '' || !ctype_digit($invoiceId) || (int) $invoiceId <= 0) {
        return ['ok' => false, 'message' => 'شناسه فاکتور نامعتبر است.'];
    }

    $date = trim((string) ($in['return_date'] ?? ''));
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'message' => 'تاریخ برگشت نامعتبر است.'];
    }
    if (!checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        return ['ok' => false, 'message' => 'تاریخ برگشت معتبر نیست.'];
    }

    $note = (string) ($in['note'] ?? '');
    if (mb_strlen($note) > 5000) {
        return ['ok' => false, 'message' => 'یادداشت برگشت فروش بلندتر از حد مجاز است.'];
    }

    return ['ok' => true, 'message' => ''];
}

/* ============================================================
 * ۳) بارگذاری بافت فاکتور + مقدار قابل‌برگشت (منبع حقیقت)
 * ========================================================== */

/**
 * بارگذاری فاکتور فروش قطعی و اقلام آن همراه با:
 *  - مقدار فروخته‌شده (واحد پایه)
 *  - مقدار از‌قبل‌برگشتی (سایر سندهای برگشت)
 *  - مقدار قابل‌برگشت
 *
 * اگر excludeReturnId داده شود، اقلام همان سند در «بازگشتی» لحاظ نمی‌شود
 * (برای ویرایش سند فعلی).
 *
 * @return array ['ok'=>true,'invoice'=>...,'items'=>map] | ['ok'=>false,'message'=>...]
 */
function sr_invoice_context(mysqli $mysqli, int $invoiceId, ?int $excludeReturnId = null): array
{
    $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $inv = $res ? $res->fetch_assoc() : null;
    if (!$inv) {
        return ['ok' => false, 'message' => 'فاکتور موردنظر یافت نشد.'];
    }
    if ($inv['type'] !== 'sales_invoice') {
        return ['ok' => false, 'message' => 'فقط فاکتور فروش قطعی قابل برگشت است.'];
    }

    /* نام مشتری برای نمایش در فرم/فاکتور */
    $inv['customer_name'] = '';
    $inv['customer_phone'] = '';
    $customerId = (int) ($inv['customer_id'] ?? 0);
    if ($customerId > 0) {
        $stmt = $mysqli->prepare('SELECT CONCAT(first_name," ",last_name) AS name, phone FROM customers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        $c = $r ? $r->fetch_assoc() : null;
        if ($c) {
            $inv['customer_name'] = (string) ($c['name'] ?? '');
            $inv['customer_phone'] = (string) ($c['phone'] ?? '');
        }
    }

    $items = [];
    $stmt = $mysqli->prepare(
        'SELECT ii.*, p.code AS product_code, p.name AS product_name, p.type AS product_type
         FROM invoice_items ii
         LEFT JOIN products p ON p.id = ii.product_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.id ASC'
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    while ($res && ($row = $res->fetch_assoc())) {
        $rowId = (int) $row['id'];
        $items[$rowId] = $row;
    }

    /* مقدار از‌قبل‌برگشتی هر اقلام فاکتور (بر حسب واحد پایه) */
    $returnedMap = [];
    $sql = 'SELECT sri.invoice_item_id, SUM(sri.base_quantity) AS returned_base
            FROM sales_return_items sri
            INNER JOIN sales_returns sr ON sr.id = sri.return_id
            WHERE sr.invoice_id = ? AND sri.invoice_item_id IS NOT NULL';
    if ($excludeReturnId !== null && $excludeReturnId > 0) {
        $sql .= ' AND sr.id <> ?';
    }
    $sql .= ' GROUP BY sri.invoice_item_id';

    $stmt = $mysqli->prepare($sql);
    if ($excludeReturnId !== null && $excludeReturnId > 0) {
        $stmt->bind_param('ii', $invoiceId, $excludeReturnId);
    } else {
        $stmt->bind_param('i', $invoiceId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    while ($res && ($row = $res->fetch_assoc())) {
        $returnedMap[(int) $row['invoice_item_id']] = (string) $row['returned_base'];
    }

    /* محاسبه قابل‌برگشت + اطلاعات نمایشی */
    foreach ($items as &$it) {
        $id = (int) $it['id'];
        $origBase = (string) ($it['base_quantity'] !== null ? $it['base_quantity'] : $it['quantity']);
        $returnedBase = $returnedMap[$id] ?? '0';
        $able = money_sub($origBase, $returnedBase);
        if (money_cmp($able, '0') < 0) {
            $able = '0';
        }
        $factor = (string) ($it['conversion_factor'] ?? '1');
        if (money_cmp($factor, '0') <= 0) {
            $factor = '1';
        }
        $it['orig_base']          = money_round($origBase);
        $it['returned_base']      = money_round($returnedBase);
        $it['returnable_base']    = money_round($able);
        $it['returned_display']   = money_round(money_div($returnedBase, $factor)); // فقط برای نمایش
        $it['returnable_display'] = money_round(money_div($able, $factor)); // فقط برای نمایش
        unset($it);
    }

    return ['ok' => true, 'message' => '', 'invoice' => $inv, 'items' => $items];
}

/* ============================================================
 * ۴) نرمال‌سازی و اعتبارسنجی اقلام برگشت
 * ========================================================== */

/**
 * اعتبارسنجی اقلام ارسال‌شده از کلاینت و ساخت snapshot سمت سرور.
 * هر اقلام باید تنها بر اساس invoice_item_id واقعی فاکتور باشد.
 * قیمت/تخفیف/واحد هرگز از کلاینت پذیرفته نمی‌شود.
 *
 * @return array ['ok'=>bool,'message'=>string,'items'=>array]
 */
function sr_normalize_return_items(array $ctxItems, array $rawItems): array
{
    if (count($rawItems) === 0) {
        return ['ok' => false, 'message' => 'حداقل یک ردیف اقلام باید وارد شود.', 'items' => []];
    }

    $merged = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) {
            return ['ok' => false, 'message' => 'ساختار ردیف های برگشت نامعتبر است.', 'items' => []];
        }
        $iiIdRaw = trim((string) ($raw['invoice_item_id'] ?? ''));
        if ($iiIdRaw === '' || !ctype_digit($iiIdRaw)) {
            return ['ok' => false, 'message' => 'شناسه اقلام فاکتور نامعتبر است.', 'items' => []];
        }
        $iiId = (int) $iiIdRaw;
        if (!isset($ctxItems[$iiId])) {
            return ['ok' => false, 'message' => 'یکی از اقلام به این فاکتور تعلق ندارد.', 'items' => []];
        }
        $item = $ctxItems[$iiId];

        /* اطمینان از هم‌خوانی محصول/واحد ارسالی با اقلام واقعی فاکتور */
        $pidRaw = trim((string) ($raw['product_id'] ?? ''));
        if ($pidRaw !== '') {
            if (!ctype_digit($pidRaw) || (int) $pidRaw !== (int) $item['product_id']) {
                return ['ok' => false, 'message' => 'کالای یکی از ردیف‌ها با فاکتور اصلی مطابقت ندارد.', 'items' => []];
            }
        }
        $unitRaw = trim((string) ($raw['unit_id'] ?? ''));
        if ($unitRaw !== '' && $unitRaw !== '0') {
            if (!ctype_digit($unitRaw) || (int) $unitRaw !== (int) ($item['unit_id'] ?? 0)) {
                return ['ok' => false, 'message' => 'واحد یکی از ردیف‌ها با فاکتور اصلی مطابقت ندارد.', 'items' => []];
            }
        }

        $qty = trim((string) ($raw['quantity'] ?? ''));
        if ($qty === '' || !is_numeric($qty) || money_cmp($qty, '0') <= 0) {
            return ['ok' => false, 'message' => "تعداد برگشتی برای ردیف «{$item['product_name']}» نامعتبر است.", 'items' => []];
        }
        $qtyR = money_round($qty);

        if (isset($merged[$iiId])) {
            $merged[$iiId]['quantity'] = money_add($merged[$iiId]['quantity'], $qtyR);
            continue;
        }

        $factor = (string) ($item['conversion_factor'] ?? '1');
        if (money_cmp($factor, '0') <= 0) {
            $factor = '1';
        }
        $baseQty = money_round(money_mul($qtyR, $factor));

        /* محدودیت سخت‌افزاری: بیش از مقدار قابل‌برگشت مجاز نیست */
        if (money_cmp($baseQty, (string) $item['returnable_base']) > 0) {
            return [
                'ok' => false,
                'message' => "مقدار برگشتی «{$item['product_name']}» از مقدار قابل‌برگشت بیشتر است.",
                'items' => [],
            ];
        }

        /* snapshot مالی از فاکتور اصلی (نه قیمت فعلی کالا) */
        $origQty = (string) ($item['quantity'] ?? '0');
        $allocDiscount = '0';
        if (money_cmp($origQty, '0') > 0 && money_cmp((string) $item['discount'], '0') > 0) {
            $allocDiscount = money_round(money_div(money_mul((string) $item['discount'], $qtyR), $origQty));
        }
        $lineTotal = money_round(money_sub(money_mul($qtyR, (string) $item['unit_price']), $allocDiscount));
        if (money_cmp($lineTotal, '0') < 0) {
            $lineTotal = '0';
        }

        $merged[$iiId] = [
            'invoice_item_id'    => $iiId,
            'product_id'         => (int) $item['product_id'],
            'unit_id'            => $item['unit_id'] ?? null,
            'unit_name'          => $item['unit_name'] ?? null,
            'conversion_factor'  => $factor,
            'quantity'           => $qtyR,
            'base_quantity'      => $baseQty,
            'unit_price'         => money_round((string) $item['unit_price']),
            'discount'           => $allocDiscount,
            'line_total'         => $lineTotal,
            'product_name'       => $item['product_name'] ?? '',
        ];
    }

    /* بررسی مجدد محدودیت پس از ادغام ردیف‌های تکراری */
    foreach ($merged as $iiId => &$row) {
        $item = $ctxItems[$iiId];
        $factor = (string) ($item['conversion_factor'] ?? '1');
        if (money_cmp($factor, '0') <= 0) {
            $factor = '1';
        }
        $baseQty = money_round(money_mul($row['quantity'], $factor));
        if (money_cmp($baseQty, (string) $item['returnable_base']) > 0) {
            return [
                'ok' => false,
                'message' => "مقدار برگشتی «{$item['product_name']}» از مقدار قابل‌برگشت بیشتر است.",
                'items' => [],
            ];
        }
        $row['base_quantity'] = $baseQty;
        unset($row);
    }

    $items = [];
    foreach ($merged as $row) {
        $items[] = $row;
    }
    return ['ok' => true, 'message' => '', 'items' => $items];
}

/* ============================================================
 * ۵) محاسبات مالی سند برگشت
 * ========================================================== */

/**
 * تعیین حالت مالیات سند برگشت بر اساس تنظیمات موجود و مالیات فاکتور اصلی.
 */
function sr_return_tax(mysqli $mysqli, array $invoice): array
{
    $tax = loadTaxSettings($mysqli);
    $invoiceApplied = money_cmp((string) ($invoice['tax_amount'] ?? '0'), '0') > 0;
    if (!$tax['tax_enabled'] && !$invoiceApplied) {
        return ['tax_enabled' => false, 'tax_rate' => '0'];
    }
    $invRate = money_round((string) ($invoice['tax_rate'] ?? '0'));
    $rate = money_cmp($invRate, '0') > 0 ? $invRate : money_round((string) ($tax['tax_rate'] ?? '0'));
    return ['tax_enabled' => true, 'tax_rate' => $rate];
}

/**
 * تخصیص نسبی تخفیف سراسری فاکتور اصلی به سند برگشت
 * (تا کل تخفیف فاکتور روی زیرمجموعه کوچک اعمال نشود).
 */
function sr_allocated_doc_discount(array $invoice, $returnSubtotal): string
{
    $invSubtotal = (string) ($invoice['subtotal'] ?? '0');
    $invDiscount = (string) ($invoice['discount'] ?? '0');
    if (money_cmp($invSubtotal, '0') <= 0 || money_cmp($invDiscount, '0') <= 0) {
        return '0';
    }
    return money_round(money_div(money_mul($invDiscount, $returnSubtotal), $invSubtotal));
}

/**
 * جمع مبالغ سند برگشت — فقط سمت سرور.
 */
function sr_compute_totals(array $items, array $invoice, array $taxInfo): array
{
    $subtotal = '0';
    foreach ($items as $it) {
        $subtotal = money_add($subtotal, $it['line_total']);
    }
    $subtotal = money_round($subtotal);
    $discount = sr_allocated_doc_discount($invoice, $subtotal);
    $totals = calculateInvoiceTotals($items, $discount, $taxInfo['tax_rate'], $taxInfo['tax_enabled']);
    return array_merge($totals, ['discount' => $discount]);
}

/* ============================================================
 * ۶) ذخیره اقلام سند برگشت
 * ========================================================== */

function sr_insert_return_items(mysqli $mysqli, int $returnId, array $items): void
{
    $stmt = $mysqli->prepare(
        'INSERT INTO sales_return_items
            (return_id, invoice_item_id, product_id, unit_id, unit_name, conversion_factor,
             quantity, base_quantity, unit_price, discount, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($items as $it) {
        $vRetId   = $returnId;
        $vIiId    = (int) $it['invoice_item_id'];
        $vPid     = (int) $it['product_id'];
        $vUnitId  = $it['unit_id'] ?? null;
        $vUnitName = $it['unit_name'] ?? null;
        $vFactor  = $it['conversion_factor'] ?? null;
        $vQty     = money_round($it['quantity']);
        $vBase    = money_round($it['base_quantity']);
        $vPrice   = money_round($it['unit_price']);
        $vDisc    = money_round($it['discount']);
        $vLine    = money_round($it['line_total']);
        $stmt->bind_param(
            'iiisddddddd',
            $vRetId,
            $vIiId,
            $vPid,
            $vUnitId,
            $vUnitName,
            $vFactor,
            $vQty,
            $vBase,
            $vPrice,
            $vDisc,
            $vLine
        );
        $stmt->execute();
    }
    $stmt->close();
}

/* ============================================================
 * ۷) اعمال تغییر موجودی برگشت (تغییرات صرفاً از طریق applyStockChange)
 * ========================================================== */

/**
 * @param string $op create | edit | delete
 */
function sr_apply_stock_for_items(
    mysqli $mysqli,
    array $items,
    int $returnId,
    string $op,
    array $stockOverrides = []
): void {
    if (count($items) === 0) {
        return;
    }

    /* نوع کالاها (standard | bundle) */
    $kindMap = bundleKindMap($mysqli, array_column($items, 'product_id'));
    $hasBundle = false;
    foreach ($items as $it) {
        if (($kindMap[(int) $it['product_id']] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
            $hasBundle = true;
            break;
        }
    }

    /* اقلام بسته با ترکیب تاریخی (snapshot) به کالاهای جزء گسترش می‌یابند */
    $itemsAttached = $hasBundle ? bundleAttachSnapshots($mysqli, $items, $kindMap) : $items;

    $normalItems = [];
    foreach ($itemsAttached as $it) {
        $pid = (int) $it['product_id'];
        if (($kindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
            continue;
        }
        $normalItems[] = $it;
    }

    $componentReq = $hasBundle ? bundleComponentRequirementsFromItems($mysqli, $itemsAttached) : [];

    $lockIds = array_map('intval', array_column($normalItems, 'product_id'));
    foreach (array_keys($componentReq) as $cpid) {
        $lockIds[] = (int) $cpid;
    }
    if (count($lockIds) === 0) {
        return;
    }
    $stocks = lockProductsById($mysqli, $lockIds);

    /* کالاهای عادی — رفتار قبلی بدون تغییر */
    foreach ($normalItems as $it) {
        $pid = (int) $it['product_id'];
        $change = $stockOverrides[$pid] ?? $it['base_quantity'];

        if (money_cmp($change, '0') === 0) {
            continue;
        }
        $snapshot = [
            'unit_name'          => $it['unit_name'] ?? null,
            'transaction_quantity' => $it['quantity'] ?? null,
            'conversion_factor'  => $it['conversion_factor'] ?? null,
        ];
        $stocks[$pid] = applyStockChange(
            $mysqli,
            $pid,
            $stocks[$pid],
            $change,
            null,            // سند برگشت در stock_movements.invoice_id قرار نمی‌گیرد (FK به invoices)
            'sales_return',
            $op,
            $snapshot,
            $returnId
        );
    }

    /* کالاهای جزء بسته — بر اساس snapshot تاریخی همان ردیف فاکتور */
    foreach ($componentReq as $cpid => $change) {
        $cpid = (int) $cpid;
        if (money_cmp($change, '0') === 0) {
            continue;
        }
        $stocks[$cpid] = applyStockChange(
            $mysqli,
            $cpid,
            $stocks[$cpid],
            $change,
            null,
            'sales_return',
            $op,
            null,
            $returnId
        );
    }
}

/* ============================================================
 * ۸) ساخت سند برگشت جدید
 * ========================================================== */

function createSalesReturn(mysqli $mysqli, array $in): array
{
    $valid = sr_validate_basic_input($in);
    if (!$valid['ok']) {
        return ['ok' => false, 'message' => $valid['message']];
    }
    $invoiceId = (int) $in['invoice_id'];
    $note = trim((string) ($in['note'] ?? ''));
    $note = $note === '' ? null : $note;
    $rawItems = $in['items'] ?? [];
    if (!is_array($rawItems)) {
        $rawItems = [];
    }

    $mysqli->begin_transaction();
    try {
        /* قفل فاکتور: تمام سندهای برگشت یک فاکتور سریالی اجرا می‌شوند */
        $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $inv = $res ? $res->fetch_assoc() : null;
        if (!$inv) {
            throw new Exception('invoice_not_found');
        }
        if ($inv['type'] !== 'sales_invoice') {
            throw new Exception('not_final_sales');
        }

        $ctx = sr_invoice_context($mysqli, $invoiceId, null);
        if (!$ctx['ok']) {
            throw new Exception('invoice_context');
        }
        $norm = sr_normalize_return_items($ctx['items'], $rawItems);
        if (!$norm['ok']) {
            $mysqli->rollback();
            return ['ok' => false, 'message' => $norm['message']];
        }
        $items = $norm['items'];

        $taxInfo = sr_return_tax($mysqli, $inv);
        $totals = sr_compute_totals($items, $inv, $taxInfo);

        $returnNumber = sr_generate_return_number($mysqli);

        $stmt = $mysqli->prepare(
            'INSERT INTO sales_returns
                (return_number, invoice_id, customer_id, return_date, subtotal, discount, tax_rate, tax_amount, payable_amount, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $custId    = $inv['customer_id'];
        $retDate   = $in['return_date'];
        $vSubtotal = money_round($totals['subtotal']);
        $vDiscount = money_round($totals['discount']);
        $vTaxRate  = money_round($taxInfo['tax_rate']);
        $vTaxAmount = money_round($totals['tax_amount']);
        $vPayable  = money_round($totals['payable_amount']);
        $stmt->bind_param(
            'siisddddds',
            $returnNumber,
            $invoiceId,
            $custId,
            $retDate,
            $vSubtotal,
            $vDiscount,
            $vTaxRate,
            $vTaxAmount,
            $vPayable,
            $note
        );
        $stmt->execute();
        $returnId = (int) $stmt->insert_id;
        $stmt->close();

        sr_insert_return_items($mysqli, $returnId, $items);
        sr_apply_stock_for_items($mysqli, $items, $returnId, 'create');

        $mysqli->commit();
        return ['ok' => true, 'message' => 'برگشت فروش با موفقیت ثبت شد.', 'return_id' => $returnId, 'return_number' => $returnNumber];
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'invoice_not_found') {
            $msg = 'فاکتور موردنظر یافت نشد.';
        } elseif ($msg === 'not_final_sales') {
            $msg = 'فقط فاکتور فروش قطعی قابل برگشت است.';
        } elseif ($msg === 'invoice_context') {
            $msg = 'فاکتور انتخابی برای برگشت معتبر نیست.';
        } elseif ($msg === 'negative_stock') {
            $msg = 'موجودی فعلی برای ثبت این برگشت کافی نیست.';
        } elseif ($msg === 'product_not_found') {
            $msg = 'یکی از کالاهای برگشتی دیگر معتبر نیست.';
        }
        return ['ok' => false, 'message' => $msg];
    }
}

/* ============================================================
 * ۹) ویرایش سند برگشت (فقط اعمال دلتم موجودی)
 * ========================================================== */

function editSalesReturn(mysqli $mysqli, int $returnId, array $in): array
{
    $valid = sr_validate_basic_input($in);
    if (!$valid['ok']) {
        return ['ok' => false, 'message' => $valid['message']];
    }
    $invoiceId = (int) $in['invoice_id'];
    $note = trim((string) ($in['note'] ?? ''));
    $note = $note === '' ? null : $note;
    $rawItems = $in['items'] ?? [];
    if (!is_array($rawItems)) {
        $rawItems = [];
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare('SELECT * FROM sales_returns WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $ret = $res ? $res->fetch_assoc() : null;
        if (!$ret) {
            throw new Exception('return_not_found');
        }
        if ((int) $ret['invoice_id'] !== $invoiceId) {
            throw new Exception('invoice_mismatch');
        }

        $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $inv = $res ? $res->fetch_assoc() : null;
        if (!$inv) {
            throw new Exception('invoice_not_found');
        }

        $ctx = sr_invoice_context($mysqli, $invoiceId, $returnId);
        if (!$ctx['ok']) {
            throw new Exception('invoice_context');
        }
        $norm = sr_normalize_return_items($ctx['items'], $rawItems);
        if (!$norm['ok']) {
            $mysqli->rollback();
            return ['ok' => false, 'message' => $norm['message']];
        }
        $newItems = $norm['items'];

        /* اقلام قدیمی سند جاری (برای محاسبه دلتم) */
        $oldItems = [];
        $stmt = $mysqli->prepare('SELECT * FROM sales_return_items WHERE return_id = ?');
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        while ($res && ($row = $res->fetch_assoc())) {
            $oldItems[] = $row;
        }

        /* دلتم موجودی: کالاهای عادی به تفکیک کالا + کالاهای جزء بسته بر اساس snapshot تاریخی */
        $kindMap = bundleKindMap($mysqli, array_merge(
            array_map('intval', array_column($oldItems, 'product_id')),
            array_map('intval', array_column($newItems, 'product_id'))
        ));

        $oldNormItems = [];
        foreach ($oldItems as $it) {
            if (($kindMap[(int) $it['product_id']] ?? PRODUCT_KIND_STANDARD) !== PRODUCT_KIND_BUNDLE) {
                $oldNormItems[] = $it;
            }
        }
        $newNormItems = [];
        foreach ($newItems as $it) {
            if (($kindMap[(int) $it['product_id']] ?? PRODUCT_KIND_STANDARD) !== PRODUCT_KIND_BUNDLE) {
                $newNormItems[] = $it;
            }
        }

        $oldByPid = [];
        foreach ($oldNormItems as $it) {
            $pid = (int) $it['product_id'];
            $oldByPid[$pid] = money_add($oldByPid[$pid] ?? '0', (string) $it['base_quantity']);
        }
        $newByPid = [];
        foreach ($newNormItems as $it) {
            $pid = (int) $it['product_id'];
            $newByPid[$pid] = money_add($newByPid[$pid] ?? '0', (string) $it['base_quantity']);
        }
        $allPids = array_values(array_unique(array_merge(array_keys($oldByPid), array_keys($newByPid))));
        $deltas = [];
        foreach ($allPids as $pid) {
            $deltas[(int) $pid] = money_sub($newByPid[$pid] ?? '0', $oldByPid[$pid] ?? '0');
        }

        /* کالاهای جزء بسته (snapshot تاریخی) */
        $oldAttached = bundleAttachSnapshots($mysqli, $oldItems, $kindMap);
        $newAttached = bundleAttachSnapshots($mysqli, $newItems, $kindMap);
        $oldCompReq = bundleComponentRequirementsFromItems($mysqli, $oldAttached);
        $newCompReq = bundleComponentRequirementsFromItems($mysqli, $newAttached);
        $compPids = array_unique(array_merge(array_keys($oldCompReq), array_keys($newCompReq)));
        foreach ($compPids as $cpid) {
            $cpid = (int) $cpid;
            $deltas[$cpid] = money_sub($newCompReq[$cpid] ?? '0', $oldCompReq[$cpid] ?? '0');
        }

        $taxInfo = sr_return_tax($mysqli, $inv);
        $totals = sr_compute_totals($newItems, $inv, $taxInfo);

        /* اعمال فقط دلتم موجودی */
        if (count($deltas) > 0) {
            $stocks = lockProductsById($mysqli, array_map('intval', array_keys($deltas)));
            foreach ($deltas as $pid => $delta) {
                if (money_cmp($delta, '0') === 0) {
                    continue;
                }
                $stocks[$pid] = applyStockChange(
                    $mysqli,
                    (int) $pid,
                    $stocks[$pid],
                    $delta,
                    null,
                    'sales_return',
                    'edit',
                    null,
                    $returnId
                );
            }
        }

        /* بازنویسی اقلام سند */
        $stmt = $mysqli->prepare('DELETE FROM sales_return_items WHERE return_id = ?');
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $stmt->close();
        sr_insert_return_items($mysqli, $returnId, $newItems);

        $stmt = $mysqli->prepare(
            'UPDATE sales_returns SET
                return_date = ?, subtotal = ?, discount = ?, tax_rate = ?, tax_amount = ?,
                payable_amount = ?, note = ?
             WHERE id = ?'
        );
        $vRetDate   = $in['return_date'];
        $vSubtotal2 = money_round($totals['subtotal']);
        $vDiscount2 = money_round($totals['discount']);
        $vTaxRate2  = money_round($taxInfo['tax_rate']);
        $vTaxAmount2 = money_round($totals['tax_amount']);
        $vPayable2  = money_round($totals['payable_amount']);
        $stmt->bind_param(
            'sdddddsi',
            $vRetDate,
            $vSubtotal2,
            $vDiscount2,
            $vTaxRate2,
            $vTaxAmount2,
            $vPayable2,
            $note,
            $returnId
        );
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
        return ['ok' => true, 'message' => 'برگشت فروش با موفقیت ویرایش شد.', 'return_id' => $returnId];
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'return_not_found') {
            $msg = 'سند برگشت فروش یافت نشد.';
        } elseif ($msg === 'invoice_mismatch') {
            $msg = 'فاکتور درخواستی با سند برگشت مطابقت ندارد.';
        } elseif ($msg === 'invoice_not_found') {
            $msg = 'فاکتور موردنظر یافت نشد.';
        } elseif ($msg === 'invoice_context') {
            $msg = 'فاکتور انتخابی برای برگشت معتبر نیست.';
        } elseif ($msg === 'negative_stock') {
            $msg = 'موجودی فعلی برای اعمال این ویرایش کافی نیست.';
        } elseif ($msg === 'product_not_found') {
            $msg = 'یکی از کالاهای برگشتی دیگر معتبر نیست.';
        }
        return ['ok' => false, 'message' => $msg];
    }
}
/* ============================================================
 * ۱۰) حذف سند برگشت (برعکس‌سازی موجودی + امنیت اجرای دوباره)
 * ========================================================== */

function deleteSalesReturn(mysqli $mysqli, int $returnId): array
{
    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare('SELECT * FROM sales_returns WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $ret = $res ? $res->fetch_assoc() : null;
        if (!$ret) {
            // اجرای دوباره: سند قبلاً حذف شده؛ برگشت موجودی دوم انجام نمی‌شود
            $mysqli->commit();
            return ['ok' => false, 'message' => 'سند برگشت فروش یافت نشد.'];
        }

        $invoiceId = (int) $ret['invoice_id'];

        $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $invoiceId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $inv = $res ? $res->fetch_assoc() : null;
        if (!$inv) {
            throw new Exception('invoice_not_found');
        }

        $items = [];
        $stmt = $mysqli->prepare('SELECT * FROM sales_return_items WHERE return_id = ?');
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        while ($res && ($row = $res->fetch_assoc())) {
            $items[] = $row;
        }

        /* معکوس‌سازی موجودی: stock -= base_quantity (با چک منفی) */
        foreach ($items as &$item) {
            $item['base_quantity'] = money_mul('-1', $item['base_quantity']);
        }
        unset($item);
        sr_apply_stock_for_items($mysqli, $items, $returnId, 'delete');

        $stmt = $mysqli->prepare('DELETE FROM sales_returns WHERE id = ?');
        $stmt->bind_param('i', $returnId);
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
        return ['ok' => true, 'message' => 'برگشت فروش با موفقیت حذف شد.', 'return_id' => $returnId];
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'invoice_not_found') {
            $msg = 'فاکتور مرتبط با این برگشت یافت نشد.';
        } elseif ($msg === 'negative_stock') {
            $msg = 'موجودی فعلی برای حذف این برگشت کافی نیست؛ عملیات لغو شد.';
        } elseif ($msg === 'product_not_found') {
            $msg = 'یکی از کالاهای برگشتی دیگر معتبر نیست.';
        }
        return ['ok' => false, 'message' => $msg];
    }
}
/* ============================================================
 * ۱۱) خواندن یک سند برگشت + جزئیات
 * ========================================================== */

function getSalesReturn(mysqli $mysqli, int $returnId): ?array
{
    $stmt = $mysqli->prepare(
        'SELECT sr.*, inv.invoice_number AS invoice_number, inv.invoice_date AS invoice_date,
                inv.payable_amount AS invoice_payable, inv.discount AS invoice_discount
         FROM sales_returns sr
         INNER JOIN invoices inv ON inv.id = sr.invoice_id
         WHERE sr.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $ret = $res ? $res->fetch_assoc() : null;
    if (!$ret) {
        return null;
    }

    $ret['return_date_shamsi'] = $ret['return_date'] ? jdate('j F Y', strtotime($ret['return_date'])) : '';

    $customer = null;
    if ((int) $ret['customer_id'] > 0) {
        $stmt = $mysqli->prepare('SELECT id, CONCAT(first_name," ",last_name) AS name, phone, national_code FROM customers WHERE id = ?');
        $custIdVar = (int) $ret['customer_id'];
        $stmt->bind_param('i', $custIdVar);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        $customer = $r ? $r->fetch_assoc() : null;
    }
    $ret['customer'] = $customer;

    $items = [];
    $stmt = $mysqli->prepare(
        'SELECT sri.*, p.code AS product_code, p.name AS product_name
         FROM sales_return_items sri
         LEFT JOIN products p ON p.id = sri.product_id
         WHERE sri.return_id = ?
         ORDER BY sri.id ASC'
    );
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = $row;
    }
    $ret['items'] = $items;
    return $ret;
}

/* ============================================================
 * ۱۲) فاکتورهای فروش قطعی (برای انتخاب در ساخت برگشت)
 * ========================================================== */

function sr_eligible_invoices(mysqli $mysqli, string $search): array
{
    $where = ["inv.type = 'sales_invoice'"];
    $types = '';
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(
            inv.invoice_number LIKE ?
            OR c.first_name LIKE ?
            OR c.last_name LIKE ?
            OR CONCAT(c.first_name, " ", c.last_name) LIKE ?
            OR c.phone LIKE ?
        )';
        $types .= 'sssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = 'SELECT inv.id, inv.invoice_number, inv.invoice_date, inv.payable_amount,
                   inv.payment_status, inv.payment_type,
                   CONCAT(c.first_name, " ", c.last_name) AS customer_name,
                   c.phone AS customer_phone
            FROM invoices inv
            LEFT JOIN customers c ON c.id = inv.customer_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY inv.id DESC
            LIMIT 50';

    if ($types !== '') {
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    } else {
        $res = $mysqli->query($sql);
    }

    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['invoice_date_shamsi'] = $row['invoice_date'] ? jdate('j F Y', strtotime($row['invoice_date'])) : '';
            $rows[] = $row;
        }
    }
    return $rows;
}
/* ============================================================
 * ۱۳) لیست سندهای برگشت با pagination سمت سرور
 * ========================================================== */

/**
 * @return array ['rows'=>..., 'total'=>int, 'page'=>int, 'per_page'=>int]
 */
function listSalesReturns(mysqli $mysqli, array $filter): array
{
    $search = trim((string) ($filter['search'] ?? ''));
    $page = max(1, (int) ($filter['page'] ?? 1));
    $perPage = max(1, min(15, (int) ($filter['per_page'] ?? 15)));

    $where = [];
    $types = '';
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(
            sr.return_number LIKE ?
            OR inv.invoice_number LIKE ?
            OR c.first_name LIKE ?
            OR c.last_name LIKE ?
            OR CONCAT(c.first_name, " ", c.last_name) LIKE ?
            OR c.phone LIKE ?
        )';
        $types .= 'ssssss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    /* تعداد کل */
    $countSql = 'SELECT COUNT(*) AS c
                 FROM sales_returns sr
                 INNER JOIN invoices inv ON inv.id = sr.invoice_id
                 LEFT JOIN customers c ON c.id = sr.customer_id' . $whereSql;
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

    /* محدودسازی صفحه خارج از دامنه معتبر → آخرین صفحه معتبر (یا ۱) */
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = ($page - 1) * $perPage;
    $sql = 'SELECT sr.id, sr.return_number, sr.invoice_id, sr.customer_id, sr.return_date,
                   sr.subtotal, sr.discount, sr.tax_rate, sr.tax_amount, sr.payable_amount, sr.note,
                   sr.created_at, sr.updated_at,
                   inv.invoice_number AS invoice_number,
                   (SELECT CONCAT(c2.first_name, " ", c2.last_name) FROM customers c2 WHERE c2.id = sr.customer_id) AS customer_name,
                   (SELECT c2.phone FROM customers c2 WHERE c2.id = sr.customer_id) AS customer_phone,
                   (SELECT COUNT(*) FROM sales_return_items sri2 WHERE sri2.return_id = sr.id) AS item_count
            FROM sales_returns sr
            INNER JOIN invoices inv ON inv.id = sr.invoice_id
            LEFT JOIN customers c ON c.id = sr.customer_id' . $whereSql . '
            ORDER BY sr.id DESC
            LIMIT ? OFFSET ?';

    $limit = $perPage;
    $offset2 = $offset;
    $stmt = $mysqli->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset2]));
    } else {
        $stmt->bind_param('ii', $limit, $offset2);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['return_date_shamsi'] = $row['return_date'] ? jdate('j F Y', strtotime($row['return_date'])) : '';
            $rows[] = $row;
        }
    }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}