<?php
/**
 * security_settings.php — تنظیمات کپچا و سیاست ضد جستجوی رمز
 *
 * GET action=get  → خواندن تنظیمات جاری (فقط مدیر)
 * POST (save)     → ذخیره‌سازی با اعتبارسنجی سمت سرور (CSRF الزامی)
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';

$action = (string) ($_POST['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'save' : 'get'));

if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(true, '', ['settings' => auth_settings_get()]);
}

/* هر تغییر حالت فقط با POST + CSRF */
require_csrf_or_fail();

if ($action === 'save') {
    $result = auth_settings_save($_POST);
    if (!$result['ok']) {
        respond_error($result['message'], 422);
    }
    respond_json(true, $result['message'], ['settings' => $result['settings']]);
}

respond_error('عملیات نامعتبر است.');
