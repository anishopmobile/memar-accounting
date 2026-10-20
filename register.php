<?php
require_once __DIR__ . '/assets/php/auth.php';

if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

/* اگر اکانتی وجود دارد، ثبت‌نام عمومی دیگر در دسترس نیست */
if (!auth_registration_available()) {
    header('Location: login.php');
    exit;
}

$captcha = CaptchaService::instance();
$captchaEnabled = $captcha->enabledFor('registration');

$error = '';
$old = [
    'first_name' => '',
    'last_name' => '',
    'username' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = [
        'username' => (string) ($_POST['username'] ?? ''),
        'first_name' => (string) ($_POST['first_name'] ?? ''),
        'last_name' => (string) ($_POST['last_name'] ?? ''),
        'password' => (string) ($_POST['password'] ?? ''),
        'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
    ];
    $old['first_name'] = $input['first_name'];
    $old['last_name'] = $input['last_name'];
    $old['username'] = $input['username'];

    if (!csrf_verify()) {
        $error = 'نشست امنیتی نامعتبر است؛ لطفاً دوباره تلاش کنید.';
    } elseif ($captchaEnabled && !$captcha->validate((string) ($_POST['captcha'] ?? ''))) {
        $error = 'کد امنیتی اشتباه است یا منقضی شده است.';
    } else {
        $result = auth_user_create($input);
        if ($result['ok']) {
            /* عمداً کاربر به‌صورت خودکار وارد نمی‌شود */
            header('Location: login.php?registered=1');
            exit;
        }
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">
    <title>ایجاد حساب نخست | کوشا حساب</title>
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
        <section class="login-card" aria-labelledby="register-title">
            <div class="login-brand">
                <div class="login-brand-mark" aria-hidden="true">
                    <span class="login-brand-mark-inner">
                        <i class="fas fa-user-shield"></i>
                    </span>
                </div>
                <div>
                    <div class="login-brand-name">کوشا حساب</div>
                    <div class="login-brand-caption">حسابداری ساده و دقیق</div>
                </div>
            </div>

            <div class="login-heading">
                <span class="login-kicker">راه‌اندازی اولیه</span>
                <h1 id="register-title">ایجاد حساب مدیریت</h1>
                <p>هنوز هیچ کاربری ساخته نشده است. اولین حساب به عنوان مدیر سیستم ثبت می‌شود.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="login-alert" role="alert">
                    <span class="login-alert-icon"><i class="fas fa-circle-exclamation"></i></span>
                    <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="login-form" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                <div class="login-grid-2">
                    <div class="login-field">
                        <label for="first_name">نام</label>
                        <div class="login-input-wrap">
                            <span class="login-input-icon" aria-hidden="true"><i class="fas fa-user"></i></span>
                            <input id="first_name" name="first_name" type="text"
                                   value="<?php echo htmlspecialchars($old['first_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                   autocomplete="given-name" placeholder="نام خود را وارد کنید" required autofocus>
                        </div>
                    </div>

                    <div class="login-field">
                        <label for="last_name">نام خانوادگی</label>
                        <div class="login-input-wrap">
                            <span class="login-input-icon" aria-hidden="true"><i class="fas fa-user"></i></span>
                            <input id="last_name" name="last_name" type="text"
                                   value="<?php echo htmlspecialchars($old['last_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                   autocomplete="family-name" placeholder="نام خانوادگی خود را وارد کنید" required>
                        </div>
                    </div>
                </div>

                <div class="login-grid-2">
                    <div class="login-field login-span-2">
                        <label for="username">نام کاربری</label>
                        <div class="login-input-wrap">
                            <span class="login-input-icon" aria-hidden="true"><i class="fas fa-at"></i></span>
                            <input id="username" name="username" type="text"
                                   value="<?php echo htmlspecialchars($old['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                   autocomplete="username" autocapitalize="none" spellcheck="false"
                                   placeholder="۳ تا ۶۴ کاراکتر انگلیسی" required>
                        </div>
                    </div>
                </div>

                <div class="login-grid-2">
                    <div class="login-field">
                        <label for="password">رمز عبور</label>
                        <div class="login-input-wrap">
                            <span class="login-input-icon" aria-hidden="true"><i class="fas fa-lock"></i></span>
                            <input id="password" name="password" type="password"
                                   autocomplete="new-password" placeholder="حداقل ۸ کاراکتر شامل حروف و عدد" required>
                        </div>
                    </div>

                    <div class="login-field">
                        <label for="password_confirmation">تکرار رمز عبور</label>
                        <div class="login-input-wrap">
                            <span class="login-input-icon" aria-hidden="true"><i class="fas fa-lock"></i></span>
                            <input id="password_confirmation" name="password_confirmation" type="password"
                                   autocomplete="new-password" placeholder="رمز عبور را دوباره وارد کنید" required>
                        </div>
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
                    <span>ایجاد حساب مدیر</span>
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </button>
            </form>

            <div class="login-footer">
                <span class="login-footer-line"></span>
                <span>پس از ساخت اولین حساب، ثبت‌نام عمومی غیرفعال می‌شود</span>
                <span class="login-footer-line"></span>
            </div>
        </section>
    </main>
</body>
</html>
