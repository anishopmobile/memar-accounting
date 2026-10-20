<?php
/**
 * bundle_action.php — ایجاد / ویرایش / حذف بسته (POST + CSRF)
 *
 * اعمال‌ها:
 *   action=save   → ذخیره بسته (ایجاد یا ویرایش) — با CSRF
 *   action=delete → حذف بسته (با محافظت سوابق تاریخی) — با CSRF
 *   action=next_code → کد بعدی برای فرم بسته (GET بدون CSRF)
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_common.php';
require_once __DIR__ . '/bundle_common.php';

$action = trim((string) ($_GET['action'] ?? ($_POST['action'] ?? '')));

if ($action === 'next_code') {
    respond_json(true, '', ['code' => nextProductCode($mysqli)]);
}

require_csrf_or_fail();

if ($action === 'delete') {
    $productIdRaw = trim((string) ($_POST['product_id'] ?? ''));
    if ($productIdRaw === '' || !ctype_digit($productIdRaw) || (int) $productIdRaw <= 0) {
        respond_error('شناسه بسته نامعتبر است.');
    }
    $productId = (int) $productIdRaw;
    $stmt = $mysqli->prepare('SELECT product_kind FROM products WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || !isBundleKind($row['product_kind'] ?? PRODUCT_KIND_STANDARD)) {
        respond_error('کالای انتخاب‌شده یک بسته نیست.');
    }
    $result = bundleDeleteProduct($mysqli, $productId);
    if ($result['ok']) {
        respond_json(true, $result['message'], ['product_id' => $result['product_id']]);
    }
    respond_error($result['message'], 422);
}

/* ---------- action = save ---------- */

$componentsRaw = $_POST['components'] ?? null;
if (is_string($componentsRaw)) {
    $componentsRaw = json_decode($componentsRaw, true);
}
if (!is_array($componentsRaw)) {
    $componentsRaw = [];
}

$in = [
    'product_id'     => trim((string) ($_POST['bundle_id'] ?? ($_POST['product_id'] ?? '0'))),
    'name'           => trim((string) ($_POST['name'] ?? '')),
    'code'           => trim((string) ($_POST['code'] ?? '')),
    'barcode'        => trim((string) ($_POST['barcode'] ?? '')),
    'category_id'    => trim((string) ($_POST['category_id'] ?? '')),
    'purchase_price' => trim((string) ($_POST['purchase_price'] ?? '')),
    'sale_price'     => trim((string) ($_POST['sale_price'] ?? '')),
    'min_stock'      => trim((string) ($_POST['min_stock'] ?? '0')),
    'description'    => trim((string) ($_POST['description'] ?? '')),
    'components'     => $componentsRaw,
];

$result = bundleSaveProduct($mysqli, $in);
if ($result['ok']) {
    $data = [
        'product_id' => $result['product_id'],
        'bundle'     => (function ($mysqli, int $bid): array {
            $stmt = $mysqli->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $bid);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            $b = $res ? $res->fetch_assoc() : null;
            if (!$b) {
                return [];
            }
            $b['components'] = getBundleComponents($mysqli, $bid);
            $b['availability'] = getBundleAvailability($mysqli, $bid);
            $b['units'] = getProductUnits($mysqli, $bid);
            return $b;
        })($mysqli, $result['product_id']),
    ];
    respond_json(true, $result['message'], $data);
}

respond_error($result['message'], 422);