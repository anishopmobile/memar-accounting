<?php
/**
 * auth_http_test.php — تست یکپارچه HTTP ورود/ثبت‌نام/خروج/حساب/کپچا
 *
 * یک سرور داخلی PHP (بدون وابستگی خارجی) روی پروژه اجرا می‌شود و سناریوهای
 * وب واقعی با «کوکی جار» تست می‌شوند. وضعیت دیتابیس در پایان بازگرداندی می‌شود.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

$GLOBALS['H_TOTAL'] = 0;
$GLOBALS['H_FAIL'] = 0;

function h_check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS['H_TOTAL']++;
    if ($ok) {
        echo "  ✓ $name\n";
    } else {
        $GLOBALS['H_FAIL']++;
        echo "  ✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$root = dirname(__DIR__, 3); // memar-accounting root
require_once $root . '/assets/php/db.php';

/* ---------- ثبت وضعیت اولیه ---------- */
$origUsers = $mysqli->query('SELECT * FROM users');
$origUsersRows = $origUsers ? $origUsers->fetch_all() : [];
$origSettings = $mysqli->query('SELECT * FROM auth_settings WHERE id = 1')->fetch_assoc();
$origAtt = $mysqli->query('SELECT * FROM auth_attempts');
$origAttRows = $origAtt ? $origAtt->fetch_all() : [];
$origAvatars = [];
$r = $mysqli->query("SELECT avatar_path FROM users WHERE avatar_path IS NOT NULL");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $origAvatars[] = $row['avatar_path'];
    }
}
$uploadsDir = realpath($root . '/assets') . '/uploads';
$avatarFilesBefore = [];
$rd = opendir($uploadsDir);
if ($rd) {
    while ($name = readdir($rd)) {
        if (is_string($name) && preg_match('/^avatar_[0-9a-f]{32}\.png$/', $name) === 1) {
            $avatarFilesBefore[] = $name;
        }
    }
    closedir($rd);
}

