<?php
/**
 * auth_users.php — کاربران و مدیریت حساب
 *
 * شامل ثبت‌نام امن اولین کاربر (اتمیک)، جستجوی کاربران، تغییر رمز عبور و
 * پردازش امن تصویر پروفایل. تمام پرس‌وجوها prepared هستند و هیچ مقدار
 * ورودی بدون اعتبارسنجی استفاده نمی‌شود.
 */

declare(strict_types=1);

const AUTH_USERNAME_MIN_LENGTH = 3;
const AUTH_USERNAME_MAX_LENGTH = 64;
const AUTH_PASSWORD_MIN_LENGTH = 8;
const AUTH_AVATAR_MAX_BYTES = 2097152; // 2MB
const AUTH_AVATAR_MAX_DIMENSION = 1200;
const AUTH_USERNAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{1,62}[a-zA-Z0-9]$/';

/** نرمال‌سازی نام کاربری — بین ثبت‌نام و ورود باید یکسان باشد. */
function auth_username_normalize(string $raw): string
{
    return strtolower(trim($raw));
}

/** اعتبارسنجی نام کاربری (پس از نرمال‌سازی). */
function auth_username_is_valid(string $username): bool
{
    $len = strlen($username);
    if ($len < AUTH_USERNAME_MIN_LENGTH || $len > AUTH_USERNAME_MAX_LENGTH) {
        return false;
    }
    return preg_match(AUTH_USERNAME_PATTERN, $username) === 1;
}

