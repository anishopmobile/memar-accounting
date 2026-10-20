<?php
/**
 * defective_goods_common.php — منطق مشترک ماژول کالاهای معیوب
 *
 * ترتیب بارگذاری: boot.php → db.php → invoice_common.php → defective_goods_common.php
 * متغیر سراسری $mysqli فرض می‌شود. تغییرات موجودی فقط از طریق applyStockChange (معماری متمرکز موجودی) انجام می‌شود.

 * تمام کوئری‌ها با prepared statement ساخته می‌شوند و هیچ ورودی کاربر مستقیماً
 * داخل SQL قرار نمی‌گیرد.
 */

require_once __DIR__ . '/invoice_common.php';

/**
 * اعتبارسنجی و نرمال‌سازی تاریخ میلادی به شکل Y-m-d
 */
function dg_parse_greg_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }
    if (!checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4))) {
        return null;
    }
    return $raw;
}

/**
 * استخراج و اعتبارسنجی فیلترهای بازه تاریخ گزارش
 *
 * @return array ['from' => ?string, 'to' => ?string] تاریخ میلادی Y-m-d
 */
function dg_extract_date_filters(array $get): array
{
    $from = isset($get['from']) ? dg_parse_greg_date((string) $get['from']) : null;
    $to   = isset($get['to'])   ? dg_parse_greg_date((string) $get['to'])   : null;

    /* اگر از تاریخ بزرگ‌تر از تا تاریخ باشد، جابه‌جا می‌کنیم تا بازه همیشه معتبر باشد */
    if ($from !== null && $to !== null && $from > $to) {
        $tmp  = $from;
        $from = $to;
        $to   = $tmp;
    }

    return ['from' => $from, 'to' => $to];
}

/**
 * ساخت شرط WHERE فیلتر تاریخ (فقط پارامترهای آماده)

 * @return array ['sql' => string, 'types' => string, 'params' => array]
 */
function dg_where_sql(array $filters): array
{
    $cond = [];
    $types = '';
    $params = [];

    if (!empty($filters['from'])) {
        $cond[] = 'dg.defective_date >= ?';
        $types .= 's';
        $params[] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $cond[] = 'dg.defective_date <= ?';
        $types .= 's';
        $params[] = $filters['to'];
    }

    return [
        'sql'    => $cond ? 'WHERE ' . implode(' AND ', $cond) : '',
        'types'  => $types,
        'params' => $params,
    ];
}

/**
 * اجرای کوئری آماده با پارامترهای اختیاری و بازگرداندن همه سطرها
 */
function dg_run_query(mysqli $mysqli, string $sql, string $types = '', array $params = []): array
{
    $rows = [];
    if ($types !== '') {
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
            }
        }
    } else {
        $res = $mysqli->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
    }
    return $rows;
}

/**
 * اجرای کوئری COUNT با پارامترهای اختیاری و بازگرداندن شمار
 */
function dg_run_count(mysqli $mysqli, string $sql, string $types = '', array $params = []): int
{
    $count = 0;
    if ($types !== '') {
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            $row = $res ? $res->fetch_assoc() : null;
            $count = $row ? (int) ($row['c'] ?? 0) : 0;
        }
    } else {
        $res = $mysqli->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
        $count = $row ? (int) ($row['c'] ?? 0) : 0;
    }
    return $count;
}

/**
 * خلاصه گزارش (تعداد رکوردها و جمع تعداد معیوب) — با احترام کامل به فیلتر تاریخ
 *
 * @return array ['records' => int, 'total_quantity' => string]
 */
function dg_summary(mysqli $mysqli, array $filters): array
{
    $w = dg_where_sql($filters);
    $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(dg.quantity), 0) AS tq
            FROM defective_goods dg {$w['sql']}";
    $rows = dg_run_query($mysqli, $sql, $w['types'], $w['params']);
    $row = $rows[0] ?? [];

    return [
        'records'         => (int) ($row['c'] ?? 0),
        'total_quantity' => money_round((string) ($row['tq'] ?? '0')),
    ];
}

/**
 * لیست صفحه‌بندی‌شده گزارش (سمت سرور؛ پیش‌فرض ۲۵ رکورد در هر صفحه)

 * @return array rows/total/totalPages/page/perPage/rangeStart/rangeEnd
 */