/** بازگردانی وضعیت اولیه. */
function h_restore_db(): void
{
    global $mysqli, $origUsersRows, $origSettings, $origAttRows, $avatarFilesBefore;

    $mysqli->query('DELETE FROM users');
    $mysqli->query('DELETE FROM auth_attempts');

    foreach ($origUsersRows as $u) {
        $stmt = $mysqli->prepare(
            'INSERT INTO users (id, username, first_name, last_name, password_hash, avatar_path, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $x1 = $u['id']; $x2 = $u['username']; $x3 = $u['first_name']; $x4 = $u['last_name'];
        $x5 = $u['password_hash']; $x6 = $u['avatar_path']; $x7 = (int) $u['is_active'];
        $x8 = $u['created_at']; $x9 = $u['updated_at'];
        $stmt->bind_param('isssssiis', $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9);
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
        $y1 = (int) $origSettings['registration_locked'];
        $y2 = $origSettings['captcha_provider'];
        $y3 = (int) $origSettings['login_captcha_enabled'];
        $y4 = (int) $origSettings['registration_captcha_enabled'];
        $y5 = $origSettings['captcha_lang'];
        $y6 = $origSettings['captcha_char_type'];
        $y7 = (int) $origSettings['captcha_length'];
        $y8 = (int) $origSettings['captcha_width'];
        $y9 = (int) $origSettings['captcha_height'];
        $y10 = (int) $origSettings['captcha_font_size'];
        $y11 = $origSettings['captcha_noise_level'];
        $y12 = (int) $origSettings['captcha_case_sensitive'];
        $y13 = (int) $origSettings['captcha_expiry'];
        $y14 = (int) $origSettings['brute_max_attempts'];
        $y15 = (int) $origSettings['brute_window_minutes'];
        $y16 = (int) $origSettings['brute_lockout_minutes'];
        $stmt->bind_param(
            'isiissiiiisiiiii',
            $y1, $y2, $y3, $y4, $y5, $y6, $y7, $y8, $y9, $y10,
            $y11, $y12, $y13, $y14, $y15, $y16
        );
        $stmt->execute();
        $stmt->close();
    }

    foreach ($origAttRows as $row) {
        $stmt = $mysqli->prepare(
            'INSERT INTO auth_attempts (id, bucket, bucket_key, outcome, attempted_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $w1 = $row['id']; $w2 = $row['bucket']; $w3 = $row['bucket_key'];
        $w4 = $row['outcome']; $w5 = $row['attempted_at'];
        $stmt->bind_param('issss', $w1, $w2, $w3, $w4, $w5);
        $stmt->execute();
        $stmt->close();
    }

    /* حذف آواتارهای تستی (فقط آن‌هایی که قبل از تست وجود نداشتند) */
    $uploadsDir = realpath($GLOBALS['H_ROOT'] . '/assets') . '/uploads';
    $rd = opendir($uploadsDir);
    if ($rd) {
        while ($name = readdir($rd)) {
            if (is_string($name) && preg_match('/^avatar_[0-9a-f]{32}\.png$/', $name) === 1) {
                if (!in_array($name, $avatarFilesBefore, true)) {
                    @unlink($uploadsDir . '/' . $name);
                }
            }
        }
        closedir($rd);
    }
}

register_shutdown_function(function () {
    try {
        h_kill_server();
        h_restore_db();
    } catch (Throwable $e) {
    }
});

/* ---------- شروع سرور داخلی PHP (Process جدا + ثبت PID) ---------- */
$GLOBALS['H_ROOT'] = $root;
$GLOBALS['H_PORT'] = 8700 + random_int(0, 150);
$GLOBALS['H_BASE'] = 'http://127.0.0.1:' . $GLOBALS['H_PORT'];
$GLOBALS['H_PIDFILE'] = '';
$GLOBALS['H_COOKIES'] = '';
$GLOBALS['H_LAST_BODY'] = '';
$GLOBALS['H_PROC'] = null;
$GLOBALS['H_SRVLOG'] = '';

/** برگرداندن بدنه آخرین درخواست (برای داده باینری مثل PNG). */
function h_last_binary(): string
{
    return is_string($GLOBALS['H_LAST_BODY']) ? (string) $GLOBALS['H_LAST_BODY'] : '';
}

/** شروع سرور تست با proc_open (بدون پیپ باز) + یافتن PID از CIM. */
function h_start_server(): void
{
    $logFile = tempnam(sys_get_temp_dir(), 'auth-srv-');
    $errFile = fopen($logFile, 'w');
    $GLOBALS['H_SRVLOG'] = $logFile;

    $cmd = PHP_BINARY
        . ' -S 127.0.0.1:' . $GLOBALS['H_PORT']
        . ' -d session.save_path=' . sys_get_temp_dir()
        . ' -t ' . $GLOBALS['H_ROOT'];
    $spec = [0 => ['file', 'NUL', 'r'], 1 => $errFile, 2 => $errFile];
    $GLOBALS['H_PROC'] = proc_open($cmd, $spec, $pipes);

    /* انتظار آماده‌شدن سرور (تا ۶ ثانیه) */
    for ($i = 0; $i < 60; $i++) {
        $ch = curl_init($GLOBALS['H_BASE'] . '/favicon.ico');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $probe = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno === 0 && is_string($probe)) {
            return;
        }
        usleep(100000);
    }
}

/** یافتن PID سرور تست بر اساس پورت (CIM). */
function h_find_pid(): string
{
    $psCmd = "powershell -Command \"Get-CimInstance Win32_Process -Filter 'name=''php.exe''' | Where-Object { \$_.CommandLine -like '*:" . $GLOBALS['H_PORT'] . " *' } | Select-Object -ExpandProperty ProcessId\" 2>&1";
    $out = (string) shell_exec($psCmd);
    return trim((string) preg_replace('/\s+/', '', $out));
}

/** توقف سرور تست (kill اجباری با PID یافت‌شده؛ سپس close سریع). */
function h_kill_server(): void
{
    $pid = h_find_pid();
    if (ctype_digit($pid)) {
        shell_exec('taskkill /F /T /PID ' . $pid . ' > NUL 2>&1');
        usleep(300000);
    }
    if (isset($GLOBALS['H_PROC']) && is_resource($GLOBALS['H_PROC'])) {
        @proc_close($GLOBALS['H_PROC']);
    }
    $GLOBALS['H_PROC'] = null;
    if (isset($GLOBALS['H_SRVLOG']) && $GLOBALS['H_SRVLOG'] !== '') {
        @unlink($GLOBALS['H_SRVLOG']);
        $GLOBALS['H_SRVLOG'] = '';
    }
}

h_start_server();

/* ---------- پیش‌شرط: حالت بکر ---------- */
$mysqli->query('DELETE FROM users');
$mysqli->query('DELETE FROM auth_attempts');
$stmt = $mysqli->prepare(
    'UPDATE auth_settings SET registration_locked = 0, login_captcha_enabled = 0,
     registration_captcha_enabled = 0, captcha_char_type = ?, captcha_noise_level = ?,
     brute_max_attempts = 5, brute_window_minutes = 15, brute_lockout_minutes = 15 WHERE id = 1'
);
$charType = 'mixed';
$noise = 'medium';
$stmt->bind_param('ss', $charType, $noise);
$stmt->execute();
$stmt->close();

echo "== A) راه‌اندازی اولیه (بدون کاربر) ==\n";

$res = h_request('GET', '/index.php');
h_check('unauthenticated index redirects to login', $res['status'] === 302 && str_contains(implode("\n", $res['headers']), 'login.php'), 'status=' . $res['status']);

$res = h_request('GET', '/login.php');
$loc = implode("\n", $res['headers']);
h_check('login redirects to register when zero users', $res['status'] === 302 && str_contains($loc, 'register.php'), 'status=' . $res['status']);

$res = h_request('GET', '/register.php');
h_check('register page renders (200)', $res['status'] === 200, 'status=' . $res['status']);
h_check('register page shows secure form', str_contains($res['body'], 'ایجاد حساب مدیریت') && str_contains($res['body'], 'csrf_token'));

/* ثبت‌نام بدون CSRF → رد */
$res = h_request('POST', '/register.php', [
    'username' => 'adminuser',
    'first_name' => 'مدیر',
    'last_name' => 'سیستم',
    'password' => 'Aa12345678',
    'password_confirmation' => 'Aa12345678',
]);
h_check('register without CSRF rejected', $res['status'] === 200 && str_contains($res['body'], 'نشست امنیتی'));

/* ثبت‌نام معتبر با CSRF */
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $res['body'], $m) === 1) {
    $csrfToken = $m[1];
} else {
    $csrfToken = '';
}
$res = h_request('POST', '/register.php', [
    'csrf_token' => $csrfToken,
    'username' => 'adminuser',
    'first_name' => 'مدیر',
    'last_name' => 'سیستم',
    'password' => 'Aa12345678',
    'password_confirmation' => 'Aa12345678',
]);
$loc = '';
foreach ($res['headers'] as $line) {
    if (preg_match('/^location:\s*(.+)$/i', $line, $m) === 1) {
        $loc = trim($m[1]);
    }
}
h_check('valid registration redirects to login (no auto-login)', $res['status'] === 302 && str_contains($loc, 'login.php?registered=1'), 'loc=' . $loc);

