<?php
require_once __DIR__ . '/../assets/php/boot.php';
$kind = (string)($_GET['party_kind'] ?? 'customer');
if (!in_array($kind,['customer','supplier'],true)) $kind='customer';
$party = max(0,(int)($_GET['party_id']??0));
?>
<div class="card" id="accountingApp" data-kind="<?php echo htmlspecialchars($kind,ENT_QUOTES,'UTF-8'); ?>" data-party="<?php echo $party; ?>">
 <div class="page-actions"><h2>صورت‌حساب <?php echo $kind==='customer'?'مشتری':'تأمین‌کننده'; ?></h2>
 <a href="?page=<?php echo $kind==='customer'?'customers':'suppliers'; ?>" class="button-secondary">بازگشت</a></div>
 <div id="acBalance" class="card">در حال دریافت اطلاعات...</div>
 <div class="card"><h3>ثبت دریافت / پرداخت</h3>
  <div class="form-grid">
   <div class="form-row"><label>جهت تراکنش</label><select id="acDirection"><option value="<?php echo $kind==='customer'?'in':'out'; ?>"><?php echo $kind==='customer'?'دریافت از مشتری':'پرداخت به تأمین‌کننده'; ?></option><option value="<?php echo $kind==='customer'?'out':'in'; ?>"><?php echo $kind==='customer'?'استرداد به مشتری':'دریافت وجه از تأمین‌کننده'; ?></option></select></div>
   <div class="form-row"><label>مبلغ (<span class="acCurrency"></span>)</label><input id="acAmount" type="number" min="1" step="1"></div>
   <div class="form-row"><label>روش</label><select id="acMethod"><option value="cash">نقدی</option><option value="pos">کارت‌خوان</option><option value="bank_transfer">انتقال بانکی</option></select></div>
   <div class="form-row"><label>تاریخ</label><input id="acDate" type="date" value="<?php echo date('Y-m-d'); ?>"></div>
   <div class="form-row"><label>حساب/صندوق</label><input id="acAccount" maxlength="120"></div>
   <div class="form-row"><label>شماره پیگیری</label><input id="acReference" maxlength="120"></div>
   <div class="form-row"><label>توضیحات</label><input id="acNote" maxlength="1000"></div>
  </div>
  <h4>تخصیص اختیاری به فاکتورهای باز</h4><div id="acAllocationList"></div>
  <div class="form-actions"><button class="button" type="button" id="acSubmit">ثبت تراکنش</button></div>
 </div>
 <div class="card"><h3>فاکتورها و مانده‌ها</h3><div class="table-wrapper"><table class="action-table"><thead><tr><th>فاکتور</th><th>تاریخ</th><th>مبلغ کل</th><th>مانده</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="acInvoices"></tbody></table></div></div>
 <div class="card"><h3>تاریخچه دریافت و پرداخت</h3><div class="table-wrapper"><table class="action-table"><thead><tr><th>تاریخ</th><th>نوع</th><th>مبلغ</th><th>تخصیص</th><th>اعتبار آزاد</th><th>روش</th><th>عملیات</th></tr></thead><tbody id="acReceipts"></tbody></table></div></div>
 <div class="card"><h3>مانده افتتاحیه</h3><p>مانده مثبت: بدهی طرف حساب به فروشگاه در مورد مشتری، یا بدهی فروشگاه به تأمین‌کننده. مانده منفی: اعتبار طرف حساب.</p>
 <div class="form-grid"><div class="form-row"><label>مانده (<span class="acCurrency"></span>)</label><input type="number" id="acOpening"></div><div class="form-row"><label>توضیح</label><input id="acOpeningNote"></div></div>
 <button type="button" class="button-secondary" id="acOpeningSubmit">ثبت مانده افتتاحیه (یک‌بار)</button></div>
</div>