function dg_list(mysqli $mysqli, array $filters, int $page, int $perPage = 25): array
{
    $w = dg_where_sql($filters);

    $total = dg_run_count(
        $mysqli,
        "SELECT COUNT(*) AS c FROM defective_goods dg {$w['sql']}",
        $w['types'],
        $w['params']
    );

    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;

    }
    if ($page < 1) {
        $page = 1;
    }
    $offset = ($page - 1) * $perPage;


    $sql = "SELECT dg.id,dg.product_id,dg.quantity,dg.defective_date,dg.reason,dg.description,
                   p.name AS product_name,p.code AS product_code,p.barcode AS product_barcode,p.unit AS product_unit
            FROM defective_goods dg
            JOIN products p ON p.id = dg.product_id
            {$w['sql']}
            ORDER BY dg.defective_date DESC,dg.id DESC
            LIMIT ? OFFSET ?";

    $allTypes = $w['types'] . 'ii';
    $allParams = array_merge($w['params'], [$perPage, $offset]);
    $rows = dg_run_query($mysqli, $sql, $allTypes, $allParams);

    return [
        'rows'        => $rows,
        'total'       => $total,
        'totalPages' => $totalPages,
        'page'        => $page,
        'perPage'    => $perPage,
        'rangeStart' => $total === 0 ? 0 : $offset + 1,
        'rangeEnd'   => min($page * $perPage, $total),
    ];
}

/**
 * دریافت همه رکوردهای منطبق با فیلتر (برای خروجی اکسل — مستقل از صفحه‌بندی)
 */
function dg_all_for_export(mysqli $mysqli, array $filters): array
{
    $w = dg_where_sql($filters);
    $sql = "SELECT dg.id,dg.product_id,dg.quantity,dg.defective_date,dg.reason,dg.description,
                   p.name AS product_name,p.code AS product_code,p.barcode AS product_barcode,p.unit AS product_unit
            FROM defective_goods dg
            JOIN products p ON p.id = dg.product_id
            {$w['sql']}
            ORDER BY dg.defective_date DESC,dg.id DESC";

    return dg_run_query($mysqli, $sql, $w['types'], $w['params']);
}

/**
 * ثبت کالای معیوب — اعتبارسنجی کامل سمت سرور + تراکنش اتمیک.
 *
 * کاهش موجودی فقط از طریق applyStockChange (معماری متمرکز موجودی) اعمال می‌شود:
 * قفل ردیف (FOR UPDATE) داخل تراکنش از race condition جلوگیری می‌کند و موجودی
 * هرگز منفی نمی‌شود. رکورد تاریخی مستقل از فاکتور خرید ذخیره می‌شود و هیچ اثری
 * روی بدهی تامین‌کننده یا فاکتورهای موجود ندارد.
 *
 * @return array ['ok' => bool, 'message' => string, 'data' => array|null]
 */
