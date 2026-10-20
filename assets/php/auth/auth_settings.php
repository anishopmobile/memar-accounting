<?php
/**
 * auth_settings.php — تنظیمات کپچا و سیاست ضد جستجوی رمز
 *
 * ذخیره‌سازی طبق معمول ماژول‌های دیگر برنامه: یک ردیف با id=1.
 * تمام گزینه‌های کپچا پیش از قرار گرفتن در تنظیمات از سورس نصب‌شده‌ی
 * کتابخانه pure-captcha تأیید شده‌اند.
 */

declare(strict_types=1);

/** مقادیر پیش‌فرض — هم‌پوشانی با مقادیر واقعی جدول. */
const AUTH_SETTINGS_DEFAULTS = [
    'registration_locked'           => 0,
    'captcha_provider'              => 'pure',
    'login_captcha_enabled'         => 0,
    'registration_captcha_enabled'  => 0,
    'captcha_lang'                  => 'fa',
    'captcha_char_type'             => 'mixed',
    'captcha_length'                => 5,
    'captcha_width'                 => 170,
    'captcha_height'                => 55,
    'captcha_font_size'             => 22,
    'captcha_noise_level'           => 'medium',
    'captcha_case_sensitive'        => 0,
    'captcha_expiry'                => 180,
    'brute_max_attempts'            => 5,
    'brute_window_minutes'          => 15,
    'brute_lockout_minutes'         => 15,
];

/*
 * کلیدهای واقعی و تأییدشده‌ی سورس نصب‌شده:
 *  - CHAR_SETS کلیدهای «mixed», «lettersB», «lettersS», «numbers», «characters»
 *    دارد؛ گزینه‌ی «letters» در مستندات ذکر شده اما در سورس وجود ندارد و به
 *    mixed برمی‌گردد، بنابراین اینجا فقط مقادیر واقعی سورس ارائه می‌شود.
 *  - NOISE کلیدهای «low», «medium», «high», «extreme», «nightmare» را دارد.
 */
const AUTH_CAPTCHA_PROVIDERS = ['pure'];
const AUTH_CAPTCHA_LANGS = ['fa', 'en'];
const AUTH_CAPTCHA_CHAR_TYPES = ['mixed', 'numbers', 'characters', 'lettersB', 'lettersS'];
const AUTH_CAPTCHA_NOISE_LEVELS = ['low', 'medium', 'high', 'extreme', 'nightmare'];

/** خواندن تنظیمات جاری امنیت/کپچا. */
function auth_settings_get(?mysqli $db = null): array
{
    $db = $db ?? $GLOBALS['mysqli'];
    $row = null;

    $stmt = $db->prepare('SELECT * FROM auth_settings WHERE id = 1 LIMIT 1');
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row) || $row === []) {
        $stmt = $db->prepare('INSERT IGNORE INTO auth_settings (id) VALUES (1)');
        $stmt->execute();
        $stmt->close();
        return AUTH_SETTINGS_DEFAULTS;
    }


    return array_merge(AUTH_SETTINGS_DEFAULTS, $row);
}

/**
 * اعتبارسنجی و ذخیره‌سازی تنظیمات امنیت/کپچا.
 * همه‌ی مقادیر سمت سرور اعتبارسنجی و به محدوده‌های امن محدود می‌شوند.
 */