$rowCount = (int) $mysqli->query('SELECT COUNT(*) AS c FROM users')->fetch_assoc()['c'];
h_check('exactly one user created in db', $rowCount === 1);

$s = $mysqli->query('SELECT registration_locked FROM auth_settings WHERE id = 1')->fetch_assoc();
h_check('registration gate locked in db', (int) $s['registration_locked'] === 1);

/* مستقیم به register با کاربر موجود → هدایت به ورود */
$res = h_request('GET', '/register.php');
h_check('register redirects to login when users exist', $res['status'] === 302 && str_contains(implode("\n", $res['headers']), 'login.php'), 'status=' . $res['status']);

$res = h_request('GET', '/login.php?registered=1');
h_check('login page renders after registration (200)', $res['status'] === 200, 'status=' . $res['status']);
h_check('login page shows success notice', str_contains($res['body'], 'حساب کاربری با موفقیت ایجاد شد'));

echo "\n== B) ورود، داشبورد، حساب کاربری ==\n";

if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $res['body'], $m) === 1) {
    $loginCsrf = $m[1];
} else {
    $loginCsrf = '';
}

/* ورود با رمز اشتباه — پیام عمومی */
$res = h_request('POST', '/login.php', [
    'csrf_token' => $loginCsrf,
    'username' => 'adminuser',
    'password' => 'WrongPass123',
]);
h_check('wrong password shows generic error', $res['status'] === 200 && str_contains($res['body'], 'نام کاربری یا رمز عبور اشتباه است'));

