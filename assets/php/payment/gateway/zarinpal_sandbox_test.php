<?php
/**
 * zarinpal_sandbox_test.php — Sandbox smoke test for ZarinPal gateway.
 *
 * This is a CLAUDE-side verification harness, NOT an application endpoint.
 * It exercises the gateway contract logic (parseCallback, redirectUrl) and,
 * when a sandbox merchant id is present, attempts a live sandbox request.
 *
 * No real credentials are embedded; the sandbox merchant id is read from the
 * payment_settings row if present, otherwise only offline assertions run.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../payment_loader.php';

function assertTrue(bool $cond, string $label): void
{
    echo ($cond ? 'PASS' : 'FAIL') . ' - ' . $label . "\n";
    if (!$cond) {
        global $failures;
        $failures++;
    }
}

$failures = 0;
$settingsRepo = new PaymentSettingsRepository($mysqli);
$settings = $settingsRepo->get();
$merchant = (string) ($settings['zarinpal_merchant_id'] ?? '');
$sandbox = (bool) ($settings['zarinpal_sandbox'] ?? false);

echo "Sandbox flag: " . ($sandbox ? 'yes' : 'no') . ", merchant set: " . ($merchant === '' ? 'no' : 'yes') . "\n";

// 1. Gateway construction from settings (no hard-coded credentials)
$gw = new ZarinPalGateway($merchant, $sandbox);
assertTrue($gw instanceof PaymentGatewayInterface, 'ZarinPalGateway implements interface');

// 2. Money conversion (IRR -> Toman), integer only
assertTrue(PaymentMoney::toGatewayToman(100000) === 10000, 'toGatewayToman 100000 IRR = 10000 Toman');
assertTrue(PaymentMoney::normalize('1,000,000') === 1000000, 'normalize handles separators');
assertTrue(PaymentMoney::normalize('۱۲۳') === 123, 'normalize handles Persian digits');

// 3. State machine: paid is terminal, callback != paid
assertTrue(PaymentStateMachine::can(PaymentStateMachine::PENDING, PaymentStateMachine::CALLBACK_RECEIVED), 'pending->callback_received allowed');
assertTrue(!PaymentStateMachine::can(PaymentStateMachine::CALLBACK_RECEIVED, PaymentStateMachine::PAID), 'callback_received->paid NOT allowed (verify first)');
assertTrue(PaymentStateMachine::can(PaymentStateMachine::VERIFYING, PaymentStateMachine::PAID), 'verifying->paid allowed');

// 4. parseCallback: NOT proof of payment
$cbOk = $gw->parseCallback(['Status' => 'OK', 'Authority' => 'A000000000000000000000000000000000']);
assertTrue($cbOk->ok === true, 'parseCallback OK');
$cbBad = $gw->parseCallback(['Status' => 'NOK', 'Authority' => '']);
assertTrue($cbBad->ok === false, 'parseCallback rejects non-OK');

// 5. redirectUrl builds start URL
$redir = $gw->redirectUrl(['authority' => 'A000000000000000000000000000000000']);
assertTrue($redir['ok'] === true && strpos($redir['url'], 'StartPay/') !== false, 'redirectUrl builds StartPay URL');

// 6. If sandbox id configured, attempt request + (optional) verify.
if ($sandbox && $merchant !== '') {
    echo "Attempting sandbox request...\n";
    $req = $gw->requestPayment(100000, 'https://example.com/callback', 'تست درگاه', 'PT-000001');
    assertTrue($req->ok === true, 'sandbox request returns ok');
    if ($req->ok && !empty($req->payload['authority'])) {
        echo 'Authorities: ' . $req->payload['authority'] . "\n";
        $verify = $gw->verifyPayment((string) $req->payload['authority'], 100000);
        echo 'Verify (expected to fail for uncompleted payment): ok=' . var_export($verify->ok, true) . "\n";
    } else {
        echo 'Request message: ' . $req->message . "\n";
    }
} else {
    echo "SKIP live sandbox request (no sandbox merchant id stored). Offline assertions only.\n";
}

echo ($failures === 0 ? "\nALL ASSERTIONS PASSED" : "\nFAILURES: " . $failures) . "\n";
$mysqli->close();