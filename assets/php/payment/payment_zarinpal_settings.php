<?php
/**
 * payment_zarinpal_settings.php — Administrator ZarinPal gateway settings.
 *
 *  GET  action=get  → read settings with Merchant ID MASKED (never raw)
 *  POST action=save → persist ZarinPal settings (CSRF required, server-side validation)
 *  POST action=test → safe configuration validation (CSRF required)
 *
 * The `test` action is explicitly NOT a live transaction test: the current
 * ZarinPal v4 API has no free credential-validity endpoint, and a real sandbox
 * request would create an actual pending payment — which the spec forbids.
 * It therefore validates the saved configuration and reports the active
 * environment without contacting the gateway or creating a transaction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/payment_loader.php';

$settingsRepo = new PaymentSettingsRepository($mysqli);
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

/* ---- GET (masked) ---- */
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = $settingsRepo->get();
    respond_json(true, '', [
        'merchant_id_masked' => PaymentSettingsRepository::maskSecret((string) ($s['zarinpal_merchant_id'] ?? '')),
        'has_merchant_id' => !empty($s['zarinpal_merchant_id']),
        'zarinpal_sandbox' => (bool) ($s['zarinpal_sandbox'] ?? false),
        'zarinpal_gateway_enabled' => (bool) ($s['zarinpal_gateway_enabled'] ?? false),
        'zarinpal_callback_url' => (string) ($s['zarinpal_callback_url'] ?? ''),
        'zarinpal_default_description' => (string) ($s['zarinpal_default_description'] ?? ''),
    ]);
}

/* ---- All mutating actions require CSRF ---- */
require_csrf_or_fail();

/* ---- SAVE (server-side validation before persistence) ---- */
if ($action === 'save') {
    $enabled = (string) ($_POST['zarinpal_gateway_enabled'] ?? '');
    if (!in_array($enabled, ['0', '1', '', 'on', 'true'], true)) {
        respond_error('مقدار وضعیت درگاه نامعتبر است.');
    }
    $sandbox = (string) ($_POST['zarinpal_sandbox'] ?? '');
    if (!in_array($sandbox, ['0', '1', '', 'on'], true)) {
        respond_error('محیط درگاه نامعتبر است.');
    }

    $merchant = trim((string) ($_POST['merchant_id'] ?? ''));
    // Merchant ID: reject control/whitespace chars and over-long values.
    if ($merchant !== '' && (mb_strlen($merchant, 'UTF-8') > 64 || preg_match('/[\x00-\x20\x7F]/', $merchant) === 1)) {
        respond_error('Merchant ID نامعتبر است.');
    }

    // Empty merchant keeps the stored value (masked edit). If the gateway is
    // (or is being) enabled with neither a stored nor a new merchant, reject.
    $current = $settingsRepo->get();
    $hasStored = !empty($current['zarinpal_merchant_id']);
    if ($merchant === '' && !$hasStored && !empty($enabled)) {
        respond_error('وقتی درگاه فعال است، Merchant ID الزامی است.');
    }

    $ok = $settingsRepo->saveZarinpal($_POST);
    if (!$ok) {
        respond_error('خطا در ذخیره‌سازی تنظیمات زرین‌پال.');
    }
    respond_json(true, 'تنظیمات درگاه زرین‌پال ذخیره شد.');
}

/* ---- TEST configuration (no live transaction) ---- */
if ($action === 'test') {
    $s = $settingsRepo->get();
    $merchant = (string) ($s['zarinpal_merchant_id'] ?? '');
    $sandbox = (bool) ($s['zarinpal_sandbox'] ?? false);
    $enabled = (bool) ($s['zarinpal_gateway_enabled'] ?? false);

    if ($merchant === '') {
        respond_json(false, 'Merchant ID تنظیم نشده است؛ ابتدا آن را ذخیره کنید.');
    }
    if (mb_strlen($merchant, 'UTF-8') > 64 || preg_match('/[\x00-\x20\x7F]/', $merchant) === 1) {
        respond_json(false, 'Merchant ID ذخیره‌شده نامعتبر است؛ آن را اصلاح کنید.');
    }
    if (!$enabled) {
        respond_json(false, 'درگاه زرین‌پال در تنظیمات غیرفعال است.');
    }

    // The exact endpoint the payment flow will use is fixed by the selected
    // environment (single authoritative source = same rows the gateway reads).
    $endpoint = $sandbox
        ? 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'
        : 'https://api.zarinpal.com/pg/v4/payment/request.json';

    respond_json(true, 'تنظیمات درگاه معتبر است. (این بررسی، تست تراکنش واقعی نیست؛ زرین‌پال سرویس رایگان اعتبارسنجی Merchant ID ندارد.)', [
        'environment' => $sandbox ? 'sandbox' : 'live',
        'environment_label' => $sandbox ? 'آزمایشی (Sandbox)' : 'واقعی (Live)',
        'gateway_enabled' => $enabled,
        'endpoint' => $endpoint,
    ]);
}

respond_error('عملیات نامعتبر است.');