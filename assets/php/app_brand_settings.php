<?php
/** UI-only app branding; independent of the invoice store logo and payment. */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';

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
    $upload = $_FILES['brand_logo'];
    if (($upload['error'] ?? -1) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) <= 0
        || ($upload['size'] ?? 0) > 2 * 1024 * 1024
        || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        respond_error('فایل لوگو باید یک تصویر معتبر حداکثر ۲ مگابایتی باشد.', 422);
    }
    // Validate actual bytes instead of trusting uploaded MIME/extension.
    $info = @getimagesize($upload['tmp_name']);
    $mime = $info['mime'] ?? '';
    $formats = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/webp'=>'webp'];
    if (!isset($formats[$mime]) || ($info[0] ?? 0) > 3000 || ($info[1] ?? 0) > 3000
        || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
        respond_error('لوگو باید PNG، JPG یا WebP معتبر با ابعاد حداکثر ۳۰۰۰ پیکسل باشد.', 422);
    }
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        respond_error('پوشه نگهداری لوگو در دسترس نیست.', 500);
    }
    $file = 'brand_' . bin2hex(random_bytes(16)) . '.' . $formats[$mime];
    $newPath = $dir . '/' . $file;
    if (!move_uploaded_file($upload['tmp_name'], $newPath)) {
        respond_error('آپلود لوگو ناموفق بود.', 500);
    }
    @chmod($newPath, 0644);
    $logo = 'assets/uploads/' . $file;
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