function dg_register_defective(mysqli $mysqli, int $productId, string $qtyRaw, string $dateRaw, string $reason, string $description): array
{
    if ($productId <= 0) {
        return ['ok' => false, 'message' => 'کالای انتخاب‌شده نامعتبر است.'];
    }

    if ($qtyRaw === '' || !is_numeric($qtyRaw) || money_cmp($qtyRaw, '0') <= 0) {
        return ['ok' => false, 'message' => 'تعداد معیوب باید عددی بزرگ‌تر از صفر باشد.'];
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $qtyRaw)) {
        return ['ok' => false, 'message' => 'تعداد معیوب حداکثر می‌تواند دو رقم اعشار داشته باشد.'];
    }
    $qty = money_round($qtyRaw);

    $date = dg_parse_greg_date($dateRaw);
    if ($date === null) {
        return ['ok' => false, 'message' => 'تاریخ ثبت کالای معیوب نامعتبر است.'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'message' => 'دلیل ثبت کالای معیوب الزامی است.'];
    }
    if (mb_strlen($reason) > 500) {
        return ['ok' => false, 'message' => 'دلیل ثبت کالای معیوب حداکثر ۵۰۰ کاراکتر است.'];
    }

    $description = trim($description);
    if (mb_strlen($description) > 2000) {
        return ['ok' => false, 'message' => 'توضیحات حداکثر ۲۰۰۰ کاراکتر است.'];
    }

    /* کالا باید وجود داشته باشد و از نوع «محصول» (موجودی‌پذیر) باشد */
    $stmt = $mysqli->prepare('SELECT id, type FROM products WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $productRow = $res ? $res->fetch_assoc() : null;
    if (!$productRow) {
        return ['ok' => false, 'message' => 'کالای انتخاب‌شده یافت نشد.'];
    }
    if ($productRow['type'] !== 'product') {
        return ['ok' => false, 'message' => 'ثبت کالای معیوب فقط برای کالاها (محصولات) با موجودی فیزیکی امکان‌پذیر است.'];
    }

    $newStock = null;
    $defectiveId = 0;
    $mysqli->begin_transaction();
    try {
        /* قفل ردیف کالا (FOR UPDATE) و خواندن موجودی به‌عنوان منبع حقیقت */
        $stocks = lockProductsById($mysqli, [$productId]);
        $stock = $stocks[$productId] ?? '0';

        if (money_cmp($qty, $stock) > 0) {
            throw new Exception('defective_exceeds_stock');
        }

        $stmt = $mysqli->prepare(
            'INSERT INTO defective_goods (product_id, quantity, defective_date, reason, description) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('idsss', $productId, $qty, $date, $reason, $description);
        $stmt->execute();
        $defectiveId = (int) $stmt->insert_id;
        $stmt->close();

        /* کاهش موجودی + ثبت گردش موجودی (stock_movements) — همان معماری موجود پروژه */
        $newStock = applyStockChange(
            $mysqli,
            $productId,
            $stock,
            money_mul('-1', $qty),
            null,
            'defective_goods',
            'create'
        );

        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'product_not_found') {
            return ['ok' => false, 'message' => 'کالای انتخاب‌شده یافت نشد.'];
        }
        if ($msg === 'defective_exceeds_stock' || $msg === 'negative_stock') {
            return ['ok' => false, 'message' => 'تعداد معیوب بیشتر از موجودی فعلی کالا است.'];
        }
        return ['ok' => false, 'message' => $msg];
    }

    return [
        'ok' => true,
        'message' => 'کالای معیوب با موفقیت ثبت شد و موجودی کالا کاهش یافت.',
        'data' => [
            'defective_id' => $defectiveId,
            'quantity'      => $qty,
            'stock_before' => $stock,
            'stock_after'  => $newStock,
        ],
    ];
}
/**
 * اعتبارسنجی و نرمال‌سازی تعداد (عدد مثبت با حداکثر دو رقم اعشار).
 *
 * @return string|null مقدار نرمال‌شده یا null در صورت نامعتبر بودن
 */
function dg_parse_quantity(string $qtyRaw): ?string
{
    $qtyRaw = trim($qtyRaw);
    if ($qtyRaw === '' || !is_numeric($qtyRaw) || money_cmp($qtyRaw, '0') <= 0) {
        return null;
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $qtyRaw)) {
        return null;
    }
    return money_round($qtyRaw);
}

/**
 * برگشت کالای معیوب (ترمیم‌شده) به موجودی — اعتبارسنجی کامل سمت سرور + تراکنش اتمیک.
 *
 * افزایش موجودی فقط از طریق applyStockChange (معماری متمرکز موجودی) اعمال می‌شود.
 * قفل ردیف معیوب (SELECT ... FOR UPDATE) به‌همراه قفل ردیف کالا (lockProductsById)
 * از همزمانی/بازگشت بیش از حد مقدار جلوگیری می‌کند؛ منبع حقیقت همواره دیتابیس است.
 *
 * این عملیات صرفاً «بازگشت به موجودی» است و هیچ فاکتور، بدهی، پرداخت، قیمت یا
 * سند مالی دیگری را تغییر نمی‌دهد. در صورت رسیدن مقدار معیوب به صفر، رکورد حذف
 * می‌شود؛ تاریخچه عملیات در stock_movements (گردش defective و defective_return)
 * حفظ می‌شود و هیچ جدول دیگری به defective_goods ارجاع ندارد.
 *
 * @return array ['ok' => bool, 'message' => string, 'data' => array|null]
 */
