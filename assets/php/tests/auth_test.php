<?php
/**
 * auth_test.php — تست جامع سامانه احراز هویت و امنیت حساب
 *
 * روی دیتابیس جاری dev اجرا می‌شود (مانند سایر تست‌های پروژه) و در پایان
 * وضعیت اولیه‌ی کاربران/تلاش‌ها/تنظیمات را دقیقاً بازمی‌گرداند.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$GLOBALS['T_PASS'] = 0;
$GLOBALS['T_FAIL'] = 0;
$GLOBALS['T_TOTAL'] = 0;

function t_check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS['T_TOTAL']++;
    if ($ok) {
        $GLOBALS['T_PASS']++;
        echo "  ✓ $name\n";
    } else {
        $GLOBALS['T_FAIL']++;
        echo "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/* ---------- ثبت وضعیت اولیه برای بازگردانی ---------- */
$origUsers = [];
$origAttemptRows = [];
$r = $mysqli->query('SELECT * FROM users');
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $origUsers[] = $row;
    }
}
$origSettings = $mysqli->query('SELECT * FROM auth_settings WHERE id = 1')->fetch_assoc();
$r = $mysqli->query('SELECT * FROM auth_attempts');
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $origAttemptRows[] = $row;
    }
}

/** بازگردانی کامل وضعیت اولیه (کاربران، تلاش‌ها، تنظیمات). */
function auth_test_restore(): void
{
    global $mysqli, $origUsers, $origSettings, $origAttemptRows;

    $mysqli->query('DELETE FROM users');
    $mysqli->query('DELETE FROM auth_attempts');

    foreach ($origUsers as $u) {
        $stmt = $mysqli->prepare(
            'INSERT INTO users (id, username, first_name, last_name, password_hash, avatar_path, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $vId = $u['id'];
        $vUsername = $u['username'];
        $vFirst = $u['first_name'];
        $vLast = $u['last_name'];
        $vHash = $u['password_hash'];
        $vAvatar = $u['avatar_path'];
        $vActive = (int) $u['is_active'];
        $vCreated = $u['created_at'];
        $vUpdated = $u['updated_at'];
        $stmt->bind_param(
            'isssssiis',
            $vId,
            $vUsername,
            $vFirst,
            $vLast,
            $vHash,
            $vAvatar,
            $vActive,
            $vCreated,
            $vUpdated
        );
        $stmt->execute();
        $stmt->close();
    }

    if (is_array($origSettings)) {
        $stmt = $mysqli->prepare(
            'UPDATE auth_settings SET
                registration_locked = ?, captcha_provider = ?, login_captcha_enabled = ?,
                registration_captcha_enabled = ?, captcha_lang = ?, captcha_char_type = ?,
                captcha_length = ?, captcha_width = ?, captcha_height = ?, captcha_font_size = ?,
                captcha_noise_level = ?, captcha_case_sensitive = ?, captcha_expiry = ?,
                brute_max_attempts = ?, brute_window_minutes = ?, brute_lockout_minutes = ?
             WHERE id = 1'
        );
        $rLock = (int) $origSettings['registration_locked'];
        $rProvider = $origSettings['captcha_provider'];
        $rLogin = (int) $origSettings['login_captcha_enabled'];
        $rReg = (int) $origSettings['registration_captcha_enabled'];
        $rLang = $origSettings['captcha_lang'];
        $rCharType = $origSettings['captcha_char_type'];
        $rLength = (int) $origSettings['captcha_length'];
        $rWidth = (int) $origSettings['captcha_width'];
        $rHeight = (int) $origSettings['captcha_height'];
        $rFontSize = (int) $origSettings['captcha_font_size'];
        $rNoise = $origSettings['captcha_noise_level'];
        $rCase = (int) $origSettings['captcha_case_sensitive'];
        $rExpiry = (int) $origSettings['captcha_expiry'];
        $rMax = (int) $origSettings['brute_max_attempts'];
        $rWindow = (int) $origSettings['brute_window_minutes'];
        $rLockout = (int) $origSettings['brute_lockout_minutes'];
        $stmt->bind_param(
            'isiissiiiisiiiii',
            $rLock,
            $rProvider,
            $rLogin,
            $rReg,
            $rLang,
            $rCharType,
            $rLength,
            $rWidth,
            $rHeight,
            $rFontSize,
            $rNoise,
            $rCase,
            $rExpiry,
            $rMax,
            $rWindow,
            $rLockout
        );
        $stmt->execute();
        $stmt->close();
    }

    foreach ($origAttemptRows as $row) {
        $stmt = $mysqli->prepare(
            'INSERT INTO auth_attempts (id, bucket, bucket_key, outcome, attempted_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $aId = $row['id'];
        $aBucket = $row['bucket'];
        $aKey = $row['bucket_key'];
        $aOutcome = $row['outcome'];
        $aAt = $row['attempted_at'];
        $stmt->bind_param(
            'issss',
            $aId,
            $aBucket,
            $aKey,
            $aOutcome,
            $aAt
        );
        $stmt->execute();
        $stmt->close();
    }
}


register_shutdown_function(function () {
    try {
        auth_test_restore();
    } catch (Throwable $e) {
    }
});

/* ---------- پیش‌شرط: حالت بکر برای اجرای قطعی تست ---------- */
$mysqli->query('DELETE FROM users');
$mysqli->query('DELETE FROM auth_attempts');
$stmt = $mysqli->prepare(
    'UPDATE auth_settings SET registration_locked = 0, login_captcha_enabled = 0,
     registration_captcha_enabled = 0, brute_max_attempts = 5, brute_window_minutes = 15,
     brute_lockout_minutes = 15 WHERE id = 1'
);
$stmt->execute();
$stmt->close();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];

/*
 * بافر خروجی می‌گذاریم تا session_regenerate_id() و session_id() داخل
 * authenticate() (که خروجی چاپ می‌کنند) در حالت CLI با مشکل
 * «headers already sent» مواجه نشوند.
 */
ob_start();

echo "== 1) ثبت‌نام اولیه ==\n";

t_check('initial registration available (zero users)', auth_registration_available() === true);

$r = auth_user_create(['username' => 'ab', 'first_name' => 'ت', 'last_name' => 'ت', 'password' => 'Aa12345678', 'password_confirmation' => 'Aa12345678']);
t_check('short username rejected', !$r['ok']);

$r = auth_user_create(['username' => 'کاربر فارسی', 'first_name' => 'ت', 'last_name' => 'ت', 'password' => 'Aa12345678', 'password_confirmation' => 'Aa12345678']);
t_check('non-ascii username rejected', !$r['ok']);

$r = auth_user_create(['username' => 'testadmin', 'first_name' => 'تست', 'last_name' => 'مدیر', 'password' => 'short', 'password_confirmation' => 'short']);
t_check('weak password rejected', !$r['ok']);

$r = auth_user_create(['username' => 'testadmin', 'first_name' => 'تست', 'last_name' => 'مدیر', 'password' => 'Aa12345678', 'password_confirmation' => 'different123']);
t_check('password confirmation mismatch rejected', !$r['ok']);

$r = auth_user_create(['username' => 'TestAdmin', 'first_name' => 'تست', 'last_name' => 'مدیر', 'password' => 'Aa12345678', 'password_confirmation' => 'Aa12345678']);
$uid = $r['user_id'] ?? 0;
t_check('valid registration succeeds', $r['ok'] === true, $r['message'] ?? '');
t_check('username normalized to lowercase', auth_user_by_id($uid, $mysqli) !== null && auth_user_by_id($uid, $mysqli)['username'] === 'testadmin');

$row = auth_user_by_username('testadmin', $mysqli);
t_check('password stored hashed not plaintext', $row !== null && $row['password_hash'] !== 'Aa12345678' && strncmp($row['password_hash'], '$', 1) === 0);
t_check('stored hash verifies', $row !== null && password_verify('Aa12345678', $row['password_hash']));
t_check('no auto-login after registration (is_authenticated false)', is_authenticated() === false);

$s = auth_settings_get($mysqli);
t_check('registration gate locked after first user', (int) $s['registration_locked'] === 1);
t_check('registration no longer available', auth_registration_available() === false);

$r2 = auth_user_create(['username' => 'seconduser', 'first_name' => 'دوم', 'last_name' => 'کاربر', 'password' => 'Bb23456789', 'password_confirmation' => 'Bb23456789']);
t_check('second registration rejected', !$r2['ok']);
t_check('no second user created', auth_user_count($mysqli) === 1);

/* حذف همه‌ی کاربران نباید دوباره ثبت‌نام عمومی را فعال کند */
$mysqli->query('DELETE FROM users');
t_check('registration stays locked after all users removed', auth_registration_available() === false);

/* بازگشت به حالت بکر برای تست‌های بعدی */
$stmt = $mysqli->prepare('UPDATE auth_settings SET registration_locked = 0 WHERE id = 1');
$stmt->execute();
$stmt->close();
t_check('manual unlock allows fresh bootstrap again', auth_registration_available() === true);

echo "\n== 2) همزمانی ثبت‌نام اولیه ==\n";

$mysqli->query('DELETE FROM users');

$conn1 = new mysqli('127.0.0.1', 'root', '', 'memar_accounting');
$conn1->set_charset('utf8mb4');

/* قفل ردیف تنظیمات روی conn1 (شبیه‌سازی ثبت‌نام در حال انجام) */
$conn1->begin_transaction();
$lk = $conn1->prepare('SELECT id FROM auth_settings WHERE id = 1 FOR UPDATE');
$lk->execute();
$lk->close();

$conn2 = new mysqli('127.0.0.1', 'root', '', 'memar_accounting');
$conn2->set_charset('utf8mb4');
$conn2->query('SET SESSION innodb_lock_wait_timeout = 1');

$attempt = auth_user_create([
    'username' => 'raceuser',
    'first_name' => 'رقیب',
    'last_name' => 'همزمان',
    'password' => 'Cc34567890',
    'password_confirmation' => 'Cc34567890',
], $conn2);

t_check('concurrent registration blocked while gate locked', !$attempt['ok'] || auth_user_count($conn2) === 0);
t_check('blocked concurrent request did not create a user', auth_user_count($conn2) === 0);

/* اولین ثبت‌نام روی conn1 زیر همان قفل */
$ins = $conn1->prepare('INSERT INTO users (username, first_name, last_name, password_hash) VALUES (?, ?, ?, ?)');
$h1 = password_hash('FirstPass123', PASSWORD_DEFAULT);
$uname1 = 'firstone';
$fn1 = 'اول';
$ln1 = 'کاربر';
$ins->bind_param('ssss', $uname1, $fn1, $ln1, $h1);
$ins->execute();
$ins->close();
$conn1->query('UPDATE auth_settings SET registration_locked = 1 WHERE id = 1');
$conn1->commit();

/* تلاش دوم بعد از آزاد شدن قفل → باید رد شود */
$attempt2 = auth_user_create([
    'username' => 'secondrace',
    'first_name' => 'دوم',
    'last_name' => 'همزمان',
    'password' => 'Dd45678901',
    'password_confirmation' => 'Dd45678901',
], $conn2);
t_check('second concurrent registration rejected after first commits', !$attempt2['ok']);
t_check('exactly one user exists after race', auth_user_count($conn2) === 1);

$conn2->close();
$conn1->close();

echo "\n== 3) ورود + محافظت ضد حمله‌ی جستجوی رمز ==\n";

/* ساخت کاربر تست */
$h = password_hash('Correctpass1', PASSWORD_DEFAULT);
$uname = 'loginuser';
$fname = 'ورود';
$lname = 'تست';
$stmt = $mysqli->prepare('INSERT INTO users (username, first_name, last_name, password_hash) VALUES (?, ?, ?, ?)');
$stmt->bind_param('ssss', $uname, $fname, $lname, $h);
$stmt->execute();
$loginUserId = (int) $stmt->insert_id;
$stmt->close();

/* سیاست قفل سریع برای تست: ۳ تلاش در ۱۵ دقیقه */
$stmt = $mysqli->prepare(
    'UPDATE auth_settings SET brute_max_attempts = 3, brute_window_minutes = 15, brute_lockout_minutes = 15 WHERE id = 1'
);
$stmt->execute();
$stmt->close();

t_check('login rejects unknown user (generic)', authenticate('nosuchuser', 'whatever123') === false);
t_check('failure reason is invalid (not locked)', auth_last_failure() === 'invalid');

/* پاک‌سازی سوابق تست کاربر ناموجود تا هر دو سطل (IP و نام کاربری) از صفر پر شوند */
auth_rate_clear('login:username', 'nosuchuser', $mysqli);
auth_rate_clear('login:ip', auth_client_ip(), $mysqli);

/* سه تلاش ناموفق روی نام کاربری واقعی */
for ($i = 1; $i <= 3; $i++) {
    $ok = authenticate('loginuser', 'Wrongpass' . $i);
}
t_check('third failed login returns false', $ok === false);

$nowLocked = auth_rate_lock_status('login:username', 'loginuser', $mysqli);
t_check('username bucket locked after threshold', $nowLocked['locked'] === true);

$ipLocked = auth_rate_lock_status('login:ip', auth_client_ip(), $mysqli);
t_check('ip bucket locked after threshold', $ipLocked['locked'] === true);

/* حتی با رمز درست هم در حالت قفل ورود داده نمی‌شود */
t_check('locked login rejected even with correct password', authenticate('loginuser', 'Correctpass1') === false);
t_check('failure reason is locked', auth_last_failure() === 'locked');

/* پاک کردن session نباید محافظت را دور بزند */
$sidBefore = session_id();
$_SESSION = [];
session_regenerate_id(true);
$newSession = session_id() !== $sidBefore;
$stillLocked = auth_rate_lock_status('login:username', 'loginuser', $mysqli)['locked'];
t_check('session clearing does not bypass lockout (still locked)', $stillLocked === true);

/* انقضای پنجره: قدیمی‌کردن سوابق → قفل آزاد میشود */
$mysqli->query(
    "UPDATE auth_attempts SET attempted_at = NOW() - INTERVAL 20 MINUTE
     WHERE bucket = 'login:username' AND bucket_key = 'loginuser' OR bucket = 'login:ip'"
);
t_check('lockout expires after window', auth_rate_lock_status('login:username', 'loginuser', $mysqli)['locked'] === false);
t_check('ip lockout expires after window', auth_rate_lock_status('login:ip', auth_client_ip(), $mysqli)['locked'] === false);

$beforeRegen = session_id();
$loginOk = authenticate('LoGiNuSeR', 'Correctpass1'); /* املای بزرگ/کوچک باید نرمال شود */
t_check('login succeeds with correct credentials (case-insensitive username)', $loginOk === true);
t_check('session id regenerated after login', session_id() !== $beforeRegen);
t_check('session carries only user id', isset($_SESSION['auth_user_id']) && (int) $_SESSION['auth_user_id'] === $loginUserId);
t_check('is_authenticated true after login', is_authenticated() === true);
t_check('current_user resolves', current_user() !== null && (int) current_user()['id'] === $loginUserId);

/* پاک شدن سوابق ناموفق نام کاربری پس از ورود موفق */
$afterOk = auth_rate_count('login:username', 'loginuser', 3600, 'fail', $mysqli);
t_check('username failure records cleared after success', $afterOk === 0);

echo "\n== 4) تغییر رمز عبور و مشخصات ==\n";

$r = auth_user_change_password($loginUserId, 'Wrongcurrent', 'Newpass1234', 'Newpass1234');
t_check('wrong current password rejected', !$r['ok']);

$r = auth_user_change_password($loginUserId, 'Correctpass1', 'Newpass1234', 'Newpass9999');
t_check('confirmation mismatch rejected', !$r['ok']);

$r = auth_user_change_password($loginUserId, 'Correctpass1', 'short', 'short');
t_check('weak new password rejected', !$r['ok']);

$r = auth_user_change_password($loginUserId, 'Correctpass1', 'Newpass1234', 'Newpass1234');
t_check('password change succeeds', $r['ok'] === true);

$fresh = auth_user_by_id($loginUserId, $mysqli);
t_check('new password verifies', $fresh !== null && password_verify('Newpass1234', $fresh['password_hash']));
t_check('old password no longer verifies', $fresh !== null && !password_verify('Correctpass1', $fresh['password_hash']));

$r = auth_user_update_profile($loginUserId, '', 'مدیر');
t_check('empty first name rejected in profile update', !$r['ok']);
$r = auth_user_update_profile($loginUserId, 'علی', 'رضایی');
t_check('profile update succeeds', $r['ok'] === true);
$fresh = auth_user_by_id($loginUserId, $mysqli);
t_check('profile update persisted', $fresh !== null && $fresh['first_name'] === 'علی' && $fresh['last_name'] === 'رضایی');

/* پاک‌سازی لاکین‌های IP برای تست‌های بعدی */
auth_rate_clear('login:ip', auth_client_ip(), $mysqli);
auth_rate_clear('login:username', 'loginuser', $mysqli);

echo "\n== 5) کپچا (محلی / یکبارمصرف / منقضی) ==\n";

$stmt = $mysqli->prepare(
    'UPDATE auth_settings SET login_captcha_enabled = 1, captcha_char_type = ?, captcha_expiry = 60,
     captcha_case_sensitive = 0, captcha_length = 5 WHERE id = 1'
);
$chars = 'numbers';
$stmt->bind_param('s', $chars);
$stmt->execute();
$stmt->close();

$svc = CaptchaService::instance();
t_check('login captcha enabled', $svc->enabledFor('login') === true);
t_check('registration captcha stays disabled (independent switches)', $svc->enabledFor('registration') === false);

/* تولید و اعتبارسنجی یک‌بارمصرف */
$_SESSION = []; // پاک‌سازی عمدی تا تداخل سشن وجود نداشته باشد
$code = \AminArjmand\PureCaptcha\Captcha::generate();
t_check('captcha challenge stored in session', isset($_SESSION['__captcha_sys']) && is_array($_SESSION['__captcha_sys']));

unset($_SESSION['__captcha_sys']);
$_SESSION['__captcha_sys'] = ['code' => 'ABc12', 'time' => time()];
t_check('captcha validate correct code (case-insensitive)', $svc->validate('abc12') === true);
t_check('captcha is one-time (second validation fails)', $svc->validate('abc12') === false);

unset($_SESSION['__captcha_sys']);
$_SESSION['__captcha_sys'] = ['code' => 'ABc12', 'time' => time()];
t_check('captcha wrong code rejected', $svc->validate('zzzzz') === false);

unset($_SESSION['__captcha_sys']);
$_SESSION['__captcha_sys'] = ['code' => 'ABc12', 'time' => time() - 9999];
t_check('expired captcha rejected', $svc->validate('abc12') === false);

unset($_SESSION['__captcha_sys']);
t_check('missing captcha challenge rejected', $svc->validate('abc12') === false);

/* گزینه‌های پیکربندی — تأیید با سورس نصب‌شده */
$provider = new PureCaptchaProvider([
    'lang' => 'en',
    'length' => 4,
    'char_type' => 'numbers',
    'noise_level' => 'low',
    'width' => 150,
    'height' => 50,
    'font_size' => 20,
    'case_sensitive' => true,
    'expiry' => 90,
]);
$html = $provider->html('assets/php/captcha_image.php', 'captcha', 'fa');
t_check('captcha widget html is local (img points to local endpoint)', str_contains($html, 'assets/php/captcha_image.php'));
t_check('captcha widget html does not leak answer', !str_contains($html, 'code'));
$styles = $provider->styles();
t_check('captcha styles are local inline css', str_contains($styles, '<style>'));

/* بررسی مقادیر ناخواسته در تنظیمات ذخیره نمی‌شود */
$r = auth_settings_save([
    'captcha_provider' => 'pure',
    'login_captcha_enabled' => '1',
    'registration_captcha_enabled' => '1',
    'captcha_lang' => 'fr', // نامعتبر (فقط fa/en)
    'captcha_char_type' => 'letters', // در مستندات هست ولی در سورس نیست
    'captcha_noise_level' => 'easy', // در کامنت کانفیگ هست ولی در سورس نیست
    'captcha_length' => 100,
    'captcha_width' => 10,
    'captcha_height' => 10,
    'captcha_font_size' => 200,
    'captcha_case_sensitive' => '1',
    'captcha_expiry' => 1,
    'brute_max_attempts' => 1,
    'brute_window_minutes' => 0,
    'brute_lockout_minutes' => 0,
]);
t_check('invalid captcha/brute settings rejected wholesale', !$r['ok']);

$r = auth_settings_save([
    'captcha_provider' => 'pure',
    'login_captcha_enabled' => '1',
    'registration_captcha_enabled' => '0',
    'captcha_lang' => 'fa',
    'captcha_char_type' => 'numbers',
    'captcha_noise_level' => 'medium',
    'captcha_length' => 5,
    'captcha_width' => 170,
    'captcha_height' => 55,
    'captcha_font_size' => 22,
    'captcha_case_sensitive' => '1',
    'captcha_expiry' => 180,
    'brute_max_attempts' => 5,
    'brute_window_minutes' => 15,
    'brute_lockout_minutes' => 15,
]);
t_check('valid settings save succeeds', $r['ok'] === true);
$s2 = auth_settings_get($mysqli);
t_check('saved settings persist (login=1 reg=0 case-sensitive=1)',
    (int) $s2['login_captcha_enabled'] === 1
    && (int) $s2['registration_captcha_enabled'] === 0
    && (int) $s2['captcha_case_sensitive'] === 1
    && $s2['captcha_char_type'] === 'numbers');

echo "\n== 6) ابزارهای تصویر پروفایل ==\n";

t_check('mime sniff png', auth_mime_sniff("\x89PNG\x0d\x0a\x1a\x0a" . str_repeat("\x00", 10)) === 'image/png');
t_check('mime sniff jpeg', auth_mime_sniff("\xff\xd8\xff\xe0" . str_repeat("\x00", 10)) === 'image/jpeg');
t_check('mime sniff gif', auth_mime_sniff('GIF89a' . str_repeat("\x00", 10)) === 'image/gif');
t_check('mime sniff webp', auth_mime_sniff("RIFF\x00\x00\x00\x00WEBP") === 'image/webp');
t_check('mime sniff text rejected', auth_mime_sniff('<?php echo "x";') === 'application/octet-stream');

/* auth_avatar_delete فقط فایل‌های تولیدی خودمان را حذف می‌کند */
$uploadsDir = realpath(__DIR__ . '/../..') . '/uploads';
$testAvatar = $uploadsDir . '/avatar_' . str_repeat('a', 32) . '.png';
file_put_contents($testAvatar, 'demo');
t_check('avatar file deletable when matching our pattern', is_file($testAvatar));
auth_avatar_delete('assets/uploads/avatar_' . str_repeat('a', 32) . '.png');
t_check('avatar file removed by delete helper', !is_file($testAvatar));

$evil = $uploadsDir . '/../../db.php';
t_check('delete helper refuses non-avatar paths (no deletion attempt)', auth_avatar_delete('../../db.php') === null || is_file(realpath(__DIR__ . '/../db.php')));

echo "\n== 7) Endpoint حدود CSRF و احراز هویت ==\n";

/** اجرای یک snippet در پروسه‌ی جدا (مانند سایر تست‌های پروژه). */
function auth_run_snippet(string $code): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'auth-ep-');
    file_put_contents($tmp, '<?php ' . $code);
    $out = (string) shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return $out;
}

