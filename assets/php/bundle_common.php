<?php

/**
 * bundle_common.php — منطق مشترک ماژول بسته‌بندی کالا (Product Bundling)
 *
 * یک «بسته» یک کالای مجازی از جنس محصول است (products.type='product' و
 * products.product_kind='bundle') که از ترکیب چند کالای استانداردِ
 * موجودی‌دار ساخته می‌شود.
 *
 * قواعد:
 *  - موجودی بسته «مشتق‌شده» است و هرگز به‌صورت مستقل معتبر نیست
 *      availability = MIN( floor(component.stock / component.quantity) )
 *  - قیمت خرید/فروش محاسبه‌شده = Σ(component.price × component.quantity)
 *  - مصرف موجودی در فروش، روی کالاهای جزء انجام می‌شود (نه روی خود بسته)
 *  - برای هر ردیف فاکتور، ترکیب بسته به‌صورت snapshot تاریخی نگه‌داری می‌شود
 *    (invoice_bundle_items) تا ویرایش/حذف/تبدیل/برگشت به ترکیب فعلی وابسته نباشد.
 *
 * ترتیب بارگذاری: boot.php → db.php → invoice_common.php → bundle_common.php
 */

require_once __DIR__ . '/invoice_common.php';

define('PRODUCT_KIND_STANDARD', 'standard');
define('PRODUCT_KIND_BUNDLE', 'bundle');
define('BUNDLE_UNIT_NAME', 'بسته');

/* ============================================================
 * ۱) تشخیص نوع کالا (standard | bundle)
 * ========================================================== */

function isBundleKind($kind): bool
{
    return (string) $kind === PRODUCT_KIND_BUNDLE;
}

function isBundleRow(array $row): bool
{
    return isBundleKind($row['product_kind'] ?? PRODUCT_KIND_STANDARD);
}

/**
 * نگاشت نوع کالاها (standard|bundle) برای مجموعه‌ای از شناسه‌ها.
 *
 * @return array<int,string>
 */
function bundleKindMap(mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $map = [];
    if (!$ids) {
        return $map;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $mysqli->prepare("SELECT id, product_kind FROM products WHERE id IN ({$in})");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $map[(int) $row['id']] = (string) ($row['product_kind'] ?? PRODUCT_KIND_STANDARD);
        }
        $res->free();
    }
    return $map;
}

/**
 * آیا این شناسه یک محصول از نوع «بسته» است؟
 */
