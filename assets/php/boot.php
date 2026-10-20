<?php
/**
 * boot.php — bootstrap مشترک تمام Endpointهای backend
 *
 * مسئولیت‌ها:
 *  - session_start() + توابع CSRF (از طریق auth.php)
 *  - اجباری‌سازی ورود (require_login)
 *  - helper پاسخ JSON استاندارد
 *
 * ترتیب بارگذاری در هر Endpoint:
 *   1) boot.php
 *   2) db.php
 *   3) invoice_common.php (در صورت نیاز)
 */

require_once __DIR__ . '/auth.php';

require_login();

/**
 * پاسخ JSON استاندارد پروژه + خروج
 *
 * @param bool   $success
 * @param string $message
 * @param mixed  $data
 * @param int    $status کد HTTP
 */
function respond_json(bool $success, string $message = '', $data = null, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
    }
    $payload = ['success' => $success];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * برای رشد سریع: پاسخ خطای JSON
 */
function respond_error(string $message, int $status = 400): void
{
    respond_json(false, $message, null, $status);
}

/**
 * الزام CSRF برای درخواست‌های تغییردهنده (POST)
 */
function require_csrf_or_fail(): void
{
    if (!csrf_verify()) {
        respond_json(false, 'توکن امنیتی (CSRF) نامعتبر است.', null, 403);
    }
}