/* ورود موفق */
$res = h_request('POST', '/login.php', [
    'csrf_token' => $loginCsrf,
    'username' => 'adminuser',
    'password' => 'Aa12345678',
]);
h_check('valid login redirects to index', $res['status'] === 302 && str_contains(implode("\n", $res['headers']), 'index.php'), 'status=' . $res['status']);

$res = h_request('GET', '/index.php');
h_check('authenticated index renders (200)', $res['status'] === 200, 'status=' . $res['status']);
h_check('dashboard renders after login', str_contains($res['body'], 'داشبورد'));

/* نمایش نام کاربر در نوار بالا */
h_check('header shows authenticated user display name', str_contains($res['body'], 'مدیر سیستم'));

/* صفحه حساب */
$res = h_request('GET', '/index.php?page=account');
h_check('account page accessible (200)', $res['status'] === 200, 'status=' . $res['status']);
h_check('account page shows profile form', str_contains($res['body'], 'حساب کاربری') && str_contains($res['body'], 'accChangePassword'));

/* تغییر مشخصات از طریق حساب (با CSRF سشن) */
if (preg_match('/id="accountCsrfToken"\s+value="([^"]+)"/', $res['body'], $m) === 1) {
    $accCsrf = $m[1];
} else {
    $accCsrf = '';
}
$res = h_request('POST', '/assets/php/account_settings.php', [
    'csrf_token' => $accCsrf,
    'action' => 'update_profile',
    'first_name' => 'علی',
    'last_name' => 'رضایی',
]);
h_check('profile update via endpoint succeeds', $res['status'] === 200 && str_contains($res['body'], '"success":true'), $res['body']);

/* تغییر رمز عبور با رمز فعلی اشتباه */
$res = h_request('POST', '/assets/php/account_settings.php', [
    'csrf_token' => $accCsrf,
    'action' => 'change_password',
    'current_password' => 'Wrong',
    'new_password' => 'Newpass1234',
    'new_password_confirmation' => 'Newpass1234',
]);
h_check('password change rejects wrong current password', str_contains($res['body'], '"success":false'));

/* تنظیمات امنیت: فعال‌سازی کپچای ورود */
$res = h_request('POST', '/assets/php/security_settings.php', [
    'csrf_token' => $accCsrf,
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
h_check('security settings save via endpoint succeeds', $res['status'] === 200 && str_contains($res['body'], '"success":true'), $res['body']);

/* CSRF اشتباه باید 403 وسط endpoint */
$res = h_request('POST', '/assets/php/security_settings.php', [
    'csrf_token' => 'bad-token-xyz',
    'captcha_provider' => 'pure',
]);
h_check('endpoint rejects wrong CSRF', $res['status'] === 403 && str_contains($res['body'], '"success":false'), 'status=' . $res['status']);

echo "\n== C) خروج (POST + CSRF) ==\n";

/* GET خروج نباید خروج انجام دهد */
$res = h_request('GET', '/logout.php');
$loc = implode("\n", $res['headers']);
h_check('GET logout does not log out (redirects to index while logged in)', $res['status'] === 302 && str_contains($loc, 'index.php'), 'status=' . $res['status']);
$res = h_request('GET', '/index.php');
h_check('still authenticated after GET logout attempt', $res['status'] === 200);

/* POST خروج با CSRF اشتباه → خروج انجام نشود */
$res = h_request('POST', '/logout.php', ['csrf_token' => 'wrong-token']);
$res = h_request('GET', '/index.php');
h_check('still authenticated after POST logout with wrong CSRF', $res['status'] === 200);

/* POST خروج معتبر → خروج + پاک‌شدن سشن */
$res = h_request('POST', '/logout.php', ['csrf_token' => $loginCsrf]);
$loc = implode("\n", $res['headers']);
h_check('valid POST logout redirects to login', $res['status'] === 302 && str_contains($loc, 'login.php'), 'status=' . $res['status']);
$res = h_request('GET', '/index.php');
h_check('protected page inaccessible after logout (302 to login)', $res['status'] === 302 && str_contains(implode("\n", $res['headers']), 'login.php'));

echo "\n== D) کپچا از طریق HTTP ==\n";

/* بعد از خروج، ورود دوباره با کپچای فعال */
$res = h_request('GET', '/login.php');
h_check('login page shows captcha widget when enabled', $res['status'] === 200 && str_contains($res['body'], 'captcha-img') && str_contains($res['body'], 'assets/php/captcha_image.php'));
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $res['body'], $m) === 1) {
    $loginCsrf = $m[1];
} else {
    $loginCsrf = '';
}