function isBundleProduct(mysqli $mysqli, int $productId): bool
{
    $stmt = $mysqli->prepare('SELECT product_kind FROM products WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $row = $res ? $res->fetch_assoc() : null;
    return $row !== null && isBundleKind($row['product_kind'] ?? PRODUCT_KIND_STANDARD);
}

/* ============================================================
 * ۲) ترکیب بسته و محاسبات مشتق‌شده
 * ========================================================== */

/**
 * ترکیب فعلی یک بسته همراه با اطلاعات کالای جزء.
 * منبع حقیقت: جدول bundle_components + products.
 *
 * @return array<int,array>
 */
function getBundleComponents(mysqli $mysqli, int $bundleId): array
{
    $rows = [];
    if ($bundleId <= 0) {
        return $rows;
    }
    $stmt = $mysqli->prepare(
        'SELECT bc.component_product_id, bc.quantity,
                p.code, p.barcode, p.name, p.unit, p.type, p.product_kind,
                p.stock, p.purchase_price, p.sale_price
         FROM bundle_components bc
         INNER JOIN products p ON p.id = bc.component_product_id
         WHERE bc.bundle_product_id = ?
         ORDER BY bc.id ASC'
    );
    $stmt->bind_param('i', $bundleId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

/**
 * نگاشت ترکیب برای چند بسته (batch؛ برای پرهیز از N+1).
 *
 * @return array<int,array<int,array>> map bundleId => components[]
 */
function getBundleComponentsMap(mysqli $mysqli, array $bundleIds): array
{
    $ids = array_values(array_unique(array_map('intval', $bundleIds)));
    $map = [];
    if (!$ids) {
        return $map;
    }
    foreach ($ids as $id) {
        $map[$id] = [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $mysqli->prepare(
        "SELECT bc.bundle_product_id, bc.component_product_id, bc.quantity,
                p.code, p.barcode, p.name, p.unit, p.stock, p.purchase_price, p.sale_price
         FROM bundle_components bc
         INNER JOIN products p ON p.id = bc.component_product_id
         WHERE bc.bundle_product_id IN ({$in})
         ORDER BY bc.id ASC"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $bid = (int) $row['bundle_product_id'];
            if (isset($map[$bid])) {
                $map[$bid][] = $row;
            }
        }
        $res->free();
    }
    return $map;
}

/**
 * موجودی مشتق‌شده یک بسته از روی ترکیب:
 *   MIN( floor(component.stock / component.quantity) )
 * اگر بسته بدون جزء باشد یا موجودی هر جزئی صفر/منفی باشد → ۰
 *
 * @param array<int,array> $components
 */
function bundleAvailabilityFromComponents(array $components): string
{
    if (count($components) === 0) {
        return '0';
    }
    $min = null;
    foreach ($components as $c) {
        $qty = (string) ($c['quantity'] ?? '0');
        if (money_cmp($qty, '0') <= 0) {
            return '0';
        }
        $stock = (string) ($c['stock'] ?? '0');
        if (money_cmp($stock, '0') <= 0) {
            return '0';
        }
        $n = money_round(money_div($stock, $qty, 0), 0); // bcdiv → floor برای مقادیر مثبت
        if ($min === null || money_cmp($n, $min) < 0) {
            $min = $n;
        }
    }
    return $min === null ? '0' : $min;
}

/**
 * موجودی مشتق‌شده یک بسته از دیتابیس.
 */
function getBundleAvailability(mysqli $mysqli, int $bundleId): string
{
    return bundleAvailabilityFromComponents(getBundleComponents($mysqli, $bundleId));
}

/**
 * موجودی مشتق‌شده چند بسته (batch).
 *
 * @return array<int,string> map bundleId => availability
 */
function getBundleStockMap(mysqli $mysqli, array $bundleIds): array
{
    $ids = array_values(array_unique(array_map('intval', $bundleIds)));
    if (!$ids) {
        return [];
    }
    $componentsMap = getBundleComponentsMap($mysqli, $ids);
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = bundleAvailabilityFromComponents($componentsMap[$id] ?? []);
    }
    return $out;
}

/**
 * قیمت خرید/فروش محاسبه‌شده = Σ(component.price × component.quantity)
 *
 * @param array<int,array> $components
 * @return array{purchase:string,sale:string}
 */
function bundleCalculatedPricesFromComponents(array $components): array
{
    $purchase = '0';
    $sale = '0';
    foreach ($components as $c) {
        $qty = (string) ($c['quantity'] ?? '0');
        $purchase = money_add($purchase, money_mul((string) ($c['purchase_price'] ?? '0'), $qty));
        $sale = money_add($sale, money_mul((string) ($c['sale_price'] ?? '0'), $qty));
    }
    return ['purchase' => money_round($purchase), 'sale' => money_round($sale)];
}

/**
 * قیمت‌های محاسبه‌شده یک بسته از دیتابیس.
 *
 * @return array{purchase:string,sale:string}
 */
function getBundleCalculatedPrices(mysqli $mysqli, int $bundleId): array
{
    return bundleCalculatedPricesFromComponents(getBundleComponents($mysqli, $bundleId));
}

/* ============================================================
 * ۳) گسترش اقلام به نیازمندی جزئی (consume/reverse engine)
 * ========================================================== */

/**
 * تبدیل مجموعه‌ای از اقلام به نیازمندی موجودیِ کالاهای جزء.
 *
 * هر آیتم:
 *   product_id     : شناسه کالا (ممکن است بسته باشد)
 *   base_quantity  : تعداد بر حسب واحد پایه
 *   components     : (اختیاری) ترکیب بسته؛ در صورت نبود از ترکیب فعلی دیتابیس خوانده می‌شود
 *
 * برای کالای عادی: pid => base_quantity
 * برای بسته: هر جزء => component_quantity × base_quantity
 *
 * @return array<int,string> map componentPid => qty
 */
function bundleRequirementsFromItems(mysqli $mysqli, array $items, array $kindMap = []): array
{
    if (!$items) {
        return [];
    }
    $pids = array_map('intval', array_column($items, 'product_id'));
    if (!$kindMap) {
        $kindMap = bundleKindMap($mysqli, $pids);
    }

    /* ترکیب مورد استفاده برای بسته‌هایی که components صریح ندارند */
    $neededBundles = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if (($kindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE && !isset($it['components'])) {
            $neededBundles[] = $pid;
        }
    }
    $currentComponents = $neededBundles ? getBundleComponentsMap($mysqli, $neededBundles) : [];

    $requirements = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $baseQty = (string) ($it['base_quantity'] ?? $it['quantity'] ?? '0');
        if (money_cmp($baseQty, '0') === 0) {
            continue;
        }
        if (($kindMap[$pid] ?? PRODUCT_KIND_STANDARD) !== PRODUCT_KIND_BUNDLE) {
            $requirements[$pid] = money_add($requirements[$pid] ?? '0', $baseQty);
            continue;
        }
        $components = $it['components'] ?? ($currentComponents[$pid] ?? []);
        foreach ($components as $c) {
            $cPid = (int) ($c['component_product_id'] ?? 0);
            if ($cPid <= 0) {
                continue;
            }
            $cQty = (string) ($c['component_quantity'] ?? $c['quantity'] ?? '0');
            if (money_cmp($cQty, '0') <= 0) {
                continue;
            }
            $requirements[$cPid] = money_add($requirements[$cPid] ?? '0', money_mul($cQty, $baseQty));
        }
    }
    return $requirements;
}

/**
 * نیازمندی موجودیِ فقط کالاهای جزءِ اقلام بسته.
 * (کالاهای عادی در خروجی نیستند تا منطق موجود آن‌ها دست‌نخورده بماند.)
 *
 * @return array<int,string> map componentPid => qty
 */
function bundleComponentRequirementsFromItems(mysqli $mysqli, array $items): array
{
    if (!$items) {
        return [];
    }
    $kindMap = bundleKindMap($mysqli, array_column($items, 'product_id'));
    $bundleItems = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if (($kindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
            $bundleItems[] = $it;
        }
    }
    if (!$bundleItems) {
        return [];
    }
    return bundleRequirementsFromItems($mysqli, $bundleItems, $kindMap);
}

/* ============================================================
 * ۴) snapshot تاریخی ترکیب بسته (invoice_bundle_items)
 * ========================================================== */

/**
 * snapshot تاریخی چند ردیف فاکتور (batch).
 *
 * @return array<int,array> map invoice_item_id => rows
 */
function getInvoiceBundleSnapshots(mysqli $mysqli, array $invoiceItemIds): array
{
    $ids = array_values(array_unique(array_map('intval', $invoiceItemIds)));
    $map = [];
    if (!$ids) {
        return $map;
    }
    foreach ($ids as $id) {
        $map[$id] = [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $mysqli->prepare(
        "SELECT invoice_item_id, bundle_product_id, component_product_id, component_quantity
         FROM invoice_bundle_items
         WHERE invoice_item_id IN ({$in})
         ORDER BY id ASC"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $iid = (int) $row['invoice_item_id'];
            if (isset($map[$iid])) {
                $map[$iid][] = $row;
            }
        }
        $res->free();
    }
    return $map;
}

/**
 * snapshot تاریخی یک ردیف فاکتور (اگر بسته بوده).
 *
 * @return array<int,array>
 */
function getInvoiceItemBundleSnapshot(mysqli $mysqli, int $invoiceItemId): array
{
    $map = getInvoiceBundleSnapshots($mysqli, [$invoiceItemId]);
    return $map[$invoiceItemId] ?? [];
}

/**
 * ذخیره snapshot ترکیب یک ردیف فاکتور بسته.
 *
 * @param array $components list of ['component_product_id'=>int,'quantity'=>string]
 */
function insertInvoiceBundleSnapshot(mysqli $mysqli, int $invoiceItemId, int $bundleProductId, array $components): void
{
    if (count($components) === 0) {
        return;
    }
    $stmt = $mysqli->prepare(
        'INSERT INTO invoice_bundle_items
            (invoice_item_id, bundle_product_id, component_product_id, component_quantity)
         VALUES (?, ?, ?, ?)'
    );
    foreach ($components as $c) {
        $cPid = (int) ($c['component_product_id'] ?? 0);
        $qty = money_round((string) ($c['quantity'] ?? $c['component_quantity'] ?? '0'));
        if ($cPid <= 0 || money_cmp($qty, '0') <= 0) {
            continue;
        }
        $iid = $invoiceItemId;
        $bid = $bundleProductId;
        $stmt->bind_param('iiid', $iid, $bid, $cPid, $qty);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * اتصال snapshot تاریخی به اقلام فاکتور (برای برگشت/حذف/تبدیل).
 * هر آیتمی که بسته باشد و invoice_item_id داشته باشد، کلید components
 * با ترکیب تاریخیِ همان ردیف پر می‌شود.
 *
 * @param array $items list of ['product_id'=>int,'base_quantity'=>string,'invoice_item_id'=>int|null,...]
 * @return array همان اقلام با کلید components (در صورت وجود snapshot)
 */
function bundleAttachSnapshots(mysqli $mysqli, array $items, array $kindMap = []): array
{
    if (!$items) {
        return $items;
    }
    $pids = array_map('intval', array_column($items, 'product_id'));
    if (!$kindMap) {
        $kindMap = bundleKindMap($mysqli, $pids);
    }
    $iiIds = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $iiId = (int) ($it['invoice_item_id'] ?? ($it['id'] ?? 0));
        if (($kindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE && $iiId > 0) {
            $iiIds[] = $iiId;
        }
    }
    $snapMap = $iiIds ? getInvoiceBundleSnapshots($mysqli, $iiIds) : [];

    foreach ($items as &$it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if (($kindMap[$pid] ?? PRODUCT_KIND_STANDARD) !== PRODUCT_KIND_BUNDLE) {
            continue;
        }
        $iiId = (int) ($it['invoice_item_id'] ?? ($it['id'] ?? 0));
        if ($iiId > 0 && !empty($snapMap[$iiId])) {
            $it['components'] = $snapMap[$iiId];
        }
    }
    unset($it);

    return $items;
}

/* ============================================================
 * ۵) اعتبارسنجی موجودی بسته برای فروش
 * ========================================================== */

/**
 * اعتبارسنجی سمت سرور موجودی برای اقلام بسته در فاکتور فروش.
 *
 * @param array $items اقلام با base_quantity و در صورت وجود components
 * @return array ['ok'=>bool,'message'=>string]
 */
function validateBundleStockForItems(mysqli $mysqli, array $items): array
{
    $pids = array_map('intval', array_column($items, 'product_id'));
    $kindMap = bundleKindMap($mysqli, $pids);
    $hasBundle = false;
    foreach ($pids as $pid) {
        if (($kindMap[$pid] ?? PRODUCT_KIND_STANDARD) === PRODUCT_KIND_BUNDLE) {
            $hasBundle = true;
            break;
        }
    }
    if (!$hasBundle) {
        return ['ok' => true, 'message' => ''];
    }

    $requirements = bundleRequirementsFromItems($mysqli, $items, $kindMap);
    if (!$requirements) {
        return ['ok' => true, 'message' => ''];
    }
    foreach ($requirements as $pid => $need) {
        if (money_cmp($need, '0') <= 0) {
            continue;
        }
        $stmt = $mysqli->prepare('SELECT name, stock FROM products WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            return ['ok' => false, 'message' => 'یکی از کالاهای جزء بسته معتبر نیست.'];
        }
        if (money_cmp((string) $row['stock'], $need) < 0) {
            return ['ok' => false, 'message' => 'موجودی کالای «' . $row['name'] . '» برای این تعداد بسته کافی نیست.'];
        }
    }
    return ['ok' => true, 'message' => ''];
}

/* ============================================================
 * ۶) نرمال‌سازی و اعتبارسنجی اجزای بسته
 * ========================================================== */

/**
 * اعتبارسنجی اجزای ارسال‌شده (منبع حقیقت: دیتابیس).
 *  - جزء باید محصول (نه خدمت) و standard (نه بسته) و موجودی > 0 باشد
 *  - quantity > 0 و حداکثر برابر موجودی فعلی
 *  - بدون تکرار و بدون self-reference
 *
 * @param array $raw      list of ['product_id'=>, 'quantity'=>]
 * @param int   $bundleId شناسه بسته (برای جلوگیری از self-reference)
 * @return array ['ok'=>bool,'message'=>string,'components'=>array]
 */
function bundleNormalizeComponents(mysqli $mysqli, $raw, int $bundleId = 0): array
{
    if (!is_array($raw) || count($raw) === 0) {
        return ['ok' => false, 'message' => 'حداقل یک کالای جزء برای بسته الزامی است.', 'components' => []];
    }
    $seen = [];
    $components = [];
    foreach ($raw as $r) {
        if (!is_array($r)) {
            return ['ok' => false, 'message' => 'ساختار کالاهای جزء نامعتبر است.', 'components' => []];
        }
        $pidRaw = trim((string) ($r['product_id'] ?? ($r['component_product_id'] ?? '')));
        if ($pidRaw === '' || !ctype_digit($pidRaw) || (int) $pidRaw <= 0) {
            return ['ok' => false, 'message' => 'شناسه یکی از کالاهای جزء نامعتبر است.', 'components' => []];
        }
        $pid = (int) $pidRaw;
        if ($bundleId > 0 && $pid === $bundleId) {
            return ['ok' => false, 'message' => 'یک بسته نمی‌تواند جزء خودش باشد.', 'components' => []];
        }
        if (isset($seen[$pid])) {
            return ['ok' => false, 'message' => 'کالای جزء تکراری مجاز نیست.', 'components' => []];
        }
        $seen[$pid] = true;

        $qtyRaw = trim((string) ($r['quantity'] ?? ($r['component_quantity'] ?? '')));
        if ($qtyRaw === '' || !is_numeric($qtyRaw) || money_cmp($qtyRaw, '0') <= 0) {
            return ['ok' => false, 'message' => 'تعداد هر کالای جزء باید عددی مثبت باشد.', 'components' => []];
        }
        $qty = money_round($qtyRaw);

        $stmt = $mysqli->prepare('SELECT id, name, type, product_kind, stock, purchase_price, sale_price FROM products WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $p = $res ? $res->fetch_assoc() : null;
        if (!$p) {
            return ['ok' => false, 'message' => 'یکی از کالاهای جزء در سیستم یافت نشد.', 'components' => []];
        }
        if (($p['type'] ?? '') !== 'product') {
            return ['ok' => false, 'message' => 'کالای «' . $p['name'] . '» خدمت است و نمی‌تواند جزء بسته باشد.', 'components' => []];
        }
        if (isBundleKind($p['product_kind'] ?? PRODUCT_KIND_STANDARD)) {
            return ['ok' => false, 'message' => 'کالای «' . $p['name'] . '» خودش یک بسته است و نمی‌تواند جزء بسته دیگر باشد.', 'components' => []];
        }
        if (money_cmp((string) $p['stock'], '0') <= 0) {
            return ['ok' => false, 'message' => 'کالای «' . $p['name'] . '» موجودی فعلی ندارد و قابل استفاده در بسته نیست.', 'components' => []];
        }
        if (money_cmp($qty, (string) $p['stock']) > 0) {
            return ['ok' => false, 'message' => 'تعداد انتخاب‌شده برای «' . $p['name'] . '» از موجودی فعلی (' . money_round((string) $p['stock']) . ') بیشتر است.', 'components' => []];
        }

        $components[] = [
            'product_id'           => $pid,
            'component_product_id' => $pid,
            'quantity'             => $qty,
            'component_quantity'   => $qty,
            'purchase_price'       => (string) ($p['purchase_price'] ?? '0'),
            'sale_price'           => (string) ($p['sale_price'] ?? '0'),
        ];
    }
    return ['ok' => true, 'message' => '', 'components' => $components];
}

/* ============================================================
 * ۷) ذخیره بسته (ایجاد / ویرایش) — سرویس مشترک Backend
 * ========================================================== */

/**
 * @param array $in
 *   product_id (۰=جدید), name, code, barcode, category_id,
 *   purchase_price, sale_price, min_stock, description,
 *   components => list of ['product_id'=>, 'quantity'=>]
 * @return array ['ok'=>bool,'message'=>string,'product_id'=>int]
 */
function bundleSaveProduct(mysqli $mysqli, array $in): array
{
    $productId = (int) ($in['product_id'] ?? 0);

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'نام بسته اجباری است.'];
    }
    if (mb_strlen($name) > 150) {
        return ['ok' => false, 'message' => 'نام بسته حداکثر ۱۵۰ کاراکتر است.'];
    }

    $code = trim((string) ($in['code'] ?? ''));
    if ($code === '') {
        $code = nextProductCode($mysqli);
    }
    if (mb_strlen($code) > 50) {
        return ['ok' => false, 'message' => 'کد بسته حداکثر ۵۰ کاراکتر است.'];
    }

    $barcode = trim((string) ($in['barcode'] ?? ''));
    if ($barcode === '') {
        $barcode = null;
    } elseif (mb_strlen($barcode) > 100) {
        return ['ok' => false, 'message' => 'بارکد بسته حداکثر ۱۰۰ کاراکتر است.'];
    }

    $categoryId = trim((string) ($in['category_id'] ?? ''));
    if ($categoryId === '' || !ctype_digit($categoryId)) {
        $categoryId = null;
    } else {
        $categoryId = (int) $categoryId;
        $stmt = $mysqli->prepare('SELECT id FROM product_categories WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            return ['ok' => false, 'message' => 'دسته‌بندی انتخاب‌شده معتبر نیست.'];
        }
    }

    $description = trim((string) ($in['description'] ?? ''));
    $minStockRaw = trim((string) ($in['min_stock'] ?? '0'));
    if ($minStockRaw === '') {
        $minStockRaw = '0';
    }
    if (!is_numeric($minStockRaw) || money_cmp($minStockRaw, '0') < 0) {
        return ['ok' => false, 'message' => 'حداقل موجودی نامعتبر است.'];
    }
    $minStock = money_round($minStockRaw);

    /* نام واحد بسته (متغیر محلی؛ bind_param فقط متغیر می‌پذیرد) */
    $unitName = BUNDLE_UNIT_NAME;

    /* --- اجزای بسته (منبع حقیقت: دیتابیس) --- */
    $norm = bundleNormalizeComponents($mysqli, $in['components'] ?? null, $productId);
    if (!$norm['ok']) {
        return ['ok' => false, 'message' => $norm['message']];
    }
    $components = $norm['components'];

    /* --- قیمت‌های محاسبه‌شده (سرور) و override اختیاری --- */
    $calc = bundleCalculatedPricesFromComponents($components);
    $purchaseRaw = trim((string) ($in['purchase_price'] ?? ''));
    $saleRaw = trim((string) ($in['sale_price'] ?? ''));
    if ($purchaseRaw === '') {
        $purchasePrice = $calc['purchase'];
    } else {
        if (!is_numeric($purchaseRaw) || money_cmp($purchaseRaw, '0') < 0) {
            return ['ok' => false, 'message' => 'قیمت خرید بسته نامعتبر است.'];
        }
        $purchasePrice = money_round($purchaseRaw);
    }
    if ($saleRaw === '') {
        $salePrice = $calc['sale'];
    } else {
        if (!is_numeric($saleRaw) || money_cmp($saleRaw, '0') < 0) {
            return ['ok' => false, 'message' => 'قیمت فروش بسته نامعتبر است.'];
        }
        $salePrice = money_round($saleRaw);
    }

    /* --- بررسی یکتا بودن کد و بارکد (به جز خودِ بسته) --- */
    $stmt = $mysqli->prepare('SELECT id FROM products WHERE code = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $code, $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $dupCode = $res && $res->fetch_assoc();
    $stmt->close();
    if ($dupCode) {
        return ['ok' => false, 'message' => 'کد «' . $code . '» قبلاً برای کالای دیگری ثبت شده است.'];
    }
    if ($barcode !== null) {
        $stmt = $mysqli->prepare('SELECT id FROM products WHERE barcode = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $barcode, $productId);
        $stmt->execute();
        $res = $stmt->get_result();
        $dupBarcode = $res && $res->fetch_assoc();
        $stmt->close();
        if ($dupBarcode) {
            return ['ok' => false, 'message' => 'بارکد «' . $barcode . '» قبلاً برای کالای دیگری ثبت شده است.'];
        }
    }

    $mysqli->begin_transaction();
    try {
        if ($productId > 0) {
            /* ---------- ویرایش بسته ---------- */
            $stmt = $mysqli->prepare('SELECT product_kind FROM products WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            $row = $res ? $res->fetch_assoc() : null;
            if (!$row) {
                throw new Exception('bundle_not_found');
            }
            if (!isBundleKind($row['product_kind'] ?? PRODUCT_KIND_STANDARD)) {
                throw new Exception('not_a_bundle');
            }

            if ($categoryId === null) {
                $stmt = $mysqli->prepare(
                    'UPDATE products SET code = ?, barcode = ?, name = ?, category_id = NULL,
                        type = "product", product_kind = "bundle", unit = ?,
                        purchase_price = ?, sale_price = ?, min_stock = ?, description = ?
                     WHERE id = ?'
                );
                $stmt->bind_param('ssssdddsi', $code, $barcode, $name, $unitName, $purchasePrice, $salePrice, $minStock, $description, $productId);
            } else {
                $stmt = $mysqli->prepare(
                    'UPDATE products SET code = ?, barcode = ?, name = ?, category_id = ?,
                        type = "product", product_kind = "bundle", unit = ?,
                        purchase_price = ?, sale_price = ?, min_stock = ?, description = ?
                     WHERE id = ?'
                );
                $stmt->bind_param('sssisddsi', $code, $barcode, $name, $categoryId, $unitName, $purchasePrice, $salePrice, $minStock, $description, $productId);
            }
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();

            $stmt = $mysqli->prepare('DELETE FROM bundle_components WHERE bundle_product_id = ?');
            $stmt->bind_param('i', $productId);
            $stmt->execute();
            $stmt->close();
        } else {
            /* ---------- ایجاد بسته ---------- */
            if ($categoryId === null) {
                $stmt = $mysqli->prepare(
                    'INSERT INTO products (code, barcode, name, category_id, type, product_kind, unit, purchase_price, sale_price, stock, min_stock, description)
                     VALUES (?, ?, ?, NULL, "product", "bundle", ?, ?, ?, 0, ?, ?)'
                );
                $stmt->bind_param('ssssddds', $code, $barcode, $name, $unitName, $purchasePrice, $salePrice, $minStock, $description);
            } else {
                $stmt = $mysqli->prepare(
                    'INSERT INTO products (code, barcode, name, category_id, type, product_kind, unit, purchase_price, sale_price, stock, min_stock, description)
                     VALUES (?, ?, ?, ?, "product", "bundle", ?, ?, ?, 0, ?, ?)'
                );
                $stmt->bind_param('sssisddds', $code, $barcode, $name, $categoryId, $unitName, $purchasePrice, $salePrice, $minStock, $description);
            }
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $productId = (int) $stmt->insert_id;
            $stmt->close();
        }

        /* ---------- درج اجزای بسته (منبع حقیقت) ---------- */
        $stmt = $mysqli->prepare(
            'INSERT INTO bundle_components (bundle_product_id, component_product_id, quantity) VALUES (?, ?, ?)'
        );
        foreach ($components as $c) {
            $bid = $productId;
            $cPid = (int) $c['component_product_id'];
            $qty = $c['quantity'];
            $stmt->bind_param('iid', $bid, $cPid, $qty);
            $stmt->execute();
        }
        $stmt->close();

        /* ---------- واحد پایه بسته (سازگاری با منطق چندواحدی فاکتور) ---------- */
        $base = getBaseUnit($mysqli, $productId);
        if ($base === null) {
            $stmt = $mysqli->prepare(
                'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
                 VALUES (?, ?, 1, 1, ?, ?, ?, 0)'
            );
            $stmt->bind_param('isdds', $productId, $unitName, $purchasePrice, $salePrice, $barcode);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare(
                'UPDATE product_units SET name = ?, purchase_price = ?, sale_price = ?, barcode = ? WHERE id = ?'
            );
            $baseId = (int) $base['id'];
            $stmt->bind_param('sddsi', $unitName, $purchasePrice, $salePrice, $barcode, $baseId);
            $stmt->execute();
            $stmt->close();
        }

        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'bundle_not_found') {
            $msg = 'بسته موردنظر یافت نشد.';
        } elseif ($msg === 'not_a_bundle') {
            $msg = 'این کالا یک بسته نیست و از این مسیر قابل ویرایش نیست.';
        } else {
            $msg = 'خطا در ذخیره‌سازی بسته: ' . $msg;
        }
        return ['ok' => false, 'message' => $msg];
    }

    return ['ok' => true, 'message' => 'بسته با موفقیت ذخیره شد.', 'product_id' => $productId];
}

/* ============================================================
 * ۸) حذف بسته — با محافظت از سوابق تاریخی
 * ========================================================== */

/**
 * @return array ['ok'=>bool,'message'=>string,'product_id'=>int]
 */
function bundleDeleteProduct(mysqli $mysqli, int $productId): array
{
    if ($productId <= 0) {
        return ['ok' => false, 'message' => 'شناسه کالا نامعتبر است.'];
    }

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare('SELECT id, name, product_kind FROM products WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            throw new Exception('bundle_not_found');
        }
        if (!isBundleKind($row['product_kind'] ?? PRODUCT_KIND_STANDARD)) {
            throw new Exception('not_a_bundle');
        }

        /* بسته‌ای که در فاکتوری استفاده شده (snapshot تاریخی) حذف نمی‌شود */
        $stmt = $mysqli->prepare('SELECT 1 FROM invoice_bundle_items WHERE bundle_product_id = ? LIMIT 1');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->store_result();
        $hasHistory = $stmt->num_rows > 0;
        $stmt->close();
        if ($hasHistory) {
            throw new Exception('bundle_has_history');
        }

        /* بسته در هیچ ردیف فاکتوری استفاده نشده باشد */
        $stmt = $mysqli->prepare('SELECT 1 FROM invoice_items WHERE product_id = ? LIMIT 1');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->store_result();
        $hasItems = $stmt->num_rows > 0;
        $stmt->close();
        if ($hasItems) {
            throw new Exception('bundle_has_items');
        }

        $stmt = $mysqli->prepare('DELETE FROM bundle_components WHERE bundle_product_id = ?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->close();

        $stmt = $mysqli->prepare('DELETE FROM products WHERE id = ? AND product_kind = "bundle"');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        if ($deleted < 1) {
            throw new Exception('bundle_not_found');
        }

        $mysqli->commit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $msg = $e->getMessage();
        if ($msg === 'bundle_not_found') {
            $msg = 'بسته موردنظر یافت نشد.';
        } elseif ($msg === 'not_a_bundle') {
            $msg = 'این کالا یک بسته نیست.';
        } elseif ($msg === 'bundle_has_history') {
            $msg = 'این بسته در فاکتورهای ثبت‌شده استفاده شده و حذف آن مجاز نیست.';
        } elseif ($msg === 'bundle_has_items') {
            $msg = 'این بسته در اقلام فاکتور استفاده شده و حذف آن مجاز نیست.';
        } else {
            $msg = 'خطا در حذف بسته: ' . $msg;
        }
        return ['ok' => false, 'message' => $msg];
    }

    return ['ok' => true, 'message' => 'بسته با موفقیت حذف شد.', 'product_id' => $productId];
}