/** اعتبارسنجی رمز عبور: حداقل طول + ترکیب حروف و عدد. */
function auth_password_is_valid(string $password): bool
{
    if (strlen($password) < AUTH_PASSWORD_MIN_LENGTH) {
        return false;
    }
    return preg_match('/[a-zA-Z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

function auth_user_exists(string $username, ?mysqli $db = null): bool
{
    $user = auth_user_by_username($username, $db);
    return $user !== null;
}

function auth_user_count(?mysqli $db = null): int
{
    $db = $db ?? $GLOBALS['mysqli'];
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM users');
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (int) $row['c'] : 0;
}

function auth_user_by_id(int $id, ?mysqli $db = null): ?array
{
    $db = $db ?? $GLOBALS['mysqli'];
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

function auth_user_by_username(string $username, ?mysqli $db = null): ?array
{
    $db = $db ?? $GLOBALS['mysqli'];
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return is_array($row) ? $row : null;
}

/**
 * آیا ثبت‌نام اولیه مجاز است؟ فقط وقتی اجرا می‌شود که هیچ کاربری
 * وجود نداشته باشد و گیت ثبت‌نام بسته نشده باشد.
 */
function auth_registration_available(?mysqli $db = null): bool
{
    $db = $db ?? $GLOBALS['mysqli'];
    $settings = auth_settings_get($db);
    if ((int) ($settings['registration_locked'] ?? 0) === 1) {
        return false;
    }
    return auth_user_count($db) === 0;
}


/**
 * ایجاد اولین کاربر — تراکنش اتمیک + قفل ردیف تنظیمات.
 *
 * حتی اگر دو درخواست همزمان به ثبت‌نام برسند، یکی قفل (FOR UPDATE) را
 * اول می‌گیرد، کاربر را می‌سازد و گیت را می‌بندد؛ درخواست دوم بعد از بیدار
 * شدن، یا قفل است یا کاربر موجود/گیت بسته را می‌بیند و رد می‌شود.
 */
function auth_user_create(array $input, ?mysqli $db = null): array
{
    $db = $db ?? $GLOBALS['mysqli'];
    $username = auth_username_normalize((string) ($input['username'] ?? ''));
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $passwordConfirm = (string) ($input['password_confirmation'] ?? '');

    if (!auth_username_is_valid($username)) {
        return ['ok' => false, 'message' => 'نام کاربری باید ۳ تا ۶۴ کاراکتر و فقط شامل حروف انگلیسی، عدد، نقطه، خط تیره یا زیرخط باشد.'];
    }
    if ($firstName === '' || mb_strlen($firstName) > 120) {
        return ['ok' => false, 'message' => 'نام وارد شده معتبر نیست.'];
    }
    if ($lastName === '' || mb_strlen($lastName) > 120) {
        return ['ok' => false, 'message' => 'نام خانوادگی وارد شده معتبر نیست.'];
    }
    if (auth_password_is_valid($password) === false) {
        return ['ok' => false, 'message' => 'رمز عبور باید حداقل ۸ کاراکتر و شامل حروف و عدد باشد.'];
    }
    if ($password !== $passwordConfirm) {
        return ['ok' => false, 'message' => 'تکرار رمز عبور با رمز عبور مطابقت ندارد.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $db->begin_transaction();

        /* قفل ردیف تنظیمات: ثبت‌نام‌های همزمان سریال می‌شوند. */
        $stmt = $db->prepare('SELECT registration_locked FROM auth_settings WHERE id = 1 FOR UPDATE');
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $locked = $row ? (int) ($row['registration_locked'] ?? 0) : 0;
        if ($locked === 1 || auth_user_count($db) > 0) {
            $db->rollback();
            return ['ok' => false, 'message' => 'ثبت‌نام فقط برای اولین کاربر مجاز است.'];
        }

        $stmt = $db->prepare('INSERT INTO users (username, first_name, last_name, password_hash) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $username, $firstName, $lastName, $hash);
        if (!$stmt->execute()) {
            $db->rollback();
            return ['ok' => false, 'message' => 'این نام کاربری قبلاً ثبت شده است.'];
        }
        $userId = (int) $stmt->insert_id;
        $stmt->close();

        $stmt = $db->prepare('UPDATE auth_settings SET registration_locked = 1 WHERE id = 1');
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return ['ok' => true, 'message' => 'حساب کاربری ایجاد شد.', 'user_id' => $userId];
    } catch (Throwable $e) {
        try {
            $db->rollback();
        } catch (Throwable $ignored) {
        }
        return ['ok' => false, 'message' => 'خطا در ایجاد حساب کاربری. لطفاً دوباره تلاش کنید.'];
    }
}

/** به‌روزرسانی نام/نام خانوادگی کاربر. */
function auth_user_update_profile(int $userId, string $firstName, string $lastName, ?mysqli $db = null): array
{
    $db = $db ?? $GLOBALS['mysqli'];
    $firstName = trim($firstName);
    $lastName = trim($lastName);

    if ($firstName === '' || mb_strlen($firstName) > 120) {
        return ['ok' => false, 'message' => 'نام وارد شده معتبر نیست.'];
    }
    if ($lastName === '' || mb_strlen($lastName) > 120) {
        return ['ok' => false, 'message' => 'نام خانوادگی وارد شده معتبر نیست.'];
    }

    $stmt = $db->prepare('UPDATE users SET first_name = ?, last_name = ? WHERE id = ?');
    $stmt->bind_param('ssi', $firstName, $lastName, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok
        ? ['ok' => true, 'message' => 'مشخصات حساب با موفقیت ذخیره شد.']
        : ['ok' => false, 'message' => 'خطا در ذخیره‌سازی مشخصات.'];
}

/**
 * تغییر رمز عبور — نیازمند رمز فعلی (بررسی سمت سرور).
 * سپس رمز جدید به صورت امن hash می‌شود.
 */
function auth_user_change_password(int $userId, string $currentPassword, string $newPassword, string $newPasswordConfirmation, ?mysqli $db = null): array
{
    $db = $db ?? $GLOBALS['mysqli'];
    $user = auth_user_by_id($userId, $db);
    if ($user === null) {
        return ['ok' => false, 'message' => 'کاربر یافت نشد.'];
    }

    if (!password_verify($currentPassword, (string) $user['password_hash'])) {
        return ['ok' => false, 'message' => 'رمز عبور فعلی اشتباه است.'];
    }
    if (auth_password_is_valid($newPassword) === false) {
        return ['ok' => false, 'message' => 'رمز عبور جدید باید حداقل ۸ کاراکتر و شامل حروف و عدد باشد.'];
    }
    if ($newPassword !== $newPasswordConfirmation) {
        return ['ok' => false, 'message' => 'تکرار رمز عبور جدید مطابقت ندارد.'];
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok
        ? ['ok' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.']
        : ['ok' => false, 'message' => 'خطا در تغییر رمز عبور.'];
}

/** به‌روزرسانی مسیر تصویر پروفایل کاربر. */
function auth_user_set_avatar(int $userId, ?string $avatarPath, ?mysqli $db = null): bool
{
    $db = $db ?? $GLOBALS['mysqli'];
    $stmt = $db->prepare('UPDATE users SET avatar_path = ? WHERE id = ?');
    $stmt->bind_param('si', $avatarPath, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** شناسایی نوع واقعی فایل بر اساس magic bytes (نه Content-Type مرورگر). */
function auth_mime_sniff(string $contents): string
{
    if (substr($contents, 0, 8) === "\x89PNG\x0d\x0a\x1a\x0a") {
        return 'image/png';
    }
    if (substr($contents, 0, 3) === "\xff\xd8\xff") {
        return 'image/jpeg';
    }
    if (substr($contents, 0, 6) === 'GIF87a' || substr($contents, 0, 6) === 'GIF89a') {
        return 'image/gif';
    }
    if (substr($contents, 0, 4) === 'RIFF' && substr($contents, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return 'application/octet-stream';
}

/**
 * پردازش و ذخیره‌سازی امن تصویر پروفایل.
 *
 * - حجم، نوع واقعی (magic bytes)، یکپارچگی با GD و ابعاد بررسی می‌شود.
 * - خروجی همیشه به PNG بازتولید می‌شود (حذف هرگونه محتوای آلوده/اجرایی).
 * - نام فایل تصادفی است تا از path traversal و اجرای کد جلوگیری شود.
 */
function auth_avatar_process(array $file): array
{
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'فایلی انتخاب نشده است.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'message' => 'حجم فایل انتخابی بیش از حد مجاز است.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'آپلود فایل ناموفق بود.'];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => 'فایل معتبر نیست.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > AUTH_AVATAR_MAX_BYTES) {
        return ['ok' => false, 'message' => 'حجم تصویر باید کمتر از ۲ مگابایت باشد.'];
    }

    $contents = file_get_contents($file['tmp_name']);
    if ($contents === false || $contents === '') {
        return ['ok' => false, 'message' => 'فایل قابل خواندن نیست.'];
    }

    $mime = auth_mime_sniff($contents);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
        return ['ok' => false, 'message' => 'فقط تصاویر PNG ، JPEG ، GIF یا WEBP مجاز هستند.'];
    }

    $im = imagecreatefromstring($contents);
    if ($im === false) {
        return ['ok' => false, 'message' => 'فایل انتخابی یک تصویر معتبر نیست.'];
    }

    $w = imagesx($im);
    $h = imagesy($im);
    if ($w <= 0 || $h <= 0 || $w > AUTH_AVATAR_MAX_DIMENSION || $h > AUTH_AVATAR_MAX_DIMENSION) {
        imagedestroy($im);
        return ['ok' => false, 'message' => 'ابعاد تصویر باید حداکثر ۱۲۰۰×۱۲۰۰ پیکسل باشد.'];
    }

    $uploadBase = realpath(__DIR__ . '/../..') . '/uploads';
    if (!is_dir($uploadBase)) {
        if (!mkdir($uploadBase, 0755, true) && !is_dir($uploadBase)) {
            imagedestroy($im);
            return ['ok' => false, 'message' => 'خطا در آماده‌سازی پوشه تصاویر.'];
        }
    }
    if (!is_writable($uploadBase)) {
        imagedestroy($im);
        return ['ok' => false, 'message' => 'پوشه تصاویر قابل نوشتن نیست.'];
    }

    $name = 'avatar_' . bin2hex(random_bytes(16)) . '.png';
    $target = $uploadBase . '/' . $name;
    $ok = imagepng($im, $target, 9);
    imagedestroy($im);

    if (!$ok) {
        @unlink($target);
        return ['ok' => false, 'message' => 'خطا در ذخیره‌سازی تصویر.'];
    }

    return ['ok' => true, 'message' => 'تصویر پروفایل ذخیره شد.', 'path' => 'assets/uploads/' . $name];
}

/**
 * حذف امن فایل قبلی تصویر پروفایل.
 * فقط فایل‌هایی که خودمان با الگوی avatar_<hex>.png ساخته‌ایم حذف می‌شوند.
 */
function auth_avatar_delete(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }
    $name = basename((string) $path);
    if (preg_match('/^avatar_[0-9a-f]{32}\.png$/', $name) !== 1) {
        return;
    }
    $filesDir = realpath(__DIR__ . '/../..') . '/uploads';
    $full = $filesDir . '/' . $name;
    if (is_file($full)) {
        @unlink($full);
    }
}

