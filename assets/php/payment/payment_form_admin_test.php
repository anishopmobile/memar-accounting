<?php
/**
 * payment_form_admin_test.php — End-to-end test of form management logic:
 * parser mapping, slug uniqueness, fixed/custom/selectable amounts, dynamic
 * fields persistence, toggle, duplicate, and safe delete.
 *
 * Run: php assets/php/payment/payment_form_admin_test.php
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

$repo = (new PaymentService($mysqli))->forms();
$suffix = date('YmdHis');

/* ---------- 1. FIXED amount ---------- */
$fixed = [
    'title' => 'شارژ کیف پول ' . $suffix,
    'amount_mode' => 'fixed',
    'fixed_amount' => '5,000,000',
    'purpose' => 'شارژ کیف پول',
    'is_active' => '1',
    'theme' => 'default',
    'field_key' => ['fullname', 'mobile'],
    'field_label' => ['نام', 'شماره موبایل'],
    'field_type' => ['name', 'mobile'],
    'field_required' => ['1', '1'],
    'field_options' => ['', ''],
];
$form = PaymentFormParser::buildFromPost($fixed);
$fields = PaymentFormParser::buildFieldsFromPost($fixed);
t($form->amountMode === 'fixed', 'fixed mode parsed');
t($form->fixedAmount === 5000000, 'fixed amount normalized to int (5,000,000)');
t($form->slug === PaymentFormParser::slugify($fixed['title']), 'slug auto-generated from title');
t(count($fields) === 2 && $fields[0]->type === 'name' && $fields[1]->type === 'mobile', 'dynamic fields built');
$fixedId = $repo->save($form, $fields);

$loaded = $repo->find($fixedId);
t($loaded !== null && $loaded->authoritativeAmount() === 5000000, 'fixed form persisted + reloaded');
t(count($loaded->fields) === 2 && $loaded->fields[1]->required === true, 'fields persisted with required flags');

$amountResult = PaymentValidator::resolveAmount($loaded, '999999');
t($amountResult['ok'] && $amountResult['amount'] === 5000000, 'fixed form uses authoritative server amount');

/* ---------- 2. Custom (min/max) ---------- */
$custom = PaymentFormParser::buildFromPost([
    'title' => 'Donate ' . $suffix,
    'amount_mode' => 'custom',
    'min_amount' => '100000',
    'max_amount' => '10,000,000',
]);
$customId = $repo->save($custom);
$cLoaded = $repo->find($customId);
t($cLoaded->isCustom() && $cLoaded->minAmount === 100000 && $cLoaded->maxAmount === 10000000, 'custom min/max persisted');

$ok = PaymentValidator::resolveAmount($cLoaded, '500000');
$low = PaymentValidator::resolveAmount($cLoaded, '50000');
$high = PaymentValidator::resolveAmount($cLoaded, '20000000');
$bad = PaymentValidator::resolveAmount($cLoaded, 'abc');
t($ok['ok'] && $ok['amount'] === 500000, 'custom amount within range accepted');
t(!$low['ok'], 'custom amount below min rejected');
t(!$high['ok'], 'custom amount above max rejected');
t(!$bad['ok'], 'non-numeric custom amount rejected');

/* ---------- 3. Selectable ---------- */
$selectable = PaymentFormParser::buildFromPost(['title' => 'پلن ' . $suffix, 'amount_mode' => 'selectable']);
$selId = $repo->save($selectable, [], [100000, 200000, 500000, 100000]);
$sLoaded = $repo->find($selId);
t($sLoaded->isSelectable() && $sLoaded->amountOptions === [100000, 200000, 500000], 'selectable options deduped + sorted');
$okSel = PaymentValidator::resolveAmount($sLoaded, '200000');
$noSel = PaymentValidator::resolveAmount($sLoaded, '300000');
t($okSel['ok'] && $okSel['amount'] === 200000, 'selectable value in list accepted');
t(!$noSel['ok'], 'selectable value outside list rejected');

/* ---------- 4. Slug uniqueness ---------- */
t($repo->slugExists($form->slug) === true, 'slug uniqueness detects existing');
t($repo->slugExists('definitely-does-not-exist-xyz-' . $suffix) === false, 'slug uniqueness false for new slug');

/* ---------- 5. Duplicate + Toggle + Delete ---------- */
$dup = clone $loaded;
$dup->id = 0;
$dup->slug = 'dup-' . $suffix;
$dupId = $repo->save($dup, $loaded->fields, $loaded->amountOptions);
$dupLoaded = $repo->find($dupId);
t($dupLoaded !== null && $dupLoaded->fields !== [], 'duplicate created with fields');

$stmt = $mysqli->prepare('UPDATE payment_forms SET is_active = 1 - is_active WHERE id = ?');
$stmt->bind_param('i', $fixedId);
$stmt->execute();
$stmt->close();
$afterToggle = $repo->find($fixedId);
t($afterToggle->isActive === false, 'toggle flips is_active');
$stmt = $mysqli->prepare('UPDATE payment_forms SET is_active = 1 - is_active WHERE id = ?');
$stmt->bind_param('i', $fixedId);
$stmt->execute();
$stmt->close();
t($repo->find($fixedId)->isActive === true, 'toggle restores is_active');

t($repo->slugExists('dup-' . $suffix) === true, 'slugs unique from duplicate');
t($repo->delete($customId) === true, 'form without transactions can be deleted');
t($repo->find($customId) === null, 'deleted form no longer found');

/* ---------- 6. Cleanup test fixtures ---------- */
$repo->delete($fixedId);
$repo->delete($selId);
$repo->delete($dupId);

echo "\n" . ($failures === 0 ? 'ALL FORM MANAGEMENT TESTS PASSED' : 'FAILURES: ' . $failures) . "\n";
$mysqli->close();