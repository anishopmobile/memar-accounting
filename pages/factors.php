<?php require_once __DIR__ . '/../assets/php/boot.php'; ?>
<input type="hidden" id="invoiceMode" value="sales">
<input type="hidden" id="invoiceCsrfToken" value="<?php echo csrf_token(); ?>">

<div class="card" id="invoiceListView">
    <div class="page-actions">
        <a href="#" class="button invoice-new" data-type="sales_invoice">+ فاکتور فروش جدید</a>
        <a href="#" class="button-secondary invoice-new" data-type="sales_proforma">+ پیش فاکتور فروش</a>
        <div class="filter-panel">
            <input type="search" id="invoiceSearch" placeholder="جستجو (شماره/مشتری)...">
            <select id="invoiceStatusFilter">
                <option value="">همه وضعیت‌ها</option>
                <option value="paid">پرداخت شده</option>
                <option value="unpaid">پرداخت نشده</option>
                <option value="partial">پرداخت جزئی</option>
            </select>
            <button type="button" class="button-secondary" id="invoiceRefresh">تازه‌سازی</button>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="invoicesTable">
            <thead>
                <tr>
                    <th>شماره</th><th>مشتری</th><th>تاریخ (شمسی)</th><th>نوع</th><th>وضعیت پرداخت</th><th>مبلغ کل</th><th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="7" class="empty-state">در حال بارگذاری...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="product-pagination invoice-pagination" id="invoicePaginationWrap" style="display:none;">
        <span class="pagination-info" id="invoicePaginationInfo">نمایش 0 تا 0 از 0 فاکتور</span>
        <div class="pagination-controls" id="invoicePagination"></div>
    </div>
</div>

<div class="card" id="invoiceFormView" style="display:none;"></div>

<!-- مودال انتخاب کالا -->
<div class="modal" id="invoiceProductModal">
    <div class="modal-backdrop" data-close="invoiceProductModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>انتخاب کالا</h2>
            <button class="modal-close" data-close="invoiceProductModal" type="button">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <input type="search" id="invoiceProductSearch" placeholder="جستجوی کالا...">
            </div>
        </div>
        <div class="table-wrapper">
            <table class="action-table" id="invoiceProductTable">
                <thead>
                    <tr><th>کد</th><th>بارکد</th><th>نام</th><th>واحد</th><th>قیمت</th><th>موجودی</th><th></th></tr>
                </thead>
                <tbody><tr><td colspan="7" class="empty-state">جستجو کنید...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال انتخاب خدمت -->
<div class="modal" id="invoiceServiceModal">
    <div class="modal-backdrop" data-close="invoiceServiceModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>انتخاب خدمت</h2>
            <button class="modal-close" data-close="invoiceServiceModal" type="button">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <input type="search" id="invoiceServiceSearch" placeholder="جستجوی خدمت...">
            </div>
        </div>
        <div class="table-wrapper">
            <table class="action-table" id="invoiceServiceTable">
                <thead>
                    <tr><th>کد</th><th>بارکد</th><th>نام</th><th>واحد</th><th>قیمت</th><th>موجودی</th><th></th></tr>
                </thead>
                <tbody><tr><td colspan="7" class="empty-state">جستجو کنید...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال PDF / چاپ فاکتور فروش -->
<div class="modal" id="invoicePdfModal">
    <div class="modal-backdrop" data-close="invoicePdfModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>تولید و چاپ فاکتور — <span id="invPdfNumber" dir="ltr"></span></h2>
            <button class="modal-close" data-close="invoicePdfModal" type="button">×</button>
        </div>
        <p class="setting-note">سند PDF به‌صورت سرورمحور و بر اساس اطلاعات ثبت‌شده فاکتور تولید می‌شود. برای چاپ، سند PDF در تب جدید باز شده و چاپ از خود سند انجام می‌شود.</p>
        <div class="modal-actions">
            <a href="#" id="invoicePdfView" class="button" target="_blank" rel="noopener">مشاهده PDF</a>
            <a href="#" id="invoicePdfPrint" class="button-secondary">چاپ فاکتور</a>
            <a href="#" id="invoicePdfDownload" class="button-secondary" download>دانلود PDF</a>
        </div>
    </div>
</div>

<!-- مودال انتخاب مشتری -->
<div class="modal" id="invoicePartyModal">
    <div class="modal-backdrop" data-close="invoicePartyModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>انتخاب مشتری</h2>
            <button class="modal-close" data-close="invoicePartyModal" type="button">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <input type="search" id="invoicePartySearch" placeholder="جستجوی مشتری...">
            </div>
        </div>
        <div class="table-wrapper">
            <table class="action-table" id="invoicePartyTable">
                <thead>
                    <tr><th>نام</th><th>تلفن</th><th>کد ملی</th><th></th></tr>
                </thead>
                <tbody><tr><td colspan="4" class="empty-state">جستجو کنید...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>
