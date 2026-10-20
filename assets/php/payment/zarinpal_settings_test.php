<?php
/**
 * zarinpal_settings_test.php — Tests for the ZarinPal gateway settings feature:
 * masking, persistence (save / masked-edit / update), environment/gateway flags,
 * and the gateway-enabled enforcement in PaymentService (begin + request).
 *
 * The test temporarily mutates payment_settings row 1 and restores the exact
 * original row at the end — it never leaves test credentials behind.
 *
 * Run with the Laragon PHP binary (mysqli enabled):
 *   php assets/php/payment/zarinpal_settings_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/payment_loader.php';

$failures = 0;
function t(bool $cond, string $label): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . ' - ' . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

$settingsRepo = new PaymentSettingsRepository($mysqli);
$svc = new PaymentService($mysqli);

// Snapshot the exact original settings so we can restore afterwards.
$original = $settingsRepo->get();

/* ---------- 1. maskSecret ---------- */
t(PaymentSettingsRepository::maskSecret(null) === '', 'maskSecret null -> empty');
t(PaymentSettingsRepository::maskSecret('') === '', 'maskSecret empty -> empty');
t(PaymentSettingsRepository::maskSecret('abcd') === '****', 'maskSecret short fully masked');
t(PaymentSettingsRepository::maskSecret('abcdefghij') === 'abc****hij', 'maskSecret long keeps edges only');
t(strpos(PaymentSettingsRepository::maskSecret('TOP_SECRET_X'), 'TOP_SECRET_X') === false, 'maskSecret never reveals full value');

/* ---------- 2. Save merchant + environment + gateway flag ---------- */
t($settingsRepo->saveZarinpal([
    'merchant_id' => 'A_TEST_MERCHANT_001',
    'zarinpal_sandbox' => '1',
    'zarinpal_gateway_enabled' => '1',
]) === true, 'saveZarinpal persists merchant/sandbox/enabled');
$s = $settingsRepo->get();
t(($s['zarinpal_merchant_id'] ?? '') === 'A_TEST_MERCHANT_001', 'merchant persisted verbatim');
t((int) $s['zarinpal_sandbox'] === 1, 'sandbox persisted as 1');
t((int) $s['zarinpal_gateway_enabled'] === 1, 'gateway enabled persisted as 1');

/* ---------- 3. Masked edit: empty merchant keeps stored value ---------- */
t($settingsRepo->saveZarinpal([
    'merchant_id' => '',
    'zarinpal_sandbox' => '0',
    'zarinpal_gateway_enabled' => '1',
]) === true, 'save with empty merchant + sandbox 0 accepted');
$s2 = $settingsRepo->get();
t(($s2['zarinpal_merchant_id'] ?? '') === 'A_TEST_MERCHANT_001', 'empty merchant keeps stored value (masked edit safe)');
t((int) $s2['zarinpal_sandbox'] === 0, 'environment toggled to live (0)');

/* ---------- 4. Merchant can be updated ---------- */
t($settingsRepo->saveZarinpal([
    'merchant_id' => 'B_TEST_MERCHANT_002',
    'zarinpal_sandbox' => '0',
    'zarinpal_gateway_enabled' => '1',
]) === true, 'merchant update persisted');
$s3 = $settingsRepo->get();
t(($s3['zarinpal_merchant_id'] ?? '') === 'B_TEST_MERCHANT_002', 'merchant updated to new value');

/* ---------- 5. Disabled gateway blocks begin() without polluting data ---------- */
t($svc->isGatewayEnabled() === true, 'gateway reports enabled while enabled');

$form = new PaymentForm();
$form->title = 'فرم تست زرین‌پال ' . date('YmdHis');
$form->slug = 'zsett-' . date('YmdHis');
$form->amountMode = 'fixed';
$form->fixedAmount = 1500000;
$form->isActive = true;
$form->theme = 'default';
$formId = $svc->forms()->save($form);
t($formId > 0, 'test form seeded');

$loaded = $svc->forms()->find($formId);

$settingsRepo->saveZarinpal([
    'merchant_id' => '',
    'zarinpal_sandbox' => '0',
    'zarinpal_gateway_enabled' => '', // empty -> disabled (0)
]);
t($svc->isGatewayEnabled() === false, 'gateway reports disabled when flag off');

