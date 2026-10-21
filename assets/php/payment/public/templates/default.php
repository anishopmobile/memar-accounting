<?php
/**
 * default.php — Default payment form template (Persian RTL).
 * Receives: $form (PaymentForm), $token (public CSRF), $errors (string),
 *           $actionUrl (string), and POST for repopulation.
 * All HTML escaped; amounts are integers rendered for display only.
 */
declare(strict_types=1);
/** @var PaymentForm $form */
$ptpl = static function ($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$amountTpl = static function (int $n): string { return number_format($n) . ' ریال'; };
?>
<div class="pay-card pay-theme-default">
    <div class="pay-head">
        <h1 class="pay-title"><?php echo $ptpl($form->title); ?></h1>
        <?php if ($form->purpose !== null && $form->purpose !== ''): ?>
            <p class="pay-purpose"><?php echo $ptpl($form->purpose); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($form->description !== null && $form->description !== ''): ?>
        <p class="pay-desc"><?php echo nl2br($ptpl($form->description)); ?></p>
    <?php endif; ?>

    <form method="post" action="<?php echo $ptpl($actionUrl); ?>" class="pay-form" novalidate>
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="slug" value="<?php echo $ptpl($form->slug); ?>">
        <input type="hidden" name="token" value="<?php echo $ptpl($token); ?>">

        <?php if ($errors !== ''): ?>
            <div class="pay-alert pay-alert-error" role="alert"><?php echo $ptpl($errors); ?></div>
        <?php endif; ?>

        <?php $f = 0; ?>
        <?php foreach ($form->fields as $field): $f++; ?>
            <?php
            $name = 'f[' . $field->key . ']';
            $req = $field->required ? 'required' : '';
            $placeholder = $field->placeholder !== null ? $ptpl($field->placeholder) : '';
            $cur = is_array($_POST[$field->key] ?? '') ? '' : (string) ($_POST[$field->key] ?? ($field->defaultValue ?? ''));
            ?>
            <div class="pay-field" data-field-key="<?php echo $ptpl($field->key); ?>">
                <label class="pay-label" for="payf<?php echo $f; ?>">
                    <?php echo $ptpl($field->label); ?><?php echo $field->required ? ' <span class="pay-required">*</span>' : ''; ?>
                </label>
                <?php if (in_array($field->type, ['name', 'mobile', 'email', 'text'], true)): ?>
                    <input type="text" id="payf<?php echo $f; ?>" name="<?php echo $ptpl($name); ?>" class="pay-input" value="<?php echo $ptpl($cur); ?>" placeholder="<?php echo $placeholder; ?>" <?php echo $req; ?>>
                <?php elseif ($field->type === 'textarea' || $field->type === 'description'): ?>
                    <textarea id="payf<?php echo $f; ?>" name="<?php echo $ptpl($name); ?>" class="pay-input pay-textarea" rows="3" placeholder="<?php echo $placeholder; ?>" <?php echo $req; ?>><?php echo $ptpl($cur); ?></textarea>
                <?php elseif ($field->type === 'number'): ?>
                    <input type="number" id="payf<?php echo $f; ?>" name="<?php echo $ptpl($name); ?>" class="pay-input" value="<?php echo $ptpl($cur); ?>" inputmode="numeric" <?php echo $req; ?>>
                <?php elseif ($field->type === 'radio'): ?>
                    <?php foreach ($field->options as $opt): ?>
                        <label class="pay-option">
                            <input type="radio" name="<?php echo $ptpl($name); ?>" value="<?php echo $ptpl($opt); ?>" <?php echo $req; ?>>&nbsp;<?php echo $ptpl($opt); ?>
                        </label>
                    <?php endforeach; ?>
                <?php elseif ($field->type === 'select'): ?>
                    <select name="<?php echo $ptpl($name); ?>" class="pay-input" <?php echo $req; ?>>
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($field->options as $opt): ?>
                            <option value="<?php echo $ptpl($opt); ?>"><?php echo $ptpl($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field->type === 'checkbox'): ?>
                    <label class="pay-option">
                        <input type="checkbox" name="<?php echo $ptpl($name); ?>" value="1" <?php echo $req; ?>>&nbsp;<?php echo $ptpl($field->label); ?>
                    </label>
                <?php else: ?>
                    <input type="text" id="payf<?php echo $f; ?>" name="<?php echo $ptpl($name); ?>" class="pay-input" placeholder="<?php echo $placeholder; ?>" <?php echo $req; ?>>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($form->isCustom()): ?>
            <div class="pay-field">
                <label class="pay-label" for="payAmount">مبلغ پرداخت (ریال)</label>
                <input type="text" id="payAmount" name="amount" class="pay-input" inputmode="numeric" placeholder="مبلغ دلخواه" required>
            </div>
        <?php elseif ($form->isSelectable()): ?>
            <div class="pay-field">
                <label class="pay-label">انتخاب مبلغ</label>
                <div class="pay-amounts">
                    <?php foreach ($form->amountOptions as $a): ?>
                        <label class="pay-amount-option">
                            <input type="radio" name="amount" value="<?php echo (int) $a; ?>" required>
                            <span><?php echo $amountTpl((int) $a); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="pay-field pay-fixed-amount">
                <span class="pay-fixed-label">مبلغ قابل پرداخت</span>
                <span class="pay-fixed-value"><?php echo $amountTpl((int) $form->authoritativeAmount()); ?></span>
                <input type="hidden" name="amount" value="<?php echo (int) $form->authoritativeAmount(); ?>">
            </div>
        <?php endif; ?>

        <div class="pay-submitrow">
            <button type="submit" class="pay-submit">پرداخت امن</button>
        </div>
    </form>
</div>