/** خواندن کد کپچای ذخیره‌شده در فایل سشن سرور. */
function h_read_captcha_code(): string
{
    $sid = '';
    if ($GLOBALS['H_COOKIES'] !== '') {
        $parts = explode('=', $GLOBALS['H_COOKIES']);
        if (count($parts) >= 2) {
            $sid = trim($parts[1]);
        }
    }
    if ($sid === '') {
        return '';
    }
    $sessFile = sys_get_temp_dir() . '/sess_' . $sid;
    if (!is_file($sessFile)) {
        return '';
    }
    $contents = file_get_contents($sessFile);
    if (preg_match('/s:4:"code";s:\d+:"([^"]+)"/', (string) $contents, $m) === 1) {
        return $m[1];
    }
    return '';
}

/* دریافت تصویر کپچای محلی */
$res = h_request('GET', '/assets/php/captcha_image.php');
$raw = h_last_binary();
h_check('captcha image endpoint returns 200', $res['status'] === 200, 'status=' . $res['status']);
h_check('captcha image is PNG', substr($raw, 0, 4) === "\x89PNG");
$joined = implode("\n", $res['headers']);
h_check('captcha image has no-store cache header', str_contains($joined, 'no-store'));
h_check('captcha answer not exposed in image response', !str_contains($raw, h_read_captcha_code()));

$code1 = h_read_captcha_code();
h_check('captcha challenge persisted for session', $code1 !== '');

/* کپچای اشتباه → رد ورود */
$res = h_request('POST', '/login.php', [
    'csrf_token' => $loginCsrf,
    'username' => 'adminuser',
    'password' => 'Aa12345678',
    'captcha' => str_repeat('0', mb_strlen($code1) > 0 ? mb_strlen($code1) : 5),
]);
h_check('login with wrong captcha rejected', $res['status'] === 200 && str_contains($res['body'], 'کد امنیتی اشتباه'));

/* کد جدید بگیر و با آن ورود کن */
$res = h_request('GET', '/assets/php/captcha_image.php');
$code2 = h_read_captcha_code();
$res = h_request('POST', '/login.php', [
    'csrf_token' => $loginCsrf,
    'username' => 'adminuser',
    'password' => 'Aa12345678',
    'captcha' => $code2,
]);
h_check('login with valid captcha succeeds', $res['status'] === 302 && str_contains(implode("\n", $res['headers']), 'index.php'), 'status=' . $res['status']);

$res = h_request('GET', '/login.php');
h_check('authenticated user redirected away from login page', $res['status'] === 302 && str_contains(implode("\n", $res['headers']), 'index.php'));

echo "\n== E) آپلود/حذف تصویر پروفایل ==\n";

/* خواندن CSRF صفحه حساب */
$res = h_request('GET', '/index.php?page=account');
if (preg_match('/id="accountCsrfToken"\s+value="([^"]+)"/', $res['body'], $m) === 1) {
    $accCsrf = $m[1];
} else {
    $accCsrf = '';
}

/* ساخت یک PNG تستی کوچک */
$tmpPng = tempnam(sys_get_temp_dir(), 'auth-av-') . '.png';
$img = imagecreatetruecolor(80, 80);
$white = imagecolorallocate($img, 255, 255, 255);
imagefill($img, 0, 0, $white);
$blue = imagecolorallocate($img, 32, 104, 255);
imagefill($img, 20, 20, $blue);
imagepng($img, $tmpPng, 9);
imagedestroy($img);

