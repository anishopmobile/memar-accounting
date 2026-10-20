<?php
/**
 * payment_sms_settings.php — Administrator SMS (FarazSMS) settings.
 *
 * GET  action=get  → read settings with API key MASKED (never raw)
 * POST action=save → persist SMS settings (CSRF required)
 * POST action=test → test the API key via balance endpoint (CSRF required)
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
        'sms_api_key_masked' => PaymentSettingsRepository::maskSecret((string) ($s['sms_api_key'] ?? '')),
        'has_sms_api_key' => !empty($s['sms_api_key']),
        'sms_sender_line' => (string) ($s['sms_sender_line'] ?? ''),
        'sms_customer_enabled' => (bool) ($s['sms_customer_enabled'] ?? false),
        'sms_admin_enabled' => (bool) ($s['sms_admin_enabled'] ?? false),
        'sms_success_pattern_code' => (string) ($s['sms_success_pattern_code'] ?? ''),
        'sms_admin_pattern_code' => (string) ($s['sms_admin_pattern_code'] ?? ''),
        'sms_admin_mobile' => (string) ($s['sms_admin_mobile'] ?? ''),
    ]);
}

/* ---- All mutating actions require CSRF ---- */
require_csrf_or_fail();

/* ---- SAVE ---- */
if ($action === 'save') {
    $ok = $settingsRepo->saveSms($_POST);
    if (!$ok) {
        respond_error('خطا در ذخیره‌سازی تنظیمات پیامک.');
    }
    respond_json(true, 'تنظیمات پیامک ذخیره شد.');
}

/* ---- TEST connection ---- */
if ($action === 'test') {
    $provider = new FarazSmsProvider(
        (string) ($settingsRepo->get()['sms_api_key'] ?? ''),
        (string) ($settingsRepo->get()['sms_sender_line'] ?? '')
    );
    $result = $provider->testConnection();
    respond_json($result->ok, $result->message, $result->data);
}

respond_error('عملیات نامعتبر است.');