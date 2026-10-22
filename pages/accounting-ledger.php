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
    <h3>صورت‌حساب و گردش مالی به‌ترتیب تاریخ</h3>
    <p id="ledgerStatementWarning" class="setting-note" hidden>به دلیل تعداد زیاد اسناد، فقط ۵۰۰ گردش آخر نمایش داده می‌شود؛ مانده‌ها همچنان از کل اسناد محاسبه شده‌اند.</p>
    <div class="table-wrapper"><table class="action-table"><thead><tr><th>تاریخ</th><th>نوع</th><th>شرح</th><th>افزایش بدهی</th><th>کاهش بدهی</th><th>مانده پس از رویداد</th></tr></thead>
      <tbody id="ledgerStatement"><tr><td colspan="6">طرف حساب را انتخاب کنید.</td></tr></tbody></table></div>
    <h3>فاکتورهای این طرف حساب</h3>
    <div class="table-wrapper"><table class="action-table"><thead><tr><th>شماره فاکتور</th><th>مبلغ کل</th><th>تسویه‌شده</th><th>مانده</th><th>تخصیص از پرداخت جدید</th></tr></thead><tbody id="ledgerInvoices"></tbody></table></div>
    <h3>ثبت دریافت / پرداخت</h3>
    <form id="ledgerPaymentForm">
      <div class="form-grid">
        <div class="form-row"><label>مبلغ واقعی دریافت / پرداخت</label><input required type="number" min="0.01" step="0.01" id="ledgerAmount"></div>
        <div class="form-row"><label>نوع عملیات مالی</label><select id="ledgerDirection"><option value="normal">دریافت / پرداخت عادی</option><option value="reverse">استرداد وجه / برگشت بانکی واقعی</option></select></div>
        <div class="form-row"><label>روش</label><select id="ledgerMethod"><option value="cash">نقدی</option><option value="pos">کارت‌خوان</option><option value="bank_transfer">انتقال بانکی</option></select></div>
        <div class="form-row"><label>تاریخ پرداخت (میلادی؛ مطابق تاریخ سند ذخیره می‌شود)</label><input required type="date" id="ledgerDate"></div>
        <div class="form-row"><label>شماره پیگیری (اختیاری)</label><input maxlength="120" id="ledgerReference"></div>
        <div class="form-row"><label>توضیح</label><input maxlength="1500" id="ledgerNote"></div>
      </div>
      <p id="ledgerAllocationSummary" aria-live="polite"></p>
      <button type="submit" class="button" id="ledgerSubmit">ثبت نهایی تراکنش</button>
    </form>
    <h3>تخصیص اعتبار واریز قبلی</h3>
    <p class="setting-note">وقتی مبلغی قبلاً دریافت یا پرداخت شده و هنوز به فاکتوری تخصیص نیافته، می‌توان بدون جابجایی دوباره پول آن را به یکی از فاکتورها تخصیص داد.</p>
    <form id="ledgerCreditForm">
      <div class="form-grid">
        <div class="form-row"><label>تراکنش دارای اعتبار</label><select required id="ledgerCreditPayment"></select></div>
        <div class="form-row"><label>فاکتور تسویه‌نشده</label><select required id="ledgerCreditInvoice"></select></div>
        <div class="form-row"><label>مبلغ تخصیص</label><input required id="ledgerCreditAmount" type="number" step="0.01" min="0.01"></div>
      </div>
      <button class="button-secondary" type="submit">اعمال اعتبار بدون واریز مجدد</button>
    </form>
    <h3>ثبت مانده افتتاحیه</h3>
    <p class="setting-note">برای بدهی یا اعتبار مربوط به قبل از ثبت اطلاعات در نرم‌افزار است. مثبت یعنی بدهی طرف حساب / بدهی فروشگاه به تأمین‌کننده؛ منفی یعنی بستانکاری. فقط یک بار قابل ثبت است.</p>
    <form id="ledgerOpeningForm">
      <div class="form-grid">
        <div class="form-row"><label>مانده اولیه (+ بدهی، − اعتبار)</label><input type="number" step="0.01" id="ledgerOpeningAmount" required></div>
        <div class="form-row"><label>تاریخ میلادی</label><input type="date" id="ledgerOpeningDate" required></div>
        <div class="form-row"><label>مستندات / علت</label><input type="text" id="ledgerOpeningNote" maxlength="2000" minlength="8" required></div>
      </div>
      <button class="button-secondary" type="submit">ثبت مانده افتتاحیه (غیرقابل تغییر)</button>
    </form>
    <h3>اصلاح پرداخت جزئی فاکتورهای تاریخی</h3>
    <p class="setting-note">این اصلاح یک رویداد واقعی دریافت وجه نیست؛ صرفاً مقدار تسویه‌شده فاکتور قدیمی را مستند می‌کند.</p>
    <form id="ledgerReconcileForm">
      <div class="form-grid">
        <div class="form-row"><label>فاکتور با مبلغ نامشخص</label><select required id="ledgerReconcileInvoice"></select></div>
        <div class="form-row"><label>مبلغ پرداخت‌شده واقعی در گذشته</label><input type="number" step="0.01" min="0" id="ledgerReconcileAmount" required></div>
        <div class="form-row"><label>سند و علت تعیین مبلغ تاریخی</label><input type="text" minlength="8" maxlength="2000" id="ledgerReconcileExplanation" required></div>
      </div>
      <button class="button-secondary" type="submit">ثبت اصلاح تاریخی یک‌باره</button>
    </form>
    <h3>تاریخچه دریافت / پرداخت</h3>
    <div class="table-wrapper"><table class="action-table"><thead><tr><th>تاریخ</th><th>روش</th><th>مبلغ</th><th>شماره پیگیری</th><th>توضیح</th></tr></thead><tbody id="ledgerPayments"></tbody></table></div>
  </div>
</div>
