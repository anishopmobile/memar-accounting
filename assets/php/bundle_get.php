<?php
/**
 * bundle_get.php — جزئیات یک بسته + ترکیب فعلی (GET بدون CSRF)
 *
 * خروجی برای پُرکردن مودال ویرایش بسته:
 *   product (name/code/barcode/category/...)، components،
 *   calculated_purchase، calculated_sale، availability، units
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_common.php';
require_once __DIR__ . '/bundle_common.php';

$idRaw = trim((string) ($_GET['id'] ?? ''));
if ($idRaw === '' || !ctype_digit($idRaw) || (int) $idRaw <= 0) {
    respond_error('شناسه بسته نامعتبر است.');
}
$id = (int) $idRaw;

$stmt = $mysqli->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
$product = $res ? $res->fetch_assoc() : null;
if (!$product) {
    respond_error('بسته یافت نشد.', 404);
}
if (!isBundleKind($product['product_kind'] ?? PRODUCT_KIND_STANDARD)) {
    respond_error('کالای انتخابی یک بسته نیست.', 404);
}

$components = getBundleComponents($mysqli, $id);
$calc = bundleCalculatedPricesFromComponents($components);

respond_json(true, '', [
    'product'             => $product,
    'components'          => $components,
    'calculated_purchase' => $calc['purchase'],
    'calculated_sale'     => $calc['sale'],
    'availability'        => bundleAvailabilityFromComponents($components),
    'units'               => getProductUnits($mysqli, $id),
]);