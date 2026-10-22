<?php
/** UI and PDF media switches are independent of financial settings. */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/media_common.php';
$keys = [
    'products_list','sales_ui','purchase_ui','sales_returns_ui',
    'defective_ui','reports_ui','category_images',
    'pdf_sales','pdf_sales_proforma','pdf_purchase','pdf_purchase_proforma','pdf_sales_return'
];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(true, '', ['settings'=>media_settings($mysqli)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond_error('روش درخواست نامعتبر است.', 405);
require_csrf_or_fail();
$values = [];
foreach ($keys as $key) {
    $v = (string)($_POST[$key] ?? '0');
    if ($v !== '0' && $v !== '1') respond_error('مقدار تنظیمات نامعتبر است.', 422);
    $values[] = (int)$v;
}
$assignments = implode(', ', array_map(static fn($k)=>"$k=?", $keys));
$stmt = $mysqli->prepare('UPDATE media_display_settings SET ' . $assignments . ' WHERE id=1');
$stmt->bind_param(str_repeat('i', count($keys)), ...$values);
$ok = $stmt->execute();
$stmt->close();
if (!$ok) respond_error('ذخیره تنظیمات تصاویر ناموفق بود.', 500);
respond_json(true, 'تنظیمات تصاویر ذخیره شد.', ['settings'=>media_settings($mysqli)]);
