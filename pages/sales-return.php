<?php require_once __DIR__ . '/../assets/php/boot.php'; ?>

<style>
/* ============================================================
 * استایل اختصاصی صفحه برگشت فروش (اسکوپ‌شده با پیشوند sr-)
 * بدون هیچ تغییر سراسری در style.css
 * ========================================================== */
.sr-items-table th, .sr-items-table td { padding: 6px 8px; vertical-align: middle; }
.sr-returnable-hint { display: block; font-size: 12px; color: var(--muted, #6b7787); }
.sr-qty-input { width: 90px; text-align: center; }
.sr-money-cell { font-weight: 700; white-space: nowrap; }
.sr-detail-grid h3 { margin: 14px 0 6px; font-size: 15px; }
.sr-detail-items th, .sr-detail-items td { padding: 7px 9px; }
.sr-page-buttons { display: inline-flex; gap: 6px; flex-wrap: wrap; }
</style>

<input type="hidden" id="srCsrfToken" value="<?php echo csrf_token(); ?>">

<!-- ============================ لیست سندهای برگشت ============================ -->
<div class="card" id="srListView">
    <div class="page-actions">
        
        <button type="button" class="button" id="srNewReturn">+ برگشت فروش جدید</button>
        <div class="filter-panel">
            <input type="search" id="srSearch" placeholder="جستجو (شماره برگشت / فاکتور / مشتری / تلفن)...">
            <button type="button" class="button-secondary" id="srRefresh">تازه‌سازی</button>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="srTable">
            <thead>
                <tr>
                    <th>شماره برگشت</th>
                    <th>فاکتور اصلی</th>
                    <th>مشتری</th>
                    <th>تاریخ (شمسی)</th>
                    <th>مبلغ برگشتی</th>
                    <th>اقلام</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="7" class="empty-state" style="padding:30px 16px;">در حال بارگذاری...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="sr-page-buttons" id="srPager" style="margin-top:12px;"></div>
</div>

<!-- ============================ فرم ساخت / ویرایش برگشت ============================ -->
<div class="card" id="srFormView" style="display:none;"></div>

<!-- ============================ نمایش جزئیات سند برگشت ============================ -->
<div class="card" id="srDetailView" style="display:none;"></div>

<!-- مودال انتخاب فاکتور فروش قطعی -->
<div class="modal" id="srInvoiceModal">
    <div class="modal-backdrop" data-close="srInvoiceModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>انتخاب فاکتور فروش</h2>
            <button class="modal-close" data-close="srInvoiceModal" type="button">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <input type="search" id="srInvoiceSearch" placeholder="جستجو (شماره/مشتری/تلفن)...">
            </div>
        </div>
        <div class="table-wrapper">
            <table class="action-table" id="srInvoiceTable">
                <thead><tr><th>شماره</th><th>مشتری</th><th>تاریخ (شمسی)</th><th>مبلغ</th><th></th></tr></thead>
                <tbody><tr><td colspan="5">در حال بارگذاری...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال PDF / چاپ سند برگشت -->
<div class="modal" id="srPdfModal">
    <div class="modal-backdrop" data-close="srPdfModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>تولید و چاپ سند برگشت — <span id="srPdfNumber" dir="ltr"></span></h2>
            <button class="modal-close" data-close="srPdfModal" type="button">×</button>
        </div>
        <p class="setting-note">سند PDF به‌صورت سرورمحور و بر اساس اطلاعات ثبت‌شده برگشت تولید می‌شود. برای چاپ، سند PDF در تب جدید باز شده و چاپ از خود سند انجام می‌شود.</p>
        <div class="modal-actions">
            <a href="#" id="srPdfView" class="button" target="_blank" rel="noopener">مشاهده PDF</a>
            <a href="#" id="srPdfView2" class="button-secondary">چاپ سند</a>
            <a href="#" id="srPdfDownload" class="button-secondary" download>دانلود PDF</a>
        </div>
    </div>
</div>

<script src="assets/js/sales-return.js" defer></script>
