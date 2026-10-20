<?php
/**
 * payment_transaction_admin_test.php — PHASE 6 test: search filters,
 * pagination, safe sorting, dashboard stats, transaction detail.
 *
 * Seeds a form + transactions in multiple states, then asserts:
 *  - status filter, amount filters, mobile/reference filters
 *  - date range filter
 *  - pagination dimension
 *  - safe sorting (invalid sort column falls back)
 *  - dashboard aggregates
 *  - detail (get) serialization
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

$svc = new PaymentService($mysqli);
$txRepo = $svc->transactions();
$formRepo = $svc->forms();

// --- isolation: remove any leftover test fixtures from interrupted/prior runs ---
$mysqli->query(
    "DELETE t FROM payment_transactions t JOIN payment_forms f ON f.id = t.form_id
     WHERE f.slug LIKE 'tx-test-%' OR f.slug LIKE 'test-gw-%' OR f.slug LIKE 'http-seed-%'
        OR f.slug LIKE 'pay-sample-%' OR f.slug IN ('testpay','tst-kopi','dup-%')"
);
$mysqli->query(
    "DELETE FROM payment_forms
     WHERE slug LIKE 'tx-test-%' OR slug LIKE 'test-gw-%' OR slug LIKE 'http-seed-%'
        OR slug LIKE 'pay-sample-%' OR slug IN ('testpay','tst-kopi') OR slug LIKE 'dup-%'"
);

// --- seed a form ---
$suffix = date('YmdHis');
$form = new PaymentForm();
$form->title = 'تست تراکنش ' . $suffix;
$form->slug = 'tx-test-' . $suffix;
$form->amountMode = 'fixed';
$form->fixedAmount = 1000000;
$form->isActive = true;
$formId = $formRepo->save($form);

// helper to create a transaction then force its state/authority/ref
function makeTx($repo, int $formId, int $amount, string $status, string $mobile, $auth, $ref): int
{
    $r = $repo->create($formId, $amount, 'zarinpal', ['name' => 'کاربر', 'mobile' => $mobile, 'amount' => $amount]);
    $id = $r['transaction']->id;
    // set authority/ref and status directly (tests don't touch the state machine)
    $repo->assignAuthority($id, $auth, 'created');   // created -> pending
    if ($status === 'pending') {
        return $id; // stays pending
    }
    $repo->markCallbackReceived($id, $auth);          // pending -> callback_received
    $repo->markVerifying($id);                          // -> verifying
    if ($status === 'paid') {
        $repo->markPaid($id, $auth, $ref !== null ? $ref : ('REF' . $id), '{"code":100}');
    } elseif ($status === 'failed') {
        $repo->markFailed($id, 'خطای تست');
    }
    return $id;
}
// seed some transactions
$auth = 'AUTH_' . $suffix . '_';
$paidId = makeTx($txRepo, $formId, 1000000, 'paid', '09171111111', $auth . '000001', 'REF1000001');
$paid2Id = makeTx($txRepo, $formId, 2000000, 'paid', '09172222222', $auth . '000002', 'REF1000002');
$failedId = makeTx($txRepo, $formId, 500000, 'failed', '09173333333', $auth . '000003', null);
$pendingId = makeTx($txRepo, $formId, 750000, 'pending', '09174444444', $auth . '000004', null);

// ---- status filter ----
$res = $txRepo->search(['status' => 'paid']);
t($res['total'] === 2 && count($res['items']) === 2, 'paid filter returns 2');

$res = $txRepo->search(['status' => 'failed']);
t($res['total'] === 1 && count($res['items']) === 1, 'failed filter returns 1');

$res = $txRepo->search(['status' => 'pending']);
t($res['total'] === 1 && count($res['items']) === 1, 'pending filter returns 1');

// ---- amount filter ----
$res = $txRepo->search(['amount_min' => 1000000]);
t($res['total'] === 2, 'amount_min >= 1,000,000 returns 2');

$res = $txRepo->search(['amount_min' => 1000000, 'amount_max' => 1500000]);
t($res['total'] === 1, 'amount range 1M..1.5M returns 1');

// ---- mobile / reference filter ----
$res = $txRepo->search(['mobile' => '09172222222']);
t($res['total'] === 1, 'mobile exact filter returns 1');

$res = $txRepo->search(['reference' => 'REF1000002']);
t($res['total'] === 1, 'reference filter returns 1');

$res = $txRepo->search(['search' => 'REF1000001']);
t($res['total'] === 1, 'general search by ref works');

// ---- date filter (today) ----
$today = date('Y-m-d');
$res = $txRepo->search(['date_from' => $today, 'date_to' => $today]);
t($res['total'] === 4, 'date range today returns all 4');

$res = $txRepo->search(['date_from' => '2030-01-01']);
t($res['total'] === 0, 'future date_from returns 0');

// ---- search text ----
$res = $txRepo->search(['search' => $auth]);
t($res['total'] === 4, 'search by authority prefix returns all 4');

// ---- pagination ----
$res = $txRepo->search(['page' => 1, 'per_page' => 2]);
t($res['total'] === 4 && $res['totalPages'] === 2 && count($res['items']) === 2, 'page 1 per 2 shows 2 of 4');

$res = $txRepo->search(['page' => 2, 'per_page' => 2]);
t(count($res['items']) === 2, 'page 2 per 2 shows remaining 2');

$res = $txRepo->search(['page' => 99, 'per_page' => 20]);
t($res['page'] === 1 && $res['total'] === 4, 'page out of range clamps to last');

// ---- safe sorting ----
$res = $txRepo->search(['sort' => 'amount', 'dir' => 'asc']);
$amounts = array_map(function ($t) { return $t->amount; }, $res['items']);
t($amounts === [500000, 750000, 1000000, 2000000], 'amount asc sorted');

$res = $txRepo->search(['sort' => 'INVALID;DROP', 'dir' => 'desc']);
t($res['total'] === 4, 'invalid sort column falls back safely (no SQL error)');

$res = $txRepo->search(['sort' => 'date', 'dir' => 'ASC']);
t(count($res['items']) === 4, 'sort dir asc works');

// ---- dashboard stats ----
$stats = $txRepo->dashboardStats();
t((int) $stats['total_all'] >= 4, 'dashboard total_all at least 4');
t((int) $stats['total_paid'] >= 2, 'dashboard total_paid at least 2');
t((int) $stats['total_failed'] >= 1, 'dashboard total_failed at least 1');
t((int) $stats['total_pending'] >= 1, 'dashboard total_pending at least 1');
t((int) $stats['paid_today'] >= 2, 'dashboard paid_today at least 2');
t((int) $stats['total_revenue'] >= 3000000, 'dashboard total_revenue at least 3,000,000');

// ---- detail (find) ----
$det = $txRepo->find($paidId);
t($det !== null && $det->isPaid() && $det->refId === 'REF1000001', 'detail find returns paid tx with ref');

// clean up test rows
$mysqli->query('DELETE FROM payment_transactions WHERE form_id = ' . (int) $formId);
$formRepo->delete($formId);

echo "\n" . ($failures === 0 ? 'ALL TRANSACTION ADMIN TESTS PASSED' : 'FAILURES: ' . $failures) . "\n";
$mysqli->close();