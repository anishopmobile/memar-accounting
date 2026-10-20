<?php
/**
 * product_history_common.php — محاسبات «تاریخچه قیمت و فروش» یک کالا
 *
 * ترتیب بارگذاری: db.php → invoice_common.php → product_history_common.php
 *
 * — منبع تاریخی قیمت فروش:
 *   سیستم جدولی برای «تاریخچه قیمت» ندارد. تنها منبع مطمئن قیمت‌های تاریخی،
 *   قیمت واحد ثبت‌شده در اقلام فاکتورهای فروش قطعی (invoice_items.unit_price)
 *   است. این فایل دوره‌های قیمتی را از ترتیب زمانی همان اقلام استخراج می‌کند؛
 *   با تغییر قیمت بین دو فروش متوالی، دوره جدیدی آغاز می‌شود.
 *
 * — فروش معتبر (بر اساس قواعد موجود برنامه):
 *   فقط فاکتورهای قطعی فروش (sales_invoice) به‌عنوان فروش معتبر لحاظ می‌شوند؛
 *   پیش‌فاکتور (sales_proforma) هرگز موجودی را تغییر نمی‌دهد و جزو فروش قطعی نیست.
 *   فاکتور حذف‌شده همراه اقلام خود به‌صورت CASCADE حذف می‌شود و دیگر در تاریخ نیست.
 *   برگشت فروش (در صورت وجود) از مقدار هر قلم فاکتور جدا کم می‌شود.
 *
 * — واحد:
 *   تعداد فروش در واحد پایه (base_quantity) و قیمت واحد معادل واحد پایه
 *   (قیمت واحد فروش ÷ ضریب تبدیل) محاسبه می‌شود تا همه‌چیز قابل مقایسه باشد.
 */

require_once __DIR__ . '/invoice_common.php';

/**
 * بارگذاری ردیف کالا
 */
function ph_load_product(mysqli $mysqli, int $productId): ?array
{
    $stmt = $mysqli->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res ? $res->fetch_assoc() : null;
}

/**
 * موجودی افتتاحی: stock_before اولین گردش موجودی کالا.
 * اگر هیچ گردشی ثبت نشده باشد، موجودی فعلی به‌عنوان موجودی اولیه برگردانده می‌شود.
 */
function ph_opening_quantity(mysqli $mysqli, int $productId, string $currentStock): string
{
    $stmt = $mysqli->prepare(
        'SELECT stock_before FROM stock_movements WHERE product_id = ? ORDER BY id ASC LIMIT 1'
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res && $row = $res->fetch_assoc()) {
        return money_round($row['stock_before']);
    }
    return money_round($currentStock);
}

/**
 * مجموع تعداد کالاهای معیوب ثبت‌شده برای کالا (واحد پایه).
 */
function ph_defective_quantity(mysqli $mysqli, int $productId): string
{
    $stmt = $mysqli->prepare(
        'SELECT COALESCE(SUM(quantity), 0) AS total FROM defective_goods WHERE product_id = ?'
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res && $row = $res->fetch_assoc()) {
        return money_round($row['total']);
    }
    return money_round('0');
}
/**
 * اقلام فروش قطعی کالا به‌ترتیب زمان + مقدار برگشتی هر قلم.
 *
 * @return array rows
 */
