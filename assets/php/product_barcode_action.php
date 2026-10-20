<?php
/**
 * product_barcode_action.php — صدور «بارکد داخلی» کالا (POST + CSRF)
 *
 * عملیات:
 *   action=generate  →  ساخت M + (20000000000 + product_id) و ذخیره در ستون
 *                       موجود products.barcode
 *
 * قوانین:
 *   - فقط برای کالاهای فیزیکی (type = 'product') مجاز است؛ خدمات شامل نمی‌شوند.
 *   - فقط وقتی مقدار فعلی بارکد خالی است اجرا می‌شود؛ بارکد موجود هرگز بازنویسی نمی‌شود.
 *   - از query آماده استفاده می‌شود و شناسه کالا سمت سرور اعتبارسنجی می‌شود.
 *   - اعتبارسنجی تکراری بودن بارکد (UNIQUE) دست‌نخورده باقی می‌ماند؛ سیستم
 *     با عبارات آماده بررسی و در صورت تداخل به‌طور امن خطا برمی‌گرداند.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_common.php';
require_once __DIR__ . '/product_barcode_common.php';

require_csrf_or_fail();

header('Content-Type: application/json; charset=UTF-8');

$action = trim((string) ($_POST['action'] ?? ''));
if ($action !== 'generate') {
    respond_error('عملیات نامعتبر است.', 400);
}

$idRaw = trim((string) ($_POST['product_id'] ?? ''));
if ($idRaw === '' || !ctype_digit($idRaw) || (int) $idRaw <= 0) {
    respond_error('شناسه کالا نامعتبر است.', 400);
}
$productId = (int) $idRaw;

/* ---------- خواندن کالا: وجود، نوع و بارکد فعلی ---------- */
$stmt = $mysqli->prepare('SELECT id, code, name, type, barcode, stock FROM products WHERE id = ?');
$stmt->bind_param('i', $productId);
$stmt->execute();
$res = $stmt->get_result();
$product = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$product) {
    respond_error('کالای موردنظر یافت نشد.', 404);
}

if ($product['type'] !== 'product') {
    respond_error('ایجاد بارکد داخلی فقط برای کالاها (محصولات) امکان‌پذیر است.', 422);
}

$existingBarcode = (string) ($product['barcode'] ?? '');
if ($existingBarcode !== '') {
    /* کالا از قبل بارکد دارد؛ چیزی بازنویسی نمی‌شود و همان بارکد برای چاپ برگردانده می‌شود */
    $product['barcode'] = $existingBarcode;
    respond_json(true, 'این کالا از قبل بارکد دارد.', ['product' => $product, 'barcode' => $existingBarcode]);
}

$newBarcode = product_internal_barcode($productId);

/* ---------- حفاظت افزوده در برابر تداخل (حتی قبل از UNIQUE دیتابیس) ---------- */
$dupStmt = $mysqli->prepare('SELECT id FROM products WHERE barcode = ? AND id <> ? LIMIT 1');
$dupStmt->bind_param('si', $newBarcode, $productId);
$dupStmt->execute();
$dupRes = $dupStmt->get_result();
$dupExists = $dupRes && $dupRes->fetch_assoc();
$dupStmt->close();
if ($dupExists) {
    respond_error('بارکد داخلی به‌طور غیرمنتظره با کالای دیگری تداخل دارد.', 409);
}

/* ---------- ذخیره: فقط اگر هنوز بارکد ندارد (ضد رقابت/تکرار امن) ---------- */
$stmt = $mysqli->prepare("UPDATE products SET barcode = ? WHERE id = ? AND (barcode IS NULL OR barcode = '')");
$stmt->bind_param('si', $newBarcode, $productId);
if (!$stmt->execute()) {
    if ($mysqli->errno === 1062) {
        respond_error('بارکد داخلی به‌طور غیرمنتظره با کالای دیگری تداخل دارد.', 409);
    }
    error_log('product_barcode_action update failed: ' . $mysqli->error);
    respond_error('خطا در ذخیره‌سازی بارکد. لطفاً دوباره تلاش کنید.', 500);
}
$affected = (int) $mysqli->affected_rows;
$stmt->close();

if ($affected !== 1) {
    /* کالا بین انتخاب و ذخیره، بارکد گرفته است → بارکد فعلی را برای چاپ برمی‌گردانیم */
    $stmt = $mysqli->prepare('SELECT barcode FROM products WHERE id = ?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $currentBarcode = (string) ($row['barcode'] ?? '');
    if ($currentBarcode !== '') {
        $product['barcode'] = $currentBarcode;
        respond_json(true, 'این کالا از قبل بارکد دارد.', ['product' => $product, 'barcode' => $currentBarcode]);
    }
    respond_error('خطا در ذخیره‌سازی بارکد. لطفاً دوباره تلاش کنید.', 500);
}

/* ---------- همگام‌سازی واحد پایه (فقط اگر خودش بارکد ندارد) ----------
 * مطابق رویه موجود پروژه، بارکد واحد پایه با بارکد کالا همگام است؛
 * اما هرگز بارکد اختصاصی از قبل تعیین‌شده برای واحد را بازنویسی نمی‌کنیم. */
$baseUnit = getBaseUnit($mysqli, $productId);
if ($baseUnit !== null && (string) ($baseUnit['barcode'] ?? '') === '') {
    $baseUnitId = (int) $baseUnit['id'];
    $uStmt = $mysqli->prepare('UPDATE product_units SET barcode = ? WHERE id = ?');
    $uStmt->bind_param('si', $newBarcode, $baseUnitId);
    $uStmt->execute();
    $uStmt->close();
}

/* ---------- بازخوانی رکورد برای پاسخ دقیق ---------- */
$stmt = $mysqli->prepare('SELECT id, code, name, type, barcode, stock FROM products WHERE id = ?');
$stmt->bind_param('i', $productId);
$stmt->execute();
$res = $stmt->get_result();
$product = $res ? $res->fetch_assoc() : null;
$stmt->close();

respond_json(
    true,
    'بارکد داخلی با موفقیت ایجاد شد.',
    ['product' => $product, 'barcode' => $product['barcode'] ?? $newBarcode]
);
