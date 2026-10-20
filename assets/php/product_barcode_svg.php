<?php
/**
 * product_barcode_svg.php — خروجی گرافیک SVG بارکد Code 128 یک کالا (فقط‌خواندنی)
 *
 * GET (بدون CSRF مانند سایر GETهای پروژه):
 *   ?id=<productId>
 *
 * مقدار بارکد مستقیماً از دیتابیس خوانده می‌شود؛ هیچ مقدار بارکدی از کلاینت
 * پذیرفته نمی‌شود تا پیش‌نمایش/چاپ همیشه معرف مقدار واقعیِ ذخیره‌شده باشد.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_barcode_common.php';

function barcode_svg_fail(string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$idRaw = trim((string) ($_GET['id'] ?? ''));
if ($idRaw === '' || !ctype_digit($idRaw) || (int) $idRaw <= 0) {
    barcode_svg_fail('شناسه کالا نامعتبر است.', 400);
}

$productId = (int) $idRaw;
$stmt = $mysqli->prepare('SELECT id, type, barcode FROM products WHERE id = ?');
$stmt->bind_param('i', $productId);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    barcode_svg_fail('کالای موردنظر یافت نشد.', 404);
}

$barcode = (string) ($row['barcode'] ?? '');
if ($barcode === '') {
    barcode_svg_fail('این کالا بارکد ندارد.', 404);
}

try {
    $svg = product_barcode_code128_svg($barcode);
} catch (Throwable $e) {
    error_log('product_barcode_svg error: ' . $e->getMessage());
    barcode_svg_fail('بارکد قابل نمایش نیست.', 400);
}

http_response_code(200);
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=86400');
echo $svg;