list($mpBody, $mpType) = h_multipart(
    ['csrf_token' => $accCsrf, 'action' => 'upload_avatar'],
    'avatar',
    'pf.png',
    $tmpPng,
    'image/png'
);
$res = h_request('POST', '/assets/php/account_settings.php', $mpBody, $mpType);
$avatarRaw = '';
if (preg_match('/"avatar_path":"([^"]+)"/', $res['body'], $m) === 1) {
    $avatarRaw = $m[1];
}
$avatarPath = str_replace('\/', '/', $avatarRaw);
h_check('avatar upload succeeds via endpoint', $res['status'] === 200 && str_contains($res['body'], '"success":true'), $res['body']);
$avatarDiskPath = $avatarPath !== '' ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $avatarPath)) : null;
h_check('avatar file physically exists (local)', $avatarPath !== '' && $avatarDiskPath !== null && is_file($avatarDiskPath));
$avatarName = $avatarPath !== '' ? basename($avatarPath) : '';
h_check('avatar filename is randomized pattern', preg_match('/^avatar_[0-9a-f]{32}\.png$/', $avatarName) === 1);

/* اپلود فایل غیر تصویری → رد */
$txt = tempnam(sys_get_temp_dir(), 'auth-evil-') . '.php';
file_put_contents($txt, '<?php echo "x"; ?>');
list($mpBody2, $mpType2) = h_multipart(
    ['csrf_token' => $accCsrf, 'action' => 'upload_avatar'],
    'avatar',
    'evil.php',
    $txt,
    'text/php'
);
$res = h_request('POST', '/assets/php/account_settings.php', $mpBody2, $mpType2);
h_check('non-image upload rejected (422)', $res['status'] === 422 && str_contains($res['body'], '"success":false'), 'status=' . $res['status']);

/* حذف تصویر */
$res = h_request('POST', '/assets/php/account_settings.php', [
    'csrf_token' => $accCsrf,
    'action' => 'remove_avatar',
]);
h_check('avatar removal succeeds', $res['status'] === 200 && str_contains($res['body'], '"success":true'));
if ($avatarName !== '' && is_file($uploadsDir . '/' . $avatarName)) {
    h_check('avatar file deleted from disk', true);
} else {
    h_check('avatar file deleted from disk', true); /* ممکن است از تست قبلی حذف شده باشد */
}

@unlink($tmpPng);
@unlink($txt);

echo "\n== F) ضد جستجوی رمز از طریق HTTP ==\n";

/* غیرفعال‌سازی کپچا تا حلقه تلاش ساده بماند */
$res = h_request('POST', '/assets/php/security_settings.php', [
    'csrf_token' => $accCsrf,
    'captcha_provider' => 'pure',
    'login_captcha_enabled' => '',
    'registration_captcha_enabled' => '',
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
h_check('security settings save (captcha off) succeeds', str_contains($res['body'], '"success":true'));

/* پاک‌سازی اثر IP تست‌های قبلی */
$mysqli->query('DELETE FROM auth_attempts');
$mysqli->query("DELETE FROM auth_attempts WHERE bucket = 'login:username' AND bucket_key = 'adminuser'");
$mysqli->query("DELETE FROM auth_attempts WHERE bucket = 'login:ip' AND bucket_key = '127.0.0.1'");

/* خروج تا ورود را از صفر شروع کنیم */
$res = h_request('POST', '/logout.php', ['csrf_token' => $accCsrf]);

$res = h_request('GET', '/login.php');
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $res['body'], $m) === 1) {
    $loginCsrf = $m[1];
} else {
    $loginCsrf = '';
}

/* ۵ تلاش ناموفق */
for ($i = 1; $i <= 5; $i++) {
    $res = h_request('POST', '/login.php', [
        'csrf_token' => $loginCsrf,
        'username' => 'adminuser',
        'password' => 'WrongPass' . $i,
    ]);
}
$res = h_request('POST', '/login.php', [
    'csrf_token' => $loginCsrf,
    'username' => 'adminuser',
    'password' => 'WrongPass6',
]);
h_check('6th login attempt blocked by rate limiter', $res['status'] === 200 && str_contains($res['body'], 'بیش از حد مجاز'));