$secEp = 'c:/laragon/www/memar-accounting/assets/php/security_settings.php';
$accEp = 'c:/laragon/www/memar-accounting/assets/php/account_settings.php';

/* 7.1 — بدون ورود: JSON 401 */
$snippet = <<<'PHP'
session_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_GET = ['action' => 'get'];
require 'c:/laragon/www/memar-accounting/assets/php/security_settings.php';
PHP;
$out = auth_run_snippet($snippet);
t_check('security endpoint blocks unauthenticated access (json 401)', str_contains($out, '"success":false') && str_contains($out, 'وارد حساب'));

/* 7.2 — با ورود ولی CSRF اشتباه → 403 */
$snippet = <<<'PHP'
session_start();
$_SESSION['auth_user_id'] = 1;
$_SESSION['csrf_token'] = 'tok123456';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_POST = ['csrf_token' => 'wrong-token'];
require 'c:/laragon/www/memar-accounting/assets/php/security_settings.php';
PHP;
$out = auth_run_snippet($snippet);
t_check('wrong CSRF rejected (403)', str_contains($out, '"success":false'));

/* 7.3 — CSRF درست ولی مقادیر نامعتبر → 422 */
$snippet = <<<'PHP'
session_start();
$_SESSION['auth_user_id'] = 1;
$_SESSION['csrf_token'] = 'tok123456';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_POST = [
    'csrf_token' => 'tok123456',
    'captcha_provider' => 'pure',
    'captcha_char_type' => 'letters',
    'captcha_length' => 5,
    'captcha_width' => 170,
    'captcha_height' => 55,
    'captcha_font_size' => 22,
    'captcha_expiry' => 180,
    'captcha_noise_level' => 'medium',
    'brute_max_attempts' => 5,
    'brute_window_minutes' => 15,
    'brute_lockout_minutes' => 15,
];
require 'c:/laragon/www/memar-accounting/assets/php/security_settings.php';
PHP;
$out = auth_run_snippet($snippet);
t_check('endpoint rejects unverified captcha char_type', str_contains($out, '"success":false'));

