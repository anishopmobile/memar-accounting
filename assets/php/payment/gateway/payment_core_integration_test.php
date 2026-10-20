<?php
/**
 * payment_core_integration_test.php — Integration test for the payment core.
 *
 * Verifies form/transaction repositories + service + gateway wiring against
 * the real database. Exercises: amount resolution, transaction create,
 * idempotent paid guard, callback-parse, and state transitions.
 *
 * Run from CLI with the Laragon PHP binary:
 *   php assets/php/payment/gateway/payment_core_integration_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../payment_loader.php';

$failures = 0;
function t(bool $cond, string $label): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . ' - ' . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

$svc = new PaymentService($mysqli);

// ---- 1. Seed a fixed-amount form (unique slug to avoid collisions) ----
$slug = 'test-gw-' . date('YmdHis');
$form = new PaymentForm();
$form->title = 'فرم تست درگاه';
$form->slug = $slug;
$form->description = 'تست';
$form->amountMode = 'fixed';
$form->fixedAmount = 1500000; // 1,500,000 ریال
$form->isActive = true;
$form->theme = 'default';
$formId = $svc->forms()->save($form);

$loaded = $svc->forms()->find($formId);
t($loaded !== null && $loaded->authoritativeAmount() === 1500000, 'form saved and authoritative amount stored');
t($svc->forms()->slugExists($slug) === true, 'slug uniqueness check detects collision');

// ---- 2. Client tries to tamper the amount on a fixed form ----
$result = $svc->begin($loaded, ['name' => 'تست', 'mobile' => '09171234567', 'amount' => '999999']);
t($result['ok'] === true, 'fixed form ignores client amount');
t($result['transaction'] !== null && $result['transaction']->amount === 1500000, 'amount resolved to server value');

$tx = $result['transaction'];

// ---- 3. Callback with wrong authority ----
$cbWrong = $svc->handleCallback('WRONG-AUTH', ['Status' => 'OK', 'Authority' => 'WRONG-AUTH']);
t($cbWrong['ok'] === false, 'unknown authority rejected');

// ---- 4. Request gateway (no merchant configured -> clean failure, tx marked failed) ----
$req = $svc->requestGatewayPayment($tx, 'https://example.com/cb');
t($req['ok'] === false, 'request fails cleanly without merchant id');

// ---- 5. Full state machine + idempotency (repo-level, no gateway) ----
$auth = 'A_TEST_' . date('YmdHis') . substr(bin2hex(random_bytes(4)), 0, 6);
$tx2res = $svc->transactions()->create($formId, 1000000, 'zarinpal', ['name' => 'تست', 'mobile' => '09171234567']);
$tx2 = $tx2res['transaction'];
t($tx2 !== null && $tx2->status === 'created', 'tx created in created state');

t($svc->transactions()->assignAuthority($tx2->id, $auth, 'created') === true, 'authority assigned, status -> pending');
t($svc->transactions()->find($tx2->id)->status === 'pending', 'status now pending');

t($svc->transactions()->markCallbackReceived($tx2->id, $auth) === true, 'callback received recorded');
t($svc->transactions()->find($tx2->id)->status === 'callback_received', 'status is callback_received (NOT paid)');

t($svc->transactions()->markVerifying($tx2->id) === true, 'mark verifying');
t($svc->transactions()->markPaid($tx2->id, $auth, 'REF_1001', '{"code":100}') === true, 'verify success -> paid');
t($svc->transactions()->find($tx2->id)->isPaid() === true, 'tx is paid');
t($svc->transactions()->find($tx2->id)->refId === 'REF_1001', 'ref_id stored');

/* idempotency: duplicate callback must NOT double-pay or fail the record */
t($svc->transactions()->markPaid($tx2->id, $auth, 'REF_1001', '{"code":100}') === false, 'duplicate markPaid returns false (safe)');
t($svc->transactions()->find($tx2->id)->isPaid() === true, 'paid stays paid after duplicate');
t($svc->transactions()->markFailed($tx2->id, 'x') === false, 'failed cannot overwrite paid');
t($svc->transactions()->transition($tx2->id, 'callback_received', 'verifying') === false, 'illegal backward transition blocked');

/* forged callback via service cannot unpush a paid tx */
$cbForged = $svc->handleCallback($auth, ['Status' => 'NOK', 'Authority' => $auth]);
t($cbForged['ok'] === false && $cbForged['transaction'] !== null && $cbForged['transaction']->isPaid() === true, 'forged non-OK callback cannot unpush paid tx');

echo "\n";
echo ($failures === 0 ? 'ALL CORE INTEGRATION TESTS PASSED' : 'FAILURES: ' . $failures) . "\n";

// cleanup form: transactions block FK deletion (intended) so delete txs first
$mysqli->query('DELETE FROM payment_transactions WHERE form_id = ' . (int) $formId);
$svc->forms()->delete($formId);
$mysqli->close();