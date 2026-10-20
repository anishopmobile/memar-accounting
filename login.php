<?php
require_once __DIR__ . '/assets/php/auth.php';

if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

/* اگر هنوز کاربری ساخته نشده باشد، مسیر ورود به ثبت‌نام امنِ اولین کاربر هدایت می‌شود */
if (auth_registration_available()) {
    header('Location: register.php');
    exit;
}

$captcha = CaptchaService::instance();

$error = '';
$notice = isset($_GET['registered']) ? 'حساب کاربری با موفقیت ایجاد شد. اکنون وارد شوید.' : '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');

if (!preg_match('/^(?:index\.php)?(?:\?page=[a-z0-9-]+)?$/i', $next)) {
    $next = 'index.php';
}

$captchaEnabled = $captcha->enabledFor('login');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_verify()) {
        $error = 'نشست امنیتی نامعتبر است؛ لطفاً دوباره تلاش کنید.';
    } elseif (
        auth_rate_lock_status('login:username', auth_username_normalize($username))['locked']
        || auth_rate_lock_status('login:ip', auth_client_ip())['locked']
    ) {
        $error = 'تعداد تلاش‌های ورود بیش از حد مجاز است؛ لطفاً چند دقیقه بعد تلاش کنید.';
    } elseif ($captchaEnabled && !$captcha->validate((string) ($_POST['captcha'] ?? ''))) {
        $error = 'کد امنیتی اشتباه است یا منقضی شده است.';
    } elseif (authenticate($username, $password)) {
        header('Location: ' . ($next !== '' ? $next : 'index.php'));
        exit;
    } else {
        $error = auth_last_failure() === 'locked'
            ? 'تعداد تلاش‌های ورود بیش از حد مجاز است؛ لطفاً چند دقیقه بعد تلاش کنید.'
            : 'نام کاربری یا رمز عبور اشتباه است.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>ورود | کوشا حساب</title>
    <link rel="stylesheet" href="assets/css/fontawesome.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <?php if ($captchaEnabled): ?><?php echo $captcha->styles(); ?><?php endif; ?>
</head>
<body class="login-page">
    <div class="login-background" aria-hidden="true">
        <span class="login-orb login-orb-one"></span>
        <span class="login-orb login-orb-two"></span>
        <span class="login-grid"></span>
    </div>

    <main class="login-layout">
        <section class="login-card" aria-labelledby="login-title">
            <div class="login-brand">
                <div class="login-brand-mark" aria-hidden="true">
                    <span class="login-brand-mark-inner">
                        <i class="fas fa-chart-pie"></i>
                    </span>
                </div>
                <div>
                    <div class="login-brand-name">کوشا حساب</div>
                    <div class="login-brand-caption">حسابداری ساده و دقیق</div>
                </div>
            </div>

            <div class="login-heading">
                <span class="login-kicker">پنل مدیریت</span>
                <h1 id="login-title">خوش آمدید</h1>
                <p>برای ورود به پنل حسابداری، اطلاعات حساب خود را وارد کنید.</p>
            </div>

            <?php if ($notice !== ''): ?>
                <div class="login-success" role="status">
                    <span><i class="fas fa-circle-check"></i></span>
                    <span><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="login-alert" role="alert">
                    <span class="login-alert-icon"><i class="fas fa-circle-exclamation"></i></span>
                    <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="login-form" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="login-field">
                    <label for="username">نام کاربری</label>
                    <div class="login-input-wrap">
                        <span class="login-input-icon" aria-hidden="true"><i class="fas fa-user"></i></span>
                        <input
                            id="username"
                            name="username"
                            type="text"
                            value="<?php echo htmlspecialchars((string) ($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            placeholder="نام کاربری را وارد کنید"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="login-field">
                    <label for="password">رمز عبور</label>
                    <div class="login-input-wrap">
                        <span class="login-input-icon" aria-hidden="true"><i class="fas fa-lock"></i></span>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="رمز عبور را وارد کنید"
                            required
                        >
                        <button class="login-password-toggle" type="button" id="togglePassword" aria-label="نمایش رمز عبور" aria-pressed="false">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <?php if ($captchaEnabled): ?>
                    <div class="login-field">
                        <label for="captcha">کد امنیتی</label>
                        <div class="login-captcha-box">
                            <?php echo $captcha->html('assets/php/captcha_image.php', 'captcha', 'fa'); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <button class="login-submit" type="submit">
                    <span>ورود به پنل</span>
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </button>
            </form>

            <div class="login-footer">
                <span class="login-footer-line"></span>
                <span>سیستم مدیریت حسابداری</span>
                <span class="login-footer-line"></span>
            </div>
        </section>
    </main>

    <script>
        (function () {
            const password = document.getElementById('password');
            const toggle = document.getElementById('togglePassword');

            if (!password || !toggle) return;

            toggle.addEventListener('click', function () {
                const isPassword = password.type === 'password';
                password.type = isPassword ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
                toggle.setAttribute('aria-label', isPassword ? 'مخفی کردن رمز عبور' : 'نمایش رمز عبور');
                toggle.innerHTML = isPassword
                    ? '<i class="fas fa-eye-slash"></i>'
                    : '<i class="fas fa-eye"></i>';
            });
        })();
    </script>
</body>
</html>
