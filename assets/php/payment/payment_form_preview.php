<?php
/**
 * payment_form_preview.php — Renders a read-only preview (HTML fragment) of a
 * payment form for administrators. Auth-guarded via boot.php (admin only).
 *
 * The fragment is escaped and deliberately does not query submitted data; it
 * shows the public-style summary so the admin can verify the form before use.
 */
declare(strict_types=1);

require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/payment_loader.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="empty-state">فرم نامعتبر است.</div>';
    exit;
}

$svc = new PaymentService($mysqli);
$form = $svc->forms()->find($id);
if ($form === null) {
    echo '<div class="empty-state">فرم پرداخت یافت نشد.</div>';
    exit;
}

$e = static function ($v): string {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};

$amountSuffix = ' ریال';
?>
<div class="card" style="min-width: 420px;">
    <div class="page-actions">
        <h2 style="margin:0;"><?php echo $e($form->title); ?></h2>
        <span class="badge<?php echo $form->isActive ? '' : ' badge-danger'; ?>"><?php echo $form->isActive ? 'فعال' : 'غیرفعال'; ?></span>
    </div>
    <p class="setting-note">آدرس عمومی: <span dir="ltr"><?php echo $e('pay.php?slug=' . $form->slug); ?></span></p>
    <?php if ($form->description !== null && $form->description !== ''): ?>
        <p><?php echo nl2br($e($form->description)); ?></p>
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-row">
            <label>نوع مبلغ</label>
            <div>
                <?php
                $modeLabels = ['fixed' => 'مبلغ ثابت', 'custom' => 'مبلغ دلخواه', 'selectable' => 'مبلغ انتخابی'];
                echo $e($modeLabels[$form->amountMode] ?? $form->amountMode);
                ?>
            </div>
        </div>
        <div class="form-row">
            <label>مبلغ</label>
            <div>
                <?php if ($form->isFixed()): ?>
                    <?php echo number_format($form->fixedAmount) . $amountSuffix; ?>
                <?php elseif ($form->isCustom()): ?>
                    <?php echo ($form->minAmount ? number_format($form->minAmount) : 0) . ' تا ' . ($form->maxAmount ? number_format($form->maxAmount) : 'نامحدود') . $amountSuffix; ?>
                <?php else: ?>
                    <?php foreach ($form->amountOptions as $a): ?><span dir="ltr" style="margin-inline-end:8px;"><?php echo number_format($a); ?></span><?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($form->purpose !== null && $form->purpose !== ''): ?>
        <div class="form-row">
            <label>موضوع پرداخت</label>
            <div><?php echo $e($form->purpose); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($form->expiresAt !== null && $form->expiresAt !== ''): ?>
        <div class="form-row">
            <label>انقضا</label>
            <div><?php echo $e($form->expiresAt); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <h3>فیلدهای فرم</h3>
    <?php if ($form->fields === []): ?>
        <p class="setting-note">بدون فیلد اضافه.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($form->fields as $field): ?>
                <li>
                    <?php echo $e($field->label); ?>
                    <?php if ($field->required): ?><span class="text-danger">*</span><?php endif; ?>
                    <span class="setting-note">(<?php echo $e($field->type); ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($form->successMessage !== null && $form->successMessage !== ''): ?>
        <div class="card panel" style="margin-top:12px;">
            <strong>پیام موفقیت:</strong>
            <p><?php echo nl2br($e($form->successMessage)); ?></p>
        </div>
    <?php endif; ?>
</div>
