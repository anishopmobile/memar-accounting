<?php require_once __DIR__.'/../assets/php/boot.php'; ?>
<div class="card" id="ledgerApp" data-csrf="<?php echo htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8'); ?>">
  <h2>دریافت‌ها و پرداخت‌های حسابداری</h2>
  <p class="setting-note">این بخش مستقل از درگاه پرداخت آنلاین است. پرداخت‌های قبلی بدون ثبت رویداد نقدی جدید، در مانده تاریخی لحاظ می‌شوند.</p>
  <div class="form-grid">
    <div class="form-row"><label for="ledgerKind">نوع طرف حساب</label>
      <select id="ledgerKind"><option value="customer">مشتری (دریافت وجه)</option><option value="supplier">تأمین‌کننده (پرداخت وجه)</option></select></div>
    <div class="form-row"><label for="ledgerPartySearch">جستجوی طرف حساب</label><input type="search" id="ledgerPartySearch" placeholder="نام یا شماره تماس"></div>
    <div class="form-row"><label for="ledgerParty">انتخاب طرف حساب</label><select id="ledgerParty"><option value="">ابتدا جستجو کنید</option></select></div>
  </div>
  <div id="ledgerAccount" hidden>
    <h3 id="ledgerPartyName"></h3>
    <div class="form-grid">
      <div class="form-row"><label>کل فاکتورهای قطعی</label><div id="ledgerTotal" class="money-cell">—</div></div>
      <div class="form-row"><label>مانده بدهی</label><div id="ledgerDebt" class="money-cell">—</div></div>
      <div class="form-row"><label>اعتبار نزد طرف حساب</label><div id="ledgerCredit" class="money-cell">—</div></div>
    </div>
    <h3>فاکتورهای این طرف حساب</h3>
    <div class="table-wrapper"><table class="action-table"><thead><tr><th>شماره فاکتور</th><th>مبلغ کل</th><th>تسویه‌شده</th><th>مانده</th><th>تخصیص از پرداخت جدید</th></tr></thead><tbody id="ledgerInvoices"></tbody></table></div>
    <h3>ثبت دریافت / پرداخت</h3>
    <form id="ledgerPaymentForm">
      <div class="form-grid">
        <div class="form-row"><label>مبلغ واقعی دریافت / پرداخت</label><input required type="number" min="0.01" step="0.01" id="ledgerAmount"></div>
        <div class="form-row"><label>روش</label><select id="ledgerMethod"><option value="cash">نقدی</option><option value="pos">کارت‌خوان</option><option value="bank_transfer">انتقال بانکی</option></select></div>
        <div class="form-row"><label>تاریخ پرداخت (میلادی؛ مطابق تاریخ سند ذخیره می‌شود)</label><input required type="date" id="ledgerDate"></div>
        <div class="form-row"><label>شماره پیگیری (اختیاری)</label><input maxlength="120" id="ledgerReference"></div>
        <div class="form-row"><label>توضیح</label><input maxlength="1500" id="ledgerNote"></div>
      </div>
      <p id="ledgerAllocationSummary" aria-live="polite"></p>
      <button type="submit" class="button" id="ledgerSubmit">ثبت نهایی تراکنش</button>
    </form>
    <h3>تاریخچه دریافت / پرداخت</h3>
    <div class="table-wrapper"><table class="action-table"><thead><tr><th>تاریخ</th><th>روش</th><th>مبلغ</th><th>شماره پیگیری</th><th>توضیح</th></tr></thead><tbody id="ledgerPayments"></tbody></table></div>
  </div>
</div>
