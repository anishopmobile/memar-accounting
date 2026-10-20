<?php
/**
 * payment_form_action.php — Administrator payment-form management endpoints.
 *
 * Uses the existing boot infrastructure:
 *   - require_login() (auth) via boot.php
 *   - require_csrf_or_fail() for all state-changing (POST) actions
 *   - respond_json() / respond_error() for standardized responses
 *
 * GET   action=get&id=      → single form (for the editor)
 * POST  action=create       → create a new form          (CSRF)
 * POST  action=update       → update an existing form    (CSRF)
 * POST  action=duplicate    → duplicate a form           (CSRF)
 * POST  action=toggle       → activate/deactivate        (CSRF)
 * POST  action=delete       → delete where safe          (CSRF)
 */
declare(strict_types=1);

require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/payment_loader.php';

$svc = new PaymentService($mysqli);
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

/** @return array<string,mixed> */
function paymentFormToArray(PaymentForm $f): array
{
    $fields = [];
    foreach ($f->fields as $field) {
        $fields[] = [
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type,
            'required' => $field->required,
            'options' => $field->options,
            'placeholder' => $field->placeholder,
            'default_value' => $field->defaultValue,
            'validation' => $field->validation,
        ];
    }
    return [
        'id' => $f->id,
        'title' => $f->title,
        'slug' => $f->slug,
        'description' => $f->description,
        'amount_mode' => $f->amountMode,
        'fixed_amount' => $f->fixedAmount,
        'min_amount' => $f->minAmount,
        'max_amount' => $f->maxAmount,
        'purpose' => $f->purpose,
        'is_active' => $f->isActive,
        'expires_at' => $f->expiresAt,
        'success_message' => $f->successMessage,
        'redirect_url' => $f->redirectUrl,
        'redirect_delay_seconds' => $f->redirectDelaySeconds,
        'notify_customer' => $f->notifyCustomer,
        'notify_admin' => $f->notifyAdmin,
        'theme' => $f->theme,
        'fields' => $fields,
        'amount_options' => $f->amountOptions,
        'public_url' => 'pay.php?slug=' . rawurlencode($f->slug),
    ];
}

/* ---- GET: single form for the editor ---- */
if ($action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respond_error('شناسه فرم نامعتبر است.');
    }
    $form = $svc->forms()->find($id);
    if ($form === null) {
        respond_error('فرم پرداخت یافت نشد.', 404);
    }
    respond_json(true, '', paymentFormToArray($form));
}

/* ---- All mutating actions require CSRF ---- */
require_csrf_or_fail();

/* ---- CREATE / UPDATE ---- */
if ($action === 'create' || $action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $existing = $id > 0 ? $svc->forms()->find($id) : null;
    if ($id > 0 && $existing === null) {
        respond_error('فرم پرداخت یافت نشد.');
    }

    $form = PaymentFormParser::buildFromPost($_POST, $existing);

    if ($form->title === '') {
        respond_error('عنوان فرم اجباری است.');
    }
    if ($form->slug === '') {
        respond_error('اسلاگ (شناسه) فرم اجباری است.');
    }

    // Ensure a unique slug.
    $excludeId = $existing ? $existing->id : null;
    if ($svc->forms()->slugExists($form->slug, $excludeId)) {
        $base = $form->slug;
        $assigned = false;
        for ($i = 1; $i <= 20; $i++) {
            $candidate = $base . '-' . $i;
            if (!$svc->forms()->slugExists($candidate, $excludeId)) {
                $form->slug = $candidate;
                $assigned = true;
                break;
            }
        }
        if (!$assigned) {
            respond_error('اسلاگ تکراری است و نمی‌توان یک جایگزین ساخت.');
        }
    }

    // Sum validation for the chosen amount mode.
    if ($form->isFixed() && $form->fixedAmount <= 0) {
        respond_error('مبلغ ثابت باید بیشتر از صفر باشد.');
    }
    if ($form->isCustom() && $form->minAmount > 0 && $form->maxAmount > 0 && $form->maxAmount < $form->minAmount) {
        respond_error('بیشترین مبلغ نمی‌تواند کمتر از کمترین مبلغ باشد.');
    }
    $fields = PaymentFormParser::buildFieldsFromPost($_POST);
    $amounts = PaymentFormParser::buildAmountsFromPost($_POST);
    if ($form->isSelectable() && $amounts === []) {
        respond_error('برای فرم انتخابی حداقل یک گزینه مبلغ وارد کنید.');
    }

    $savedId = $svc->forms()->save($form, $fields, $amounts);

    respond_json(true, 'فرم پرداخت ذخیره شد.', [
        'id' => $savedId,
        'slug' => $form->slug,
        'public_url' => 'pay.php?slug=' . rawurlencode($form->slug),
    ]);
}

/* ---- DUPLICATE ---- */
if ($action === 'duplicate') {
    $id = (int) ($_POST['id'] ?? 0);
    $source = $id > 0 ? $svc->forms()->find($id) : null;
    if ($source === null) {
        respond_error('فرم پرداخت یافت نشد.');
    }

    $copy = clone $source;
    $copy->id = 0;
    $copy->title = mb_substr($source->title . ' (کپی)', 0, 255, 'UTF-8');
    $base = PaymentFormParser::slugify($source->title . ' kopi');
    $copy->slug = $base;
    $i = 1;
    while ($svc->forms()->slugExists($copy->slug)) {
        $copy->slug = $base . '-' . $i;
        $i++;
    }

    $newId = $svc->forms()->save($copy, $source->fields, $source->amountOptions);

    respond_json(true, 'فرم با موفقیت کپی شد.', [
        'id' => $newId,
        'slug' => $copy->slug,
        'public_url' => 'pay.php?slug=' . rawurlencode($copy->slug),
    ]);
}

/* ---- TOGGLE ACTIVE ---- */
if ($action === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond_error('شناسه فرم نامعتبر است.');
    }
    $stmt = $mysqli->prepare('UPDATE payment_forms SET is_active = 1 - is_active WHERE id = ?');
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        respond_error('خطا در تغییر وضعیت فرم.');
    }
    respond_json(true, 'وضعیت فرم تغییر کرد.');
}

/* ---- DELETE (safe: blocked by FK if transactions exist) ---- */
if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond_error('شناسه فرم نامعتبر است.');
    }
    $stmt = $mysqli->prepare('SELECT COUNT(*) AS c FROM payment_transactions WHERE form_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row && (int) $row['c'] > 0) {
        respond_error('این فرم دارای تراکنش است و قابل حذف نیست. به‌جای آن آن را غیرفعال کنید.');
    }
    if (!$svc->forms()->delete($id)) {
        respond_error('خطا در حذف فرم.');
    }
    respond_json(true, 'فرم پرداخت حذف شد.');
}

respond_error('عملیات نامعتبر است.');