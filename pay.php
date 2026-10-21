<?php
/**
 * pay.php — Public payment entry point (no account required).
 *
 *   GET  /pay.php                  → show configured default form
 *   GET  /pay.php?slug=x           → show form x
 *   POST /pay.php (action=submit)  → create order + redirect to gateway
 *   GET  /pay.php?action=callback  → gateway verify + success/failure page
 *
 * PUBLIC page: uses db.php + payment_loader.php but NOT boot.php. Never
 * exposes credentials, SQL errors, stack traces or filesystem paths.
 */
declare(strict_types=1);

require __DIR__ . '/assets/php/db.php';
require __DIR__ . '/assets/php/payment/payment_loader.php';
require __DIR__ . '/assets/php/payment/public/public_helpers.php';

$svc = new PaymentService($mysqli);
$settingsRepo = new PaymentSettingsRepository($mysqli);
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

/* ============================ CALLBACK ============================ */
if ($action === 'callback' || isset($_GET['Authority']) || isset($_GET['Status'])) {
    // Throttle duplicate-callback / authority-guessing abuse.
    $rl = new PaymentRateLimiter($mysqli);
    $bucket = 'callback';
    if (!$rl->allow('cb:' . $rl->clientIp(), $bucket)) {
        payHeader('پرداخت');
        echo '<div class="pay-result"><div class="pay-result-icon pay-icon-fail">✕</div><h1 class="pay-result-title">پرداخت انجام نشد</h1><p class="pay-result-note">درخواست‌های بیش از حد؛ لطفاً کمی بعد تلاش کنید.</p></div>';
        payEnd();
        $mysqli->close();
        exit;
    }

    $authority = trim((string) ($_GET['Authority'] ?? ''));
    $result = $svc->handleCallback($authority, $_GET);
    $tx = $result['transaction'];
    $ok = $result['ok'] && $tx !== null && $tx->isPaid();

    payHeader($ok ? 'پرداخت موفق' : 'پرداخت ناموفق');
    echo '<div class="pay-result">';
    if ($ok) {
        $formName = '';
        if ($tx !== null) {
            $f = $svc->forms()->find($tx->formId);
            if ($f !== null) {
                $formName = $f->title;
            }
        }
        echo '<div class="pay-result-icon pay-icon-success">✓</div>';
        echo '<h1 class="pay-result-title">پرداخت با موفقیت انجام شد</h1>';
        if ($tx !== null) {
            echo '<div class="pay-result-grid">';
            echo '<div><span>مبلغ</span><strong>' . number_format((int) $tx->amount) . ' ریال</strong></div>';
            echo '<div><span>موضوع</span><strong>' . pe($formName) . '</strong></div>';
            echo '<div><span>کد پیگیری</span><strong dir="ltr">' . pe((string) ($tx->refId ?: $tx->number)) . '</strong></div>';
            echo '</div>';
        }
    } else {
        echo '<div class="pay-result-icon pay-icon-fail">✕</div>';
        echo '<h1 class="pay-result-title">پرداخت انجام نشد</h1>';
        echo '<p class="pay-result-note">' . pe($result['message'] !== '' ? $result['message'] : 'تراکنش ناموفق بود.') . '</p>';
    }
    echo '</div>';
    payEnd();
    $mysqli->close();
    exit;
}

/* ============================ SUBMIT ============================ */
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Throttle public submission spam (transaction/gateway flooding).
    $rl = new PaymentRateLimiter($mysqli);
    if (!$rl->allow('sub:' . $rl->clientIp(), 'submit')) {
        payHeader('پرداخت');
        publicFriendlyError('تعداد درخواست‌های پرداخت شما بیش از حد مجاز است؛ لطفاً کمی بعد تلاش کنید.');
        payEnd();
        $mysqli->close();
        exit;
    }

    $formSlug = trim((string) ($_POST['slug'] ?? ''));
    $form = $formSlug !== '' ? $svc->forms()->findBySlug($formSlug) : null;
    if ($form === null) {
        payHeader('پرداخت');
        publicFriendlyError('فرم پرداخت یافت نشد.');
        payEnd();
        $mysqli->close();
        exit;
    }

    payPublicSessionStart();
    $token = (string) ($_POST['token'] ?? '');
    $expected = (string) ($_SESSION['pay_token'] ?? '');
    if ($token === '' || !hash_equals($expected, $token)) {
        payHeader(pe($form->title));
        publicFriendlyError('نشست نامعتبر است؛ لطفاً صفحه را بارگذاری مجدد کنید.');
        payEnd();
        $mysqli->close();
        exit;
    }

    // Normalize namespaced dynamic fields (f[...]) into flat keys the backend
    // expects; the real amount comes from the top-level `amount` field.
    $submitted = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
    $submitted['amount'] = (string) ($_POST['amount'] ?? '');
    $submitted['slug'] = $formSlug;

    $begin = $svc->begin($form, $submitted);
    if (!$begin['ok']) {
        payHeader(pe($form->title));
        echo renderLocalForm($form, (string) $begin['message']);
        payEnd();
        $mysqli->close();
        exit;
    }

    $tx = $begin['transaction'];
    $gate = $svc->requestGatewayPayment($tx, publicCallbackUrl());

    if (!$gate['ok'] || empty($gate['redirect'])) {
        payHeader('پرداخت');
        publicFriendlyError($gate['message']);
        payEnd();
        $mysqli->close();
        exit;
    }

    header('Location: ' . $gate['redirect']);
    $mysqli->close();
    exit;
}

/* ============================ SHOW FORM ============================ */
$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug !== '') {
    $form = $svc->forms()->findActiveBySlug($slug);
} else {
    $defaultId = $settingsRepo->defaultFormId();
    $form = $defaultId ? $svc->forms()->find($defaultId) : null;
    if ($form === null || !$form->isActive) {
        foreach ($svc->forms()->all() as $c) {
            if ($c->isActive) {
                $form = $c;
                break;
            }
        }
    }
}

if ($form === null || !$form->isActive) {
    payHeader('پرداخت');
    echo '<div class="pay-card"><p style="text-align:center;padding:24px;">فرم پرداخت در دسترس نیست.</p></div>';
    payEnd();
    $mysqli->close();
    exit;
}

payPublicSessionStart();
if (empty($_SESSION['pay_token'])) {
    $_SESSION['pay_token'] = bin2hex(random_bytes(24));
}

payHeader(pe($form->title));
echo renderLocalForm($form, '');
payEnd();
$mysqli->close();
exit;

/* ------------------------- local helpers ------------------------- */
function payHeader(string $title): void
{
    global $pageTitle;
    $pageTitle = $title;
    include __DIR__ . '/assets/php/payment/public/layout/header.php';
}
function payEnd(): void
{
    include __DIR__ . '/assets/php/payment/public/layout/footer.php';
}
function renderLocalForm(PaymentForm $form, string $errors): string
{
    $token = (string) ($_SESSION['pay_token'] ?? '');
    $actionUrl = 'pay.php';
    $theme = $form->theme === 'clean' ? 'clean' : 'default';
    $tpl = __DIR__ . '/assets/php/payment/public/templates/' . $theme . '.php';
    if (!is_file($tpl)) {
        $tpl = __DIR__ . '/assets/php/payment/public/templates/default.php';
    }
    ob_start();
    include $tpl;
    return (string) ob_get_clean();
}