/* 7.4 — ذخیره معتبر */
$snippet = <<<'PHP'
session_start();
$_SESSION['auth_user_id'] = 1;
$_SESSION['csrf_token'] = 'tok123456';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_POST = [
    'csrf_token' => 'tok123456',
    'captcha_provider' => 'pure',
    'login_captcha_enabled' => '1',
    'registration_captcha_enabled' => '0',
    'captcha_lang' => 'fa',
    'captcha_char_type' => 'mixed',
    'captcha_length' => 5,
    'captcha_width' => 170,
    'captcha_height' => 55,
    'captcha_font_size' => 22,
    'captcha_noise_level' => 'medium',
    'captcha_case_sensitive' => '1',
    'captcha_expiry' => 180,
    'brute_max_attempts' => 5,
    'brute_window_minutes' => 15,
    'brute_lockout_minutes' => 15,
];
require 'c:/laragon/www/memar-accounting/assets/php/security_settings.php';
PHP;
$out = auth_run_snippet($snippet);
t_check('valid settings save via endpoint succeeds', str_contains($out, '"success":true'));

/* 7.5 — تغییر رمز از طریق endpoint با رمز فعلی اشتباه → 422 */
$snippet = sprintf(<<<'PHP'
session_start();
$_SESSION['auth_user_id'] = %d;
$_SESSION['csrf_token'] = 'tok123456';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_POST = [
    'csrf_token' => 'tok123456',
    'action' => 'change_password',
    'current_password' => 'Wrongcurrent',
    'new_password' => 'Newpass123',
    'new_password_confirmation' => 'Newpass123',
];
require 'c:/laragon/www/memar-accounting/assets/php/account_settings.php';
PHP, $loginUserId);
$out = auth_run_snippet($snippet);
t_check('account endpoint rejects wrong current password', str_contains($out, '"success":false'));

echo "\n== SUMMARY ==\n";

/* بازگردانی صریح (و در صورت خطای ناگهانی، تابع خاموش‌سازی نیز اجرا می‌شود) */
auth_test_restore();

echo 'passed: ' . $GLOBALS['T_PASS'] . ' / ' . $GLOBALS['T_TOTAL'] . "\n";
echo 'failed: ' . $GLOBALS['T_FAIL'] . "\n";
echo ($GLOBALS['T_FAIL'] === 0 ? 'AUTH TEST OK' : 'AUTH TEST FAILED') . "\n";
ob_end_flush();
exit($GLOBALS['T_FAIL'] === 0 ? 0 : 1);






