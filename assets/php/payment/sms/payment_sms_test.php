<?php
/**
 * payment_sms_test.php — PHASE 8 test: SMS service + provider abstraction.
 *
 * Verifies:
 *  - provider implements interface
 *  - Iran mobile validation (09xxxxxxxxx, rejects +98)
 *  - disabled SMS yields disabled log rows (no exceptions, payment unaffected)
 *  - dedup: second notify for same paid tx does not create a new send
 *  - values map building (customer/admin)
 *  - a fake provider failure does NOT change payment status (paid stays)
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

/* ---- provider abstraction ---- */
$provider = new FarazSmsProvider('', '90008361', 1);
t($provider instanceof SmsProviderInterface, 'FarazSmsProvider implements SmsProviderInterface');

/* no api key -> clean false result, no throw */
$r = $provider->sendPattern('09171234567', '12345', ['amount' => '1,000']);
t($r->ok === false && strpos($r->message, 'کلید') !== false, 'sendPattern without api key returns clean failure');

$r = $provider->testConnection();
t($r->ok === false && strpos($r->message, 'کلید') !== false, 'testConnection without api key returns clean failure');

/* mobile validation via a throwaway: valid/invalid */
$rp = new ReflectionClass('FarazSmsProvider');
$mv = $rp->getMethod('isValidIranMobile');
$mv->setAccessible(true);
$inst = new FarazSmsProvider('key');
t($mv->invoke($inst, '09171234567') === true, '09000000000 format accepted');
t($mv->invoke($inst, '989171234567') === false, '+98917... rejected');

/* ---- seed a paid transaction + form ---- */
$svc = new PaymentService($mysqli);
$formRepo = $svc->forms();
$txRepo = $svc->transactions();
$suffix = date('YmdHis');
$form = new PaymentForm();
$form->title = 'SMS test form ' . $suffix;
$form->slug = 'sms-test-' . $suffix;
$form->amountMode = 'fixed';
$form->fixedAmount = 500000;
$form->purpose = 'بدهی قبلی';
$form->isActive = true;
$formId = $formRepo->save($form);

$res = $txRepo->create($formId, 500000, 'zarinpal', ['name' => 'حمید نعیمی', 'mobile' => '09179079543']);
$tx = $res['transaction'];
$auth = 'SMSAUTH' . substr(bin2hex(random_bytes(6)), 0, 12);
$txRepo->assignAuthority($tx->id, $auth, 'created');
$txRepo->markCallbackReceived($tx->id, $auth);
$txRepo->markVerifying($tx->id);
$txRepo->markPaid($tx->id, $auth, 'REF-SMS-' . $tx->id, '{"code":100}');
$paidTx = $txRepo->find($tx->id);
t($paidTx->isPaid() === true, 'tx is paid (verified)');

/* ---- SMS service with all settings disabled ---- */
$sms = new SmsService($mysqli);
$results = $sms->notifyPaid($paidTx);
t(!$results['customer']->ok && !$results['admin']->ok, 'disabled SMS returns non-ok results (no throw)');
// verify log rows created with status disabled
$nr = new PaymentNotificationRepository($mysqli);
$logs = $nr->forTransaction($paidTx->id);
t(count($logs) >= 2, 'notification log rows created for disabled sends');
$paidRecheck = $txRepo->find($tx->id);
t($paidRecheck->isPaid() === true, 'disabled SMS does NOT change payment status');

/* ---- dedup: calling notify again must not add more enabled sends ---- */
$results2 = $sms->notifyPaid($paidTx);
$logs2 = $nr->forTransaction($paidTx->id);
t(count($logs2) === count($logs), 'duplicate notify() does not create extra log rows (idempotent)');

/* ---- values maps ---- */
$loadedForm = $formRepo->find($formId);
$customerValues = $sms->customerValues($paidTx);
t(isset($customerValues['tracking_code']) && $customerValues['tracking_code'] === 'REF-SMS-' . $tx->id, 'customer values include tracking code from verified ref');
$adminValues = $sms->adminValues($paidTx, $loadedForm);
t($adminValues['mobile'] === '09179079543' && $adminValues['purpose'] === 'بدهی قبلی', 'admin values include mobile + purpose');

/* ---- clean up ---- */
$mysqli->query('DELETE FROM payment_notifications WHERE transaction_id = ' . (int) $paidTx->id);
$mysqli->query('DELETE FROM payment_transactions WHERE id = ' . (int) $paidTx->id);
$formRepo->delete($formId);

echo "\n" . ($failures === 0 ? 'ALL SMS TESTS PASSED' : 'FAILURES: ' . $failures) . "\n";
$mysqli->close();