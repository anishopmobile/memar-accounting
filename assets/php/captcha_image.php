<?php
/**
 * captcha_image.php — تولید محلی تصویر کپچا (عمومی)
 *
 * کاملاً محلی است: تصویر توسط کتابخانه‌ی داخل پروژه (pure-captcha) و با
 * GD سرور روی همین PHP ساخته می‌شود؛ هیچ وابستگی خارجی/CDN وجود ندارد.
 * پاسخ هرگز در کش مرورگر ذخیره نمی‌شود و کد پاسخ هرگز در خروجی قرار نمی‌گیرد.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$ip = auth_client_ip();

/* محافظت سبک در برابر سوءاستفاده/غرق‌سازی تولید تصویر */
$policy = auth_rate_policy();
$count = auth_rate_count('captcha:ip', $ip, $policy['window_seconds'], 'success');
if ($count >= 120) {
    if (!headers_sent()) {
        http_response_code(429);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    exit;
}
auth_rate_record('captcha:ip', $ip, 'success');

if (random_int(1, 100) === 1) {
    auth_rate_cleanup(7);
}

$service = CaptchaService::instance();
$service->renderImage();
