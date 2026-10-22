<?php
/** Party receivable/payable account statement. All reads/changes via authenticated accounting API. */
$kind = (string)($_GET['kind'] ?? '');
$partyId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!in_array($kind,['customer','supplier'],true) || !$partyId):
?>
<div class="card"><p class="empty-state">صورت‌حساب طرف حساب معتبر نیست.</p></div>
<?php else: ?>
<div class="card" id="accountStatement" data-kind="<?php echo htmlspecialchars($kind,ENT_QUOTES,'UTF-8'); ?>"
     data-party-id="<?php echo (int)$partyId; ?>">
    <div class="page-actions">
        <h2>صورت‌حساب <?php echo $kind==='customer'?'مشتری':'تأمین‌کننده'; ?></h2>
        <div class="toolbar-actions">
            <a class="button-secondary" href="?page=<?php echo $kind==='customer'?'customers':'suppliers'; ?>">بازگشت</a>
            <button class="button" type="button" id="acctNewPayment"><?php echo $kind==='customer'?'ثبت دریافت':'ثبت پرداخت'; ?></button>
            <button class="button-secondary" type="button" id="acctOpening">ثبت مانده افتتاحیه</button>
            <button class="button-secondary" type="button" id="acctRefund"><?php echo $kind==='customer'?'استرداد به مشتری':'دریافت بازپرداخت'; ?></button>
            <button class="button-secondary" type="button" id="acctRefresh">به‌روزرسانی</button>
        </div>
    </div>
    <h3 id="acctPartyName">در حال بارگذاری...</h3>
    <div class="metrics-grid" style="margin-bottom:1.25rem">
        <div class="metric-card-new"><h4>جمع فاکتورها</h4><strong id="acctInvoiced">—</strong></div>
        <div class="metric-card-new"><h4>بدهی باقی‌مانده</h4><strong id="acctDebt">—</strong></div>
        <div class="metric-card-new"><h4>اعتبار طرف حساب</h4><strong id="acctCredit">—</strong></div>
    </div>
    <div id="acctLegacyWarning" class="alert alert-warning" style="display:none"></div>
    <h3>گردش مالی</h3>
    <div class="table-wrapper">
        <table class="action-table">
            <thead><tr><th>تاریخ</th><th>شرح</th><th>افزایش بدهی</th><th>کاهش بدهی</th><th>مانده</th><th>عملیات</th></tr></thead>
            <tbody id="acctStatementRows"><tr><td colspan="6">در حال بارگذاری...</td></tr></tbody>
        </table>
    </div>
    <p class="setting-note">مبالغ به واحد انتخاب‌شده نمایش داده می‌شوند؛ تراکنش‌های ابطال‌شده باقی می‌مانند اما مانده را تغییر نمی‌دهند.</p>
</div>
<?php endif; ?>
