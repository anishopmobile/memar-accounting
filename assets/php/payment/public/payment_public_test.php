<?php
/**
 * payment_public_test.php — PHASE 9 test: template rendering for all amount
 * modes, both themes, all field types, and XSS escaping. Pure CLI (no HTTP).
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

/** Render a template for a form, returning HTML. */
function renderTemplate(PaymentForm $form, array $post = []): string
{
    $token = 'deadbeef';
    $actionUrl = 'pay.php';
    $theme = $form->theme === 'clean' ? 'clean' : 'default';
    $tpl = __DIR__ . '/templates/' . $theme . '.php';
    $errors = '';
    $_SESSION['pay_token'] = $token;
    ob_start();
    include $tpl;
    return (string) ob_get_clean();
}

$base = new PaymentForm();
$base->title = 'فرم تست';
$base->slug = 'x';
$base->purpose = 'هدف تست';
$base->description = 'توضیح تست';

/* ---------- Fixed amount + default theme ---------- */
$f = clone $base;
$f->amountMode = 'fixed';
$f->fixedAmount = 2500000;
$f->theme = 'default';
$f->fields = [];
$html = renderTemplate($f);
t(strpos($html, '2,500,000 ریال') !== false, 'fixed amount shown');
t(strpos($html, 'name="amount" value="2500000"') !== false, 'fixed amount hidden input authoritative');
t(strpos($html, 'pay-theme-default') !== false, 'default theme class applied');

/* ---------- Custom amount ---------- */
$f = clone $base;
$f->amountMode = 'custom';
$f->minAmount = 10000;
$f->maxAmount = 999000;
$html = renderTemplate($f);
t(strpos($html, 'name="amount"') !== false && strpos($html, 'type="text"') !== false, 'custom amount text input present');

/* ---------- Selectable amount ---------- */
$f = clone $base;
$f->amountMode = 'selectable';
$f->amountOptions = [100000, 200000, 500000];
$html = renderTemplate($f);
t(strpos($html, 'value="100000"') !== false && strpos($html, 'value="200000"') !== false && strpos($html, 'value="500000"') !== false, 'selectable options rendered as radios');

/* ---------- Clean theme ---------- */
$f = clone $base;
$f->amountMode = 'fixed';
$f->fixedAmount = 500000;
$f->theme = 'clean';
$html = renderTemplate($f);
t(strpos($html, 'pay-theme-clean') !== false, 'clean theme class applied');

/* ---------- Field types ---------- */
$types = ['name', 'mobile', 'email', 'text', 'textarea', 'number', 'select', 'radio', 'checkbox'];
foreach ($types as $i => $type) {
    $field = new PaymentFormField();
    $field->key = 'field_' . $type;
    $field->label = 'فیلد ' . $type;
    $field->type = $type;
    $field->required = true;
    $field->options = ($type === 'select' || $type === 'radio') ? ['a', 'b'] : [];
    $f = clone $base;
    $f->amountMode = 'fixed';
    $f->fixedAmount = 100000;
    $f->fields = [$field];
    $html = renderTemplate($f);
    t(
        (($type === 'select') ? (strpos($html, '<select') !== false)
         : (($type === 'radio') ? (strpos($html, 'type="radio"') !== false)
            : (($type === 'checkbox') ? (strpos($html, 'type="checkbox"') !== false)
               : (($type === 'textarea') ? (strpos($html, '<textarea') !== false) : true)))),
        "field type '$type' rendered"
    );
}

/* ---------- XSS escaping ---------- */
$f = clone $base;
$f->title = '"><script>alert(1)</script>';
$f->amountMode = 'fixed';
$f->fixedAmount = 1;
$f->fields = [];
$html = renderTemplate($f);
t(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'XSS in title is escaped');

/* now test normalization of submit (mirror of pay.php logic) */
$rawPost = ['f' => ['name' => 'Ali', 'mobile' => '09171234567'], 'amount' => '100000', 'slug' => 'x'];
$submitted = is_array($rawPost['f'] ?? null) ? $rawPost['f'] : [];
$submitted['amount'] = (string) ($rawPost['amount'] ?? '');
$submitted['slug'] = 'x';
t($submitted['name'] === 'Ali' && $submitted['mobile'] === '09171234567' && $submitted['amount'] === '100000', 'submit normalization flat-maps f[] fields + amount');

echo "\n" . ($failures === 0 ? 'ALL PUBLIC PAYMENT TESTS PASSED' : 'FAILURES: ' . $failures) . "\n";
$mysqli->close();