$beginBefore = (int) $mysqli->query('SELECT COUNT(*) AS c FROM payment_transactions WHERE form_id = ' . (int) $formId)->fetch_assoc()['c'];
$res = $svc->begin($loaded, ['name' => 'تست', 'mobile' => '09171234567', 'amount' => '1500000']);
t($res['ok'] === false, 'begin() blocked when gateway disabled');
t($res['transaction'] === null, 'no transaction returned while disabled');
t(trim((string) $res['message']) !== '', 'safe Persian message returned while disabled');
$beginAfter = (int) $mysqli->query('SELECT COUNT(*) AS c FROM payment_transactions WHERE form_id = ' . (int) $formId)->fetch_assoc()['c'];
t($beginAfter === $beginBefore, 'disabled gateway creates NO transaction');

/* ---------- 6. Enabled gateway uses stored config in begin() ---------- */
$settingsRepo->saveZarinpal([
    'merchant_id' => 'B_TEST_MERCHANT_002',
    'zarinpal_sandbox' => '1',
    'zarinpal_gateway_enabled' => '1',
]);
t($svc->isGatewayEnabled() === true, 'gateway enabled again');
$res2 = $svc->begin($loaded, ['name' => 'تست', 'mobile' => '09171234567', 'amount' => '1500000']);
t($res2['ok'] === true && $res2['transaction'] !== null, 'begin() creates transaction when enabled');
$tx = $res2['transaction'];
t($tx->amount === 1500000, 'server-authoritative amount stored');

/* ---------- 7. requestGatewayPayment defense-in-depth ---------- */
t($svc->requestGatewayPayment($tx, 'https://example.com/cb')['ok'] === false, 'gateway request fails cleanly (no live call)');
$settingsRepo->saveZarinpal([
    'merchant_id' => 'B_TEST_MERCHANT_002',
    'zarinpal_sandbox' => '1',
    'zarinpal_gateway_enabled' => '', // disabled again
]);
$req = $svc->requestGatewayPayment($tx, 'https://example.com/cb');
t($req['ok'] === false && stripos((string) $req['message'], 'زرین‌پال') !== false, 'requestGatewayPayment blocked when disabled');
t($req['redirect'] === null, 'no redirect produced while disabled');

/* ---------- 8. Gateway environment wiring ---------- */
$gw = $svc->gateway();
t($gw instanceof ZarinPalGateway, 'gateway() returns single ZarinPal gateway class');
$liveGw = new ZarinPalGateway('MERCHANT', false);
$sandboxGw = new ZarinPalGateway('MERCHANT', true);
$liveRedir = $liveGw->redirectUrl(['authority' => 'A00000000000000000000000000000000000']);
$sandRedir = $sandboxGw->redirectUrl(['authority' => 'A00000000000000000000000000000000000']);
t(strpos($liveRedir['url'], 'https://www.zarinpal.com/pg/StartPay/') === 0, 'live environment uses live StartPay endpoint');
t(strpos($sandRedir['url'], 'https://sandbox.zarinpal.com/pg/StartPay/') === 0, 'sandbox environment uses sandbox StartPay endpoint');

/* ---------- 9. Cleanup test artifacts ---------- */
$mysqli->query('DELETE FROM payment_transactions WHERE form_id = ' . (int) $formId);
$svc->forms()->delete($formId);
t($svc->forms()->find($formId) === null, 'test form cleaned up');

/* ---------- 10. Restore exact original settings row ---------- */
$origMerchant = $original['zarinpal_merchant_id'] ?? null;
$origSandbox = (int) ($original['zarinpal_sandbox'] ?? 0);
$origEnabled = (int) ($original['zarinpal_gateway_enabled'] ?? 1);
$stmt = $mysqli->prepare(
    'UPDATE payment_settings SET zarinpal_merchant_id = ?, zarinpal_sandbox = ?, zarinpal_gateway_enabled = ? WHERE id = 1'
);
$stmt->bind_param('sii', $origMerchant, $origSandbox, $origEnabled);
t($stmt->execute() === true, 'original settings row restored');
$stmt->close();
$restored = $settingsRepo->get();
t(($restored['zarinpal_merchant_id'] ?? null) === $origMerchant, 'restored merchant equals original (NULL)');
t((int) $restored['zarinpal_sandbox'] === $origSandbox && (int) $restored['zarinpal_gateway_enabled'] === $origEnabled, 'restored sandbox + enabled flags match original');
t($svc->isGatewayEnabled() === (bool) $origEnabled, 'gateway enabled state matches original after restore');

echo "\n" . ($failures === 0 ? 'ALL ZARINPAL SETTINGS TESTS PASSED' : 'FAILURES: ' . $failures) . "\n";
$mysqli->close();