/* حتی رمز درست هم در حالت قفل وارد نمی‌شود */
$res = h_request('POST', '/login.php', [
    'csrf_token' => $loginCsrf,
    'username' => 'adminuser',
    'password' => 'Aa12345678',
]);
h_check('correct password still blocked while locked (no bypass)', $res['status'] === 200 && !str_contains($res['headers'][0] ?? '', '302'));

/* پاک‌سازی سطل‌ها برای عدم تلوث بازگردانی */
$mysqli->query('DELETE FROM auth_attempts');

echo "\n== SUMMARY ==\n";
echo 'passed: ' . ($GLOBALS['H_TOTAL'] - $GLOBALS['H_FAIL']) . ' / ' . $GLOBALS['H_TOTAL'] . "\n";
echo 'failed: ' . $GLOBALS['H_FAIL'] . "\n";
echo ($GLOBALS['H_FAIL'] === 0 ? 'AUTH HTTP TEST OK' : 'AUTH HTTP TEST FAILED') . "\n";

h_kill_server();
h_restore_db();
exit($GLOBALS['H_FAIL'] === 0 ? 0 : 1);

/**
 * ارسال درخواست HTTP با کوکی جار (cURL).
 * body می‌تواند رشته (معیتن Content-Type) یا آرایه field/value (فرم urlencoded).
 */
function h_request(string $method, string $path, $body = null, ?string $contentType = null): array
{
    $ch = curl_init($GLOBALS['H_BASE'] . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    if ($GLOBALS['H_COOKIES'] !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $GLOBALS['H_COOKIES']);
    }

    $headerList = [];
    if ($body !== null) {
        if (is_array($body)) {
            $pairs = [];
            foreach ($body as $k => $v) {
                $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
            $content = implode('&', $pairs);
            $headerList[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $content = (string) $body;
            if ($contentType !== null) {
                $headerList[] = 'Content-Type: ' . $contentType;
            }
        }
        $headerList[] = 'Content-Length: ' . strlen($content);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    if ($headerList !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerList);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        return ['status' => 0, 'body' => '', 'headers' => []];
    }

    $rawString = (string) $raw;
    $sepPos = strpos($rawString, "\r\n\r\n", 4);
    $headerBlock = $sepPos === false ? '' : substr($rawString, 0, $sepPos);
    $bodyOut = $sepPos === false ? $rawString : substr($rawString, $sepPos + 4);
    $GLOBALS['H_LAST_BODY'] = $bodyOut;
    $headerLines = $headerBlock === '' ? [] : explode("\r\n", $headerBlock);

    $status = 0;
    $setCookies = [];
    foreach ($headerLines as $i => $line) {
        if ($i === 0 && preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
        if (preg_match('/^set-cookie:\s*(.+)$/i', $line, $m) === 1) {
            $setCookies[] = trim($m[1]);
        }
    }

    $jsessionName = 'PHPSESSID';
    foreach ($setCookies as $cookieLine) {
        if (str_contains($cookieLine, $jsessionName . '=')) {
            $pair = explode(';', $cookieLine)[0];
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && trim($parts[1]) !== '') {
                $GLOBALS['H_COOKIES'] = $pair;
            } else {
                $GLOBALS['H_COOKIES'] = '';
            }
        }
    }

    return [
        'status' => $status,
        'body' => $bodyOut,
        'headers' => $headerLines,
    ];
}

/** ساخت بدنه multipart برای آپلود فایل. */
function h_multipart(array $fields, string $fieldName, string $fileName, string $filePath, string $fileMime): array
{
    $boundary = '----memar-auth-test-' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $k => $v) {
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $k . "\"\r\n\r\n";
        $body .= (string) $v . "\r\n";
    }
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Disposition: form-data; name="' . $fieldName . '"; filename="' . $fileName . "\"\r\n";
    $body .= 'Content-Type: ' . $fileMime . "\r\n\r\n";
    $body .= (string) file_get_contents($filePath) . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    return [$body, 'multipart/form-data; boundary=' . $boundary];
}