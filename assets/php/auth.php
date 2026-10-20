<?php
/**
 * auth.php — احراز هویت مبتنی بر دیتابیس + نشست امن + توکن CSRF
 *
 * مسئولیت‌ها:
 *  - شروع session با کوکی‌های امن (HttpOnly + SameSite=Lax)
 *  - تولید/اعتبارسنجی توکن CSRF (مشترک برای login/register و boot)
 *  - ورود/خروج با پشتیبانی جدول users (password_hash / password_verify)
 *  - محافظت پایدار در برابر حمله‌ی جستجوی رمز (auth_attempts)
 *  - نگهبان احراز هویت صفحات (require_login)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth/auth_users.php';
require_once __DIR__ . '/auth/auth_rate_limiter.php';
require_once __DIR__ . '/auth/auth_settings.php';
require_once __DIR__ . '/captcha/captcha_provider.php';
require_once __DIR__ . '/captcha/pure_captcha_provider.php';
require_once __DIR__ . '/captcha/captcha_service.php';

if (session_status() === PHP_SESSION_NONE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/* ==================== CSRF (مشترک login/register + boot) ==================== */

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

/* ==================== احراز هویت ==================== */

/**
 * دلیل آخرین ورود ناموفق: 'locked' | 'invalid'
 */
function auth_last_failure(): string
{
    return isset($GLOBALS['AUTH_LAST_FAILURE']) ? (string) $GLOBALS['AUTH_LAST_FAILURE'] : 'invalid';
}

function auth_client_ip(): string
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', (string) $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * آیا کاربر وارد شده است؟ (بر اساس session؛ بدون کوئری دیتابیس)
 *
 * کلید جدید auth_user_id معیار اصلی است؛ کلید قدیمی auth_user برای
 * سازگاری با جلسات/تست‌های قبلی همچنان پذیرفته می‌شود.
 */
function is_authenticated(): bool
{
    $id = $_SESSION['auth_user_id'] ?? null;
    if ($id !== null) {
        $int = filter_var($id, FILTER_VALIDATE_INT);
        if ($int !== false && $int > 0) {
            return true;
        }
    }

    $legacy = $_SESSION['auth_user'] ?? null;
    if (is_string($legacy) && $legacy !== '') {
        return true;
    }

    return false;
}

/** شناسه‌ی کاربر واردشده (فقط برای جلسات جدید). */
function auth_user_id(): ?int
{
    if (!is_authenticated()) {
        return null;
    }
    $id = $_SESSION['auth_user_id'] ?? null;
    $int = filter_var($id, FILTER_VALIDATE_INT);
    if ($int !== false && $int > 0) {
        return (int) $int;
    }
    return null;
}

/** رکورد کامل کاربر واردشده (یا null برای جلسات قدیمی). */
function current_user(): ?array
{
    $id = auth_user_id();
    if ($id === null) {
        return null;
    }
    return auth_user_by_id($id);
}

/** هش فیک برای جلوگیری از افشای وجود کاربر از طریق زمان پاسخ‌دهی. */
function auth_dummy_verify(string $password): void
{
    if (!isset($GLOBALS['AUTH_DUMMY_HASH'])) {
        $GLOBALS['AUTH_DUMMY_HASH'] = password_hash(
            'dummy-verify-' . bin2hex(random_bytes(4)),
            PASSWORD_DEFAULT
        );
    }
    password_verify($password, (string) $GLOBALS['AUTH_DUMMY_HASH']);
}


/**
 * ورود با نام کاربری/رمز عبور دیتابیس.
 *
 * - ابتدا قفلِ نام کاربری و IP بررسی می‌شود (ضد جستجوی رمز).
 * - رمز فقط با password_verify بررسی می‌شود؛ هیچ اعتبار سخت‌کد شده‌ای وجود ندارد.
 * - بعد از موفقیت، شناسه‌ی session بازتولید و فقط شناسه‌ی کاربر ذخیره می‌شود.
 */
function authenticate(string $username, string $password): bool
{
    global $mysqli;

    $GLOBALS['AUTH_LAST_FAILURE'] = 'invalid';

    $username = auth_username_normalize($username);
    $ip = auth_client_ip();

    if ($username === '' || $password === '') {
        return false;
    }

    /* قفل مبتنی بر نام کاربری و IP (کاملاً پایدار و مستقل از session) */
    $userLock = auth_rate_lock_status('login:username', $username, $mysqli);
    if ($userLock['locked']) {
        $GLOBALS['AUTH_LAST_FAILURE'] = 'locked';
        return false;
    }
    $ipLock = auth_rate_lock_status('login:ip', $ip, $mysqli);
    if ($ipLock['locked']) {
        $GLOBALS['AUTH_LAST_FAILURE'] = 'locked';
        return false;
    }

    /* پاکسازی سبک و احتمال‌محور رکوردهای قدیمی */
    if (random_int(1, 100) === 1) {
        auth_rate_cleanup(7, $mysqli);
    }

    $user = auth_user_by_username($username, $mysqli);

    if ($user !== null && (int) ($user['is_active'] ?? 1) === 1) {
        $valid = password_verify($password, (string) $user['password_hash']);
    } else {
        auth_dummy_verify($password);
        $valid = false;
    }

    if (!$valid) {
        auth_rate_record('login:username', $username, 'fail', $mysqli);
        auth_rate_record('login:ip', $ip, 'fail', $mysqli);
        return false;
    }

    /* ورود موفق: ثبت و پاک‌سازی سوابق این حساب */
    auth_rate_record('login:username', $username, 'success', $mysqli);
    auth_rate_clear('login:username', $username, $mysqli);

    session_regenerate_id(true);
    $_SESSION['auth_user_id'] = (int) $user['id'];
    $_SESSION['auth_user'] = (string) $user['username'];

    return true;
}


function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}

function require_login(): void
{
    if (is_authenticated()) {
        return;
    }

    $script = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($script === 'login.php' || $script === 'register.php') {
        return;
    }

    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $expectsJson = $isAjax || str_contains($accept, 'application/json');

    if ($expectsJson) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'success' => false,
            'message' => 'برای انجام این عملیات باید وارد حساب کاربری شوید.',
            'redirect' => 'login.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
    if ($requestMethod !== 'GET') {
        $next = 'index.php';
    }

    header('Location: login.php?next=' . rawurlencode($next));
    exit;
}
