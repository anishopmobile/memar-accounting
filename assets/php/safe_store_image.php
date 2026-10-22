<?php
/**
 * Image-only storage for logos, signature and stamp.
 * Existing asset URLs remain compatible with printable documents.
 * New uploads are never stored verbatim: decoded pixels are re-encoded.
 */
declare(strict_types=1);

function store_image_save(array $upload, string $kind, int $maxBytes = 5242880): array
{
    $types = ['brand_logo', 'logo_image', 'signature_image', 'stamp_image'];
    if (!in_array($kind, $types, true)) {
        throw new InvalidArgumentException('فیلد تصویر مجاز نیست.');
    }
    $tmp = (string)($upload['tmp_name'] ?? '');
    $size = (int)($upload['size'] ?? 0);
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || $size < 1 || $size > $maxBytes || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('فایل تصویر معتبر نیست یا حجم آن بیش از حد مجاز است.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $supported = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
    $info = @getimagesize($tmp);
    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);
    if (!isset($supported[$mime]) || ($info['mime'] ?? '') !== $mime
        || $w < 1 || $h < 1 || $w > 3000 || $h > 3000
        || $w * $h > 9000000 || !extension_loaded('gd')) {
        throw new InvalidArgumentException('فقط تصاویر واقعی JPEG، PNG یا WebP با ابعاد مجاز پذیرفته می‌شوند.');
    }
    $bytes = @file_get_contents($tmp);
    $image = $bytes === false ? false : @imagecreatefromstring($bytes);
    unset($bytes);
    if (!$image) throw new InvalidArgumentException('محتوای تصویر قابل پردازش نیست.');
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        imagedestroy($image);
        throw new RuntimeException('پوشه تصاویر تنظیمات در دسترس نیست.');
    }
    // Never accept a client-controlled path, extension or basename.
    $name = ($kind === 'brand_logo' ? 'brand' : $kind) . '_' . bin2hex(random_bytes(16)) . '.' . $supported[$mime];
    $target = $dir . '/' . $name;
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
    $ok = match ($mime) {
        'image/png' => @imagepng($image, $target, 6),
        'image/jpeg' => @imagejpeg($image, $target, 88),
        'image/webp' => @imagewebp($image, $target, 88),
        default => false
    };
    imagedestroy($image);
    if (!$ok || !is_file($target) || filesize($target) < 1) {
        @unlink($target);
        throw new RuntimeException('بازنویسی ایمن تصویر انجام نشد.');
    }
    @chmod($target, 0644);
    return ['path'=>'assets/uploads/' . $name, 'absolute'=>$target];
}