function ph_sales_rows(mysqli $mysqli, int $productId): array
{
    $rows = [];
    $stmt = $mysqli->prepare("
        SELECT i.invoice_date, i.id AS invoice_id, i.invoice_number,
               ii.id AS item_id, ii.quantity, ii.base_quantity, ii.unit_price,
               ii.unit_name, ii.conversion_factor, ii.created_at
        FROM invoice_items ii
        JOIN invoices i ON i.id = ii.invoice_id
        WHERE ii.product_id = ? AND i.type = 'sales_invoice'
        ORDER BY i.invoice_date ASC, i.id ASC, ii.created_at ASC, ii.id ASC
    ");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }

    /* مقدار برگشتی هر قلم فاکتور (بر اساس invoice_item_id) */
    $returnedMap = [];
    if (count($rows) > 0) {
        $stmt = $mysqli->prepare("
            SELECT sri.invoice_item_id, COALESCE(SUM(sri.base_quantity), 0) AS returned_base
            FROM sales_return_items sri
            JOIN invoice_items ii ON ii.id = sri.invoice_item_id
            WHERE ii.product_id = ?
            GROUP BY sri.invoice_item_id
        ");
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $res2 = $stmt->get_result();
        $stmt->close();
        if ($res2) {
            while ($row = $res2->fetch_assoc()) {
                $returnedMap[(int) $row['invoice_item_id']] = (string) $row['returned_base'];
            }
            $res2->free();
        }
    }

    foreach ($rows as &$r) {
        $factor = money_round((string) ($r['conversion_factor'] ?? '1'), 4);
        if (money_cmp($factor, '0') <= 0) {
            $factor = '1';
        }
        $baseQty = money_round((string) ($r['base_quantity'] ?? $r['quantity']));
        $returned = $returnedMap[(int) $r['item_id']] ?? '0';
        $r['net_base_qty'] = money_sub($baseQty, $returned);
        /* قیمت واحد معادل واحد پایه = قیمت واحد فروش ÷ ضریب تبدیل */
        $r['base_price'] = money_round(money_div((string) $r['unit_price'], $factor));
        unset($r);
    }

    return $rows;
}

/**
 * قالب‌بندی تعداد (حذف صفرهای اضافی اعشار)
 */
function ph_fmt_qty($v): string
{
    return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
}

/**
 * قالب‌بندی مبلغ (همان قالب برنامه: هزارگان با کاما و بدون اعشار اضافی)
 */
function ph_fmt_money($v): string
{
    return number_format((float) $v);
}
/**
 * ساخت توضیحات فارسی پویا بر اساس داده‌های واقعی محاسبه‌شده.
 */
function ph_build_explanation(
    string $unitName,
    string $opening,
    array $periods,
    string $defective,
    string $currentStock,
    string $currentPrice,
    ?string $lastSoldPrice
): string
{
    $lines = [];
    $unit = ($unitName !== '') ? (' ' . $unitName) : '';

    $lines[] = 'موجودی اولیه این کالا طبق گردش موجودی «' . ph_fmt_qty($opening) . $unit . '» ثبت شده است.';

    if (count($periods) === 0) {
        $lines[] = 'برای این کالا هیچ فاکتور فروش قطعی ثبت نشده است؛ بنابراین دوره قیمتی تاریخی قابل استخراج نیست.';
    } else {
        $total = count($periods);
        foreach ($periods as $idx => $p) {
            $price = ph_fmt_money($p['price']);
            $qty = ph_fmt_qty($p['qty']);
            $from = (string) $p['from_shamsi'];
            if ($total === 1) {
                $lines[] = 'فروش این کالا از تاریخ ' . $from . ' با قیمت واحد ' . $price . ' ریال ثبت شده است و از آن تاریخ تاکنون ' . $qty . $unit . ' فروخته شده است.';
            } elseif ($idx === 0) {
                $lines[] = 'از تاریخ ' . $from . ' قیمت واحد این کالا ' . $price . ' ریال ثبت شده است؛ تا قبل از تغییر قیمت بعدی ' . $qty . $unit . ' فروخته شده است.';
            } elseif ($idx === $total - 1) {
                $lines[] = 'در تاریخ ' . $from . ' قیمت واحد کالا به ' . $price . ' ریال تغییر کرده است و از آن تاریخ تاکنون ' . $qty . $unit . ' فروخته شده است.';
            } else {
                $lines[] = 'در تاریخ ' . $from . ' قیمت واحد کالا به ' . $price . ' ریال تغییر کرده است؛ تا قبل از تغییر قیمت بعدی ' . $qty . $unit . ' فروخته شده است.';
            }
        }
    }

    if (money_cmp($defective, '0') > 0) {
        $lines[] = 'کالاهای معیوب ثبت‌شده برای این کالا ' . ph_fmt_qty($defective) . $unit . ' است.';
    }

    $lines[] = 'موجودی فعلی کالا ' . ph_fmt_qty($currentStock) . $unit . ' است.';

    if ($lastSoldPrice !== null && money_cmp($lastSoldPrice, $currentPrice) !== 0) {
        $lines[] = 'توجه: آخرین قیمت ثبت‌شده در فاکتورهای فروش (' . ph_fmt_money($lastSoldPrice) . ' ریال) با قیمت فروش فعلی (' . ph_fmt_money($currentPrice) . ' ریال) متفاوت است؛ تاریخ دقیق این تغییر قیمت در سیستم ثبت نشده است.';
    } else {
        $lines[] = 'قیمت فروش فعلی کالا ' . ph_fmt_money($currentPrice) . ' ریال است.';
    }

    return implode("\n", $lines);
}
/**
 * ساخت داده کامل تاریخچه قیمت و فروش یک کالا.
 *
 * @return ?array null اگر کالا یافت نشود
 */
function buildProductHistory(mysqli $mysqli, int $productId): ?array
{
    $product = ph_load_product($mysqli, $productId);
    if ($product === null) {
        return null;
    }

    $baseUnit = getBaseUnit($mysqli, $productId);
    $unitName = (string) (($baseUnit['name'] ?? '') !== '' ? $baseUnit['name'] : ($product['unit'] ?? 'عدد'));

    $currentStock = money_round((string) ($product['stock'] ?? '0'));
    $opening = ph_opening_quantity($mysqli, $productId, $currentStock);
    $defective = ph_defective_quantity($mysqli, $productId);
    $sales = ph_sales_rows($mysqli, $productId);

    /* ---------- دوره‌های قیمتی (تغییر قیمت بین فروش‌های متوالی) ---------- */
    $periods = [];
    $totalSold = money_round('0');
    foreach ($sales as $s) {
        if (money_cmp((string) $s['net_base_qty'], '0') <= 0) {
            continue; /* قلم کاملاً برگشتی یا بی‌اثر */
        }
        $lastIdx = count($periods) - 1;
        if ($lastIdx < 0 || money_cmp((string) $s['base_price'], $periods[$lastIdx]['price']) !== 0) {
            $periods[] = [
                'from'             => (string) $s['invoice_date'],
                'from_shamsi'      => (string) $s['invoice_date'] !== ''
                    ? jdate('j F Y', strtotime((string) $s['invoice_date']))
                    : '',
                'from_shamsi_short' => (string) $s['invoice_date'] !== ''
                    ? jdate('Y/m/d', strtotime((string) $s['invoice_date']))
                    : '',
                'price'            => (string) $s['base_price'],
                'qty'              => money_round('0'),
            ];
            $lastIdx++;
        }
        $periods[$lastIdx]['qty'] = money_add($periods[$lastIdx]['qty'], (string) $s['net_base_qty']);
        $totalSold = money_add($totalSold, (string) $s['net_base_qty']);
    }

    $currentPrice = money_round((string) ($product['sale_price'] ?? '0'));
    $lastSoldPrice = count($periods) > 0 ? (string) $periods[count($periods) - 1]['price'] : null;

    $createdShamsi = '';
    if (!empty($product['created_at'])) {
        $createdShamsi = jdate('j F Y', strtotime((string) $product['created_at']));
    }

    return [
        'product' => [
            'id'                 => (int) $product['id'],
            'code'               => (string) ($product['code'] ?? ''),
            'name'               => (string) ($product['name'] ?? ''),
            'type'               => (string) ($product['type'] ?? 'product'),
            'unit'               => $unitName,
            'sale_price'         => $currentPrice,
            'purchase_price'     => money_round((string) ($product['purchase_price'] ?? '0')),
            'stock'              => $currentStock,
            'created_at'         => (string) ($product['created_at'] ?? ''),
            'created_at_shamsi'  => $createdShamsi,
        ],
        'unit_name'              => $unitName,
        'opening_quantity'       => $opening,
        'defective_quantity'     => $defective,
        'total_sold'             => $totalSold,
        'current_price'          => $currentPrice,
        'last_sold_price'        => $lastSoldPrice,
        'price_changed_after_last_sale' => $lastSoldPrice !== null && money_cmp($lastSoldPrice, $currentPrice) !== 0,
        'periods'                => $periods,
        'explanation'            => ph_build_explanation(
            $unitName,
            $opening,
            $periods,
            $defective,
            $currentStock,
            $currentPrice,
            $lastSoldPrice
        ),
    ];
}