function dg_return_defective(mysqli $mysqli, int $defectiveId, string $qtyRaw): array
{
    if ($defectiveId <= 0) {
        return ['ok' => false, 'message' => 'شناسه کالای معیوب نامعتبر است.'];
    }

    $qty = dg_parse_quantity($qtyRaw);
    if ($qty === null) {
        return ['ok' => false, 'message' => 'تعداد برگشتی باید عددی بزرگ‌تر از صفر با حداکثر دو رقم اعشار باشد.'];
    }

    $remaining   = null;
    $newStock    = null;
    $stockBefore = null;

    $mysqli->begin_transaction();
    try {
        /* ۱) قفل رکورد معیوب و خواندن مقدار فعلی به‌عنوان منبع حقیقت */
        $stmt = $mysqli->prepare('SELECT id, product_id, quantity FROM defective_goods WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $defectiveId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            throw new Exception('defective_not_found');
        }
        $productId  = (int) $row['product_id'];
        $currentQty = money_round((string) $row['quantity']);

        /* ۲) مقدار درخواستی نباید از مقدار معیوب باقی‌مانده بیشتر باشد */
        if (money_cmp($qty, $currentQty) > 0) {
            throw new Exception('defective_return_exceeds');
        }

        /* ۳) قفل ردیف کالا (FOR UPDATE) و خواندن موجودی به‌عنوان منبع حقیقت */
        $stocks      = lockProductsById($mysqli, [$productId]);
        $stockBefore = $stocks[$productId] ?? '0';

        /* ۴) افزایش موجودی + ثبت گردش موجودی (نوع defective_return / جهت in) */
        $newStock = applyStockChange(
            $mysqli,
            $productId,
            $stockBefore,
            $qty,
            null,
            'defective_goods',
            'return'
        );

        /* ۵) کاهش مقدار معیوب یا حذف رکورد در صورت برگشت کامل */
        $remaining = money_sub($currentQty, $qty);
        if (money_cmp($remaining, '0') === 0) {
            $stmt = $mysqli->prepare('DELETE FROM defective_goods WHERE id = ?');
            $stmt->bind_param('i', $defectiveId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare('UPDATE defective_goods SET quantity = ? WHERE id = ?');
            $stmt->bind_param('di', $remaining, $defectiveId);
            $stmt->execute();
            $stmt->close();
        }

        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        $errors = [
            'defective_not_found'       => 'کالای معیوب موردنظر یافت نشد.',
            'defective_return_exceeds'  => 'تعداد درخواستی بیشتر از مقدار معیوب باقی‌مانده است.',
            'product_not_found'         => 'کالای مرتبط با کالای معیوب یافت نشد.',
            'negative_stock'            => 'موجودی کالا منفی شد؛ عملیات لغو شد.',
        ];
        return ['ok' => false, 'message' => $errors[$msg] ?? $msg];
    }

    return [
        'ok' => true,
        'message' => 'کالای معیوب با موفقیت به موجودی بازگردانده شد.',
        'data' => [
            'defective_id'   => $defectiveId,
            'returned_qty'   => $qty,
            'remaining_qty'  => money_round($remaining),
            'product_id'     => $productId,
            'stock_before'   => money_round($stockBefore),
            'stock_after'    => $newStock,
            'fully_returned' => money_cmp($remaining, '0') === 0,
        ],
    ];
}

/**
 * داده JSON گزارش کالاهای معیوب (بدون رندر HTML) برای به‌روزرسانی‌های AJAX.
 *
 * از همان dg_summary/dg_list استفاده می‌شود تا سرور همچنان منبع حقیقت و
 * فیلتر تاریخ/صفحه‌بندی دقیقاً مشابه صفحه اولیه اعمال شود.
 */
function dg_report_data(mysqli $mysqli, array $filters, int $page, int $perPage = 25): array
{
    $summary = dg_summary($mysqli, $filters);
    $list    = dg_list($mysqli, $filters, $page, $perPage);

    $rows  = [];
    $rowNo = $list['rangeStart'];
    foreach ($list['rows'] as $r) {
        $rows[] = [
            'id'                    => (int) $r['id'],
            'row_no'                => $rowNo++,
            'defective_date'        => (string) $r['defective_date'],
            'defective_date_jalali' => (string) $r['defective_date'] !== ''
                ? jdate('Y/m/d', strtotime((string) $r['defective_date'])) : '',
            'product_id'            => (int) $r['product_id'],
            'product_name'          => (string) $r['product_name'],
            'product_code'          => (string) $r['product_code'],
            'product_barcode'       => $r['product_barcode'] !== null && (string) $r['product_barcode'] !== ''
                ? (string) $r['product_barcode'] : '-',
            'product_unit'          => (string) ($r['product_unit'] ?? ''),
            'quantity'              => (string) $r['quantity'],
            'quantity_display'      => number_format((float) $r['quantity']),
            'reason'                => (string) $r['reason'],
            'description'           => (string) ($r['description'] ?? ''),
        ];
    }

    return [
        'rows'        => $rows,
        'summary'     => $summary,
        'total'       => $list['total'],
        'totalPages'  => $list['totalPages'],
        'page'        => $list['page'],
        'perPage'     => $list['perPage'],
        'rangeStart'  => $list['rangeStart'],
        'rangeEnd'    => $list['rangeEnd'],
        'filters'     => $filters,
    ];
}