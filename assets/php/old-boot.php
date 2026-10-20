<?php
/**
 * boot.php — bootstrap مشترک تمام Endpointهای backend
 *
 * مسئولیت‌ها:
 *  - session_start()
 *  - توکن CSRF و اعتبارسنجی آن
 *  - helper پاسخ JSON استاندارد
 *
 * ترتیب بارگذاری در هر Endpoint:
 *   1) boot.php
 *   2) db.php
 *   3) invoice_common.php (در صورت نیاز)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * تولید / بازگرداندن توکن CSRF جلسه
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * اعتبارسنجی توکن CSRF (مقایسه زمان-امن)
 */
function csrf_verify(?string $token = null): bool
{
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }
    $stored = $_SESSION['csrf_token'] ?? '';
    return is_string($token)
        && $token !== ''
        && is_string($stored)
        && $stored !== ''
        && hash_equals($stored, $token);
}

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
