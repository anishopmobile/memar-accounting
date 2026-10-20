<?php
/**
 * payment_transaction_action.php — Administrator transaction list/search/details.
 *
 * All reads are GET (authentication via boot.php). No state is changed here,
 * so CSRF is not required — consistent with invoice_list.php / invoice_get.php
 * which are GET without CSRF. Prepared statements throughout.
 */
declare(strict_types=1);

require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/payment_loader.php';

$svc = new PaymentService($mysqli);
$action = (string) ($_GET['action'] ?? '');

/** Format a MySQL datetime as Persian date + HH:MM (jdate lacks time tokens). */
function paymentDateTime($dt): string
{
    if ($dt === null || $dt === '') {
        return '-';
    }
    $ts = strtotime((string) $dt);
    if ($ts === false) {
        return '-';
    }
    return jdate('j F Y', $ts) . ' - ' . date('H:i', $ts);
}

/** Serialize a transaction row for the admin list. */
function paymentTransactionToArray(PaymentTransaction $t, array $formNames): array
{
    return [
        'id' => $t->id,
        'number' => $t->number,
        'form_id' => $t->formId,
        'form_name' => $formNames[$t->formId] ?? '—',
        'amount' => $t->amount,
        'amount_display' => number_format($t->amount) . ' ریال',
        'status' => $t->status,
        'status_label' => PaymentStateMachine::label($t->status),
        'customer_name' => $t->customerName,
        'customer_mobile' => $t->customerMobile,
        'authority' => $t->authority,
        'ref_id' => $t->refId,
        'created_at' => $t->createdAt,
        'created_at_display' => paymentDateTime($t->createdAt),
    ];
}

/* ---- DASHBOARD ---- */
if ($action === 'dashboard') {
    $stats = $svc->transactions()->dashboardStats();

    $forms = (new PaymentFormRepository($mysqli))->all();
    $formOptions = [];
    foreach ($forms as $f) {
        $formOptions[] = ['id' => $f->id, 'title' => $f->title];
    }
    $formNames = [];
    foreach ($forms as $f) {
        $formNames[$f->id] = $f->title;
    }

    $recent = $svc->transactions()->search(['page' => 1, 'per_page' => 10, 'sort' => 'date', 'dir' => 'desc']);
    $recentItems = [];
    foreach ($recent['items'] as $tx) {
        $recentItems[] = paymentTransactionToArray($tx, $formNames);
    }

    respond_json(true, '', [
        'stats' => $stats,
        'forms' => $formOptions,
        'recent' => $recentItems,
    ]);
}

/* ---- SEARCH / LIST (paginated) ---- */
if ($action === 'list') {
    $opts = [
        'page' => (int) ($_GET['page'] ?? 1),
        'per_page' => (int) ($_GET['per_page'] ?? 20),
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
        'form_id' => (int) ($_GET['form_id'] ?? 0),
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'amount_min' => (int) ($_GET['amount_min'] ?? 0),
        'amount_max' => (int) ($_GET['amount_max'] ?? 0),
        'mobile' => $_GET['mobile'] ?? '',
        'reference' => $_GET['reference'] ?? '',
        'sort' => $_GET['sort'] ?? 'date',
        'dir' => $_GET['dir'] ?? 'desc',
    ];
    foreach (['search', 'status', 'date_from', 'date_to', 'mobile', 'reference', 'sort', 'dir'] as $k) {
        $opts[$k] = trim((string) $opts[$k]);
    }

    $result = $svc->transactions()->search($opts);
    $formNames = [];
    $res = $mysqli->query('SELECT id, title FROM payment_forms');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $formNames[(int) $row['id']] = (string) $row['title'];
        }
        $res->free();
    }

    $items = [];
    foreach ($result['items'] as $tx) {
        $items[] = paymentTransactionToArray($tx, $formNames);
    }

    respond_json(true, '', [
        'items' => $items,
        'total' => $result['total'],
        'page' => $result['page'],
        'perPage' => $result['perPage'],
        'totalPages' => $result['totalPages'],
    ]);
}

/* ---- DETAIL (single) ---- */
if ($action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respond_error('شناسه تراکنش نامعتبر است.');
    }
    $tx = $svc->transactions()->find($id);
    if ($tx === null) {
        respond_error('تراکنش یافت نشد.', 404);
    }
    $formNames = [];
    $res = $mysqli->query('SELECT id, title FROM payment_forms');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $formNames[(int) $row['id']] = (string) $row['title'];
        }
        $res->free();
    }
    respond_json(true, '', paymentTransactionDetail($tx, $formNames));
}

respond_error('عملیات نامعتبر است.');

/** Full transaction detail for the details modal. */
function paymentTransactionDetail(PaymentTransaction $t, array $formNames): array
{
    return [
        'id' => $t->id,
        'number' => $t->number,
        'form_id' => $t->formId,
        'form_name' => $formNames[$t->formId] ?? '—',
        'amount' => $t->amount,
        'amount_display' => number_format($t->amount) . ' ریال',
        'currency' => $t->currency,
        'status' => $t->status,
        'status_label' => PaymentStateMachine::label($t->status),
        'gateway' => $t->gateway,
        'authority' => $t->authority,
        'ref_id' => $t->refId,
        'failure_reason' => $t->failureReason,
        'customer_name' => $t->customerName,
        'customer_mobile' => $t->customerMobile,
        'customer_email' => $t->customerEmail,
        'submitted_data' => $t->submittedData,
        'ip_address' => $t->ipAddress,
        'user_agent' => $t->userAgent,
        'created_at' => $t->createdAt,
        'callback_at' => $t->callbackAt,
        'verified_at' => $t->verifiedAt,
        'created_at_display' => paymentDateTime($t->createdAt),
        'callback_at_display' => paymentDateTime($t->callbackAt),
        'verified_at_display' => paymentDateTime($t->verifiedAt),
    ];
}