function auth_settings_save(array $input, ?mysqli $db = null): array
{
    $db = $db ?? $GLOBALS['mysqli'];
    $errors = [];

    $provider = (string) ($input['captcha_provider'] ?? 'pure');
    if (!in_array($provider, AUTH_CAPTCHA_PROVIDERS, true)) {
        $errors[] = 'ارائه‌دهنده‌ی کپچا نامعتبر است.';
    }

    $loginEnabled = !empty($input['login_captcha_enabled']) ? 1 : 0;
    $regEnabled = !empty($input['registration_captcha_enabled']) ? 1 : 0;

    $lang = (string) ($input['captcha_lang'] ?? 'fa');
    if (!in_array($lang, AUTH_CAPTCHA_LANGS, true)) {
        $errors[] = 'زبان کپچا نامعتبر است.';
    }

    $charType = (string) ($input['captcha_char_type'] ?? 'mixed');
    if (!in_array($charType, AUTH_CAPTCHA_CHAR_TYPES, true)) {
        $errors[] = 'نوع کاراکتر کپچا نامعتبر است.';
    }

    $noise = (string) ($input['captcha_noise_level'] ?? 'medium');
    if (!in_array($noise, AUTH_CAPTCHA_NOISE_LEVELS, true)) {
        $errors[] = 'میزان نویز کپچا نامعتبر است.';
    }

    $length = (int) ($input['captcha_length'] ?? 5);
    if ($length < 4 || $length > 8) {
        $errors[] = 'تعداد کاراکتر کپچا باید بین ۴ تا ۸ باشد.';
    }

    $width = (int) ($input['captcha_width'] ?? 170);
    if ($width < 120 || $width > 500) {
        $errors[] = 'عرض کپچا باید بین ۱۲۰ تا ۵۰۰ پیکسل باشد.';
    }

    $height = (int) ($input['captcha_height'] ?? 55);
    if ($height < 40 || $height > 160) {
        $errors[] = 'ارتفاع کپچا باید بین ۴۰ تا ۱۶۰ پیکسل باشد.';
    }

    $fontSize = (int) ($input['captcha_font_size'] ?? 22);
    if ($fontSize < 12 || $fontSize > 72) {
        $errors[] = 'اندازه فونت کپچا باید بین ۱۲ تا ۷۲ باشد.';
    }

    $caseSensitive = !empty($input['captcha_case_sensitive']) ? 1 : 0;

    $expiry = (int) ($input['captcha_expiry'] ?? 180);
    if ($expiry < 60 || $expiry > 900) {
        $errors[] = 'مدت اعتبار کپچا باید بین ۶۰ تا ۹۰۰ ثانیه باشد.';
    }

    $maxAttempts = (int) ($input['brute_max_attempts'] ?? 5);
    if ($maxAttempts < 3 || $maxAttempts > 50) {
        $errors[] = 'حداکثر تلاش ناموفق باید بین ۳ تا ۵۰ باشد.';
    }

    $windowMinutes = (int) ($input['brute_window_minutes'] ?? 15);
    if ($windowMinutes < 1 || $windowMinutes > 60) {
        $errors[] = 'بازه‌ی شمارش تلاش‌ها باید بین ۱ تا ۶۰ دقیقه باشد.';
    }

    $lockoutMinutes = (int) ($input['brute_lockout_minutes'] ?? 15);
    if ($lockoutMinutes < 1 || $lockoutMinutes > 120) {
        $errors[] = 'مدت قفل باید بین ۱ تا ۱۲۰ دقیقه باشد.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'message' => implode(' ', $errors), 'errors' => $errors];
    }

    $stmt = $db->prepare(
        'UPDATE auth_settings SET
            captcha_provider = ?, login_captcha_enabled = ?, registration_captcha_enabled = ?,
            captcha_lang = ?, captcha_char_type = ?, captcha_length = ?, captcha_width = ?,
            captcha_height = ?, captcha_font_size = ?, captcha_noise_level = ?,
            captcha_case_sensitive = ?, captcha_expiry = ?, brute_max_attempts = ?,
            brute_window_minutes = ?, brute_lockout_minutes = ?
         WHERE id = 1'
    );
    $stmt->bind_param(
        'siissiiiisiiiii',
        $provider,
        $loginEnabled,
        $regEnabled,
        $lang,
        $charType,
        $length,
        $width,
        $height,
        $fontSize,
        $noise,
        $caseSensitive,
        $expiry,
        $maxAttempts,
        $windowMinutes,
        $lockoutMinutes
    );
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'خطا در ذخیره‌سازی تنظیمات امنیت.'];
    }

    return [
        'ok' => true,
        'message' => 'تنظیمات امنیت با موفقیت ذخیره شد.',
        'settings' => auth_settings_get($db),
    ];
}

