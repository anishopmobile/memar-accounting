<?php
/** UI-only app branding; independent of the invoice store logo and payment. */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/safe_store_image.php';

function brand_settings_data(mysqli $mysqli): array {
    $r = $mysqli->query('SELECT display_mode, logo_path FROM app_brand_settings WHERE id = 1');
    $row = $r ? $r->fetch_assoc() : null;
    return [
        'display_mode' => in_array($row['display_mode'] ?? '', ['name','logo','logo_name'], true)
            ? $row['display_mode'] : 'name',
        'logo_path' => (string) ($row['logo_path'] ?? '')
    ];
}
function brand_local_image(string $path): bool {
    if (!preg_match('#^assets/uploads/brand_[0-9a-f]{32}\\.(png|jpg|webp)$#D', $path)) {
        return false;
    }
    $root = realpath(__DIR__ . '/../..');
    return $root !== false && is_file($root . '/' . $path);
}

$settings = brand_settings_data($mysqli);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(true, '', $settings);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('متد درخواست پشتیبانی نمی‌شود.', 405);
}
require_csrf_or_fail();

$mode = (string) ($_POST['display_mode'] ?? '');
if (!in_array($mode, ['name','logo','logo_name'], true)) {
    respond_error('حالت نمایش انتخاب‌شده معتبر نیست.', 422);
}
$logo = $settings['logo_path'];
$newPath = null;
if (isset($_FILES['brand_logo']) && ($_FILES['brand_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $saved = store_image_save($_FILES['brand_logo'], 'brand_logo', 2 * 1024 * 1024);
    } catch (Throwable $e) {
        if (!($e instanceof InvalidArgumentException)) error_log('brand image upload: '.$e->getMessage());
        respond_error($e instanceof InvalidArgumentException
            ? $e->getMessage() : 'بارگذاری امن لوگو امکان‌پذیر نیست.', 422);
    }
    $newPath = $saved['absolute'];
    $logo = $saved['path'];
}
if ($mode !== 'name' && !brand_local_image($logo)) {
    if ($newPath !== null) { @unlink($newPath); }
    respond_error('برای نمایش لوگو ابتدا یک تصویر معتبر انتخاب کنید.', 422);
}
try {
    $stmt = $mysqli->prepare(
        'INSERT INTO app_brand_settings (id,display_mode,logo_path) VALUES (1,?,?)'
        . ' ON DUPLICATE KEY UPDATE display_mode=VALUES(display_mode),logo_path=VALUES(logo_path)'
    );
    $stmt->bind_param('ss', $mode, $logo);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    if ($newPath !== null) { @unlink($newPath); }
    error_log('brand setting save: '.$e->getMessage());
    respond_error('ذخیره تنظیمات نمایشی ناموفق بود.', 500);
}
respond_json(true, 'نمایش عنوان نوار بالا ذخیره شد.', ['display_mode'=>$mode,'logo_path'=>$logo]);
