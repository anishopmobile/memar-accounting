<?php
require_once __DIR__ . '/../assets/php/product_common.php';

$summary = getInventorySummary($mysqli);
$categoryOptions = buildCategoryOptionsHtml($mysqli);
?>
<input type="hidden" id="productCsrfToken" value="<?php echo csrf_token(); ?>">
<input type="hidden" id="bundleCsrfToken" value="<?php echo csrf_token(); ?>">
<div class="card">
    <div class="page-actions">
        <a href="#" id="new-products" class="button">+ کالای جدید</a>
        <button type="button" class="button button-success" id="openPriceIncreaseModal">افزایش قیمت</button>
        <button type="button" class="button button-success" id="openBundleModal">بسته‌بندی کالا</button>
        <a href="index.php?page=defective-goods" class="button-secondary" id="registerDefectiveLink">ثبت کالای معیوب</a>
        <a href="assets/php/export_products.php" class="button-secondary">خروجی اکسل</a>
        <button type="button" class="button-secondary" id="openProductImportModal">ورود از اکسل</button>
        <div class="filter-panel">
            <span>تعداد:</span>
            <span id="productCount" class="value-badge"><?php echo $summary['total']; ?></span>
            <select id="productCategoryFilter">
                <?php echo buildCategoryFilterOptionsHtml($mysqli); ?>
            </select>
            <span>ارزش موجودی:</span>
            <span class="value-badge"><span id="inventoryValue"><?php echo number_format($summary['value'], 0); ?></span> ریال</span>
            <input type="search" id="productSearch" placeholder="جستجو...">
            <label class="page-size-label">تعداد در صفحه:
                <select id="productPageSize">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </label>
        </div>
    </div>
    <div class="table-wrapper">
        <table id="products-list" class="action-table">
            <thead>
                <tr>
                    <th><button type="button" class="th-sort" data-sort="code">کد</button></th>
                    <th><button type="button" class="th-sort" data-sort="barcode">بارکد</button></th>
                    <th><button type="button" class="th-sort" data-sort="name">نام</button></th>
                    <th><button type="button" class="th-sort" data-sort="type">نوع</button></th>
                    <th><button type="button" class="th-sort" data-sort="unit">واحد</button></th>
                    <th><button type="button" class="th-sort" data-sort="purchase_price">قیمت خرید</button></th>
                    <th><button type="button" class="th-sort" data-sort="sale_price">قیمت فروش</button></th>
                    <th><button type="button" class="th-sort" data-sort="stock">موجودی</button></th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <tr class="empty-state-row">
                    <td colspan="9" class="empty-state">در حال دریافت اطلاعات...</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="product-pagination">
        <span class="pagination-info" id="productPaginationInfo">نمایش 0 تا 0 از 0 کالا</span>
        <div class="pagination-controls">
            <button type="button" class="pagination-button" id="pgFirst" data-page="first" disabled>اولین</button>
            <button type="button" class="pagination-button" id="pgPrev" data-page="prev" disabled>قبلی</button>
            <span class="pagination-current" id="productPaginationCurrent">صفحه 1 از 1</span>
            <button type="button" class="pagination-button" id="pgNext" data-page="next" disabled>بعدی</button>
            <button type="button" class="pagination-button" id="pgLast" data-page="last" disabled>آخرین</button>
        </div>
    </div>
</div>

<div class="modal" id="productModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="productModalTitle">کالای جدید</h2>
            <button class="modal-close" id="closeProductModal" type="button">×</button>
        </div>
        <form id="productForm" method="post" action="assets/php/product_action.php">
            <input type="hidden" name="product_id" id="productId" value="">
            <input type="hidden" name="code" id="productCodeHidden" value="">
            <div class="form-grid">
                <div class="form-row"><label>کد کالا</label><input type="text" id="productCode" readonly disabled placeholder="به صورت خودکار تولید می‌شود"></div>
                <div class="form-row"><label>بارکد</label><input type="text" name="barcode" id="productBarcode" maxlength="100" inputmode="numeric" autocomplete="off" placeholder="بارکد کالا"></div>
                <div class="form-row"><label>نام کالا <span class="required">*</span></label><input type="text" name="name" id="productName" required></div>
                <div class="form-row"><label>دسته‌بندی</label>
                    <select name="category_id" id="productCategory">
                        <?php echo $categoryOptions; ?>
                    </select>
                </div>
                <div class="form-row"><label>نوع</label>
                    <select name="type" id="productType">
                        <option value="product">محصول</option>
                        <option value="service">خدمت</option>
                    </select>
                </div>
                <div class="form-row"><label>واحد</label>
                    <select name="unit" id="productUnit">
                        <option value="عدد">عدد</option>
                        <option value="بسته">بسته</option>
                        <option value="قرص">قرص</option>
                    </select>
                </div>
                <div class="form-row"><label>قیمت خرید</label><input type="text" name="purchase_price" id="productPurchasePrice" inputmode="numeric" autocomplete="off" placeholder="مثال: 10,000,000"></div>
                <div class="form-row"><label>قیمت فروش</label><input type="text" name="sale_price" id="productSalePrice" inputmode="numeric" autocomplete="off" placeholder="مثال: 12,500,000"></div>
                <div class="form-row"><label>موجودی</label><input type="number" name="stock" id="productStock" min="0" step="1"></div>
                <div class="form-row"><label>حداقل موجودی</label><input type="number" name="min_stock" id="productMinStock" min="0" step="1"></div>
                <div class="form-row"><label>توضیحات</label><textarea name="description" id="productDescription" rows="2"></textarea></div>
            </div>
            <div class="product-units-editor">
                <h3>واحدهای اندازه‌گیری</h3>
                <p class="units-help">واحد پایه (ضریب ۱) مرجع موجودی است. ضریب تبدیل، قیمت خرید/فروش و بارکد برای هر واحد جداگانه تنظیم می‌شود.</p>
                <input type="hidden" name="units" id="productUnitsJson" value="">
                <table class="action-table units-table" id="productUnitsTable">
                    <thead>
                        <tr>
                            <th>نام واحد</th>
                            <th>ضریب تبدیل</th>
                            <th>پایه</th>
                            <th>قیمت خرید</th>
                            <th>قیمت فروش</th>
                            <th>بارکد</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <button type="button" class="button-secondary small" id="addProductUnitRow">+ افزودن واحد</button>
                <span class="units-error" id="productUnitsError"></span>
            </div>
            <div class="form-actions modal-actions">
                <button type="submit" class="button" id="saveProductButton">ذخیره</button>
                <button type="button" class="button-secondary" id="cancelProductModal">لغو</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="priceIncreaseModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>افزایش قیمت کالاها</h2>
            <button class="modal-close" id="closePriceIncreaseModal" type="button">×</button>
        </div>
        <form id="priceIncreaseForm" method="post" action="assets/php/price_increase.php">
            <div class="form-grid">
                <div class="form-row" style="grid-column: span 2;">
                    <label>محدوده</label>
                    <select name="scope" id="priceScope">
                        <?php echo buildCategoryFilterOptionsHtml($mysqli, '', 'همه کالاها'); ?>
                    </select>
                </div>
                <div class="form-row" style="grid-column: span 2;">
                    <label>کدام قیمت</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="price_type" value="sale" checked> قیمت فروش</label>
                        <label class="radio-option"><input type="radio" name="price_type" value="purchase"> قیمت خرید</label>
                        <label class="radio-option"><input type="radio" name="price_type" value="both"> هر دو</label>
                    </div>
                </div>
                <div class="form-row" style="grid-column: span 2;">
                    <label>نوع افزایش</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="increase_type" value="percent" checked> درصدی</label>
                        <label class="radio-option"><input type="radio" name="increase_type" value="amount"> مبلغ</label>
                    </div>
                </div>
                <div class="form-row" style="grid-column: span 2;">
                    <label>میزان افزایش</label>
                    <input type="number" name="increase_value" id="priceIncreaseValue" min="0" step="any" inputmode="numeric" placeholder="مثال: ۱۰ یعنی ۱۰٪ افزایش">
                </div>
            </div>
            <div class="form-actions modal-actions">
                <button type="submit" class="button button-success" id="applyPriceIncrease">اعمال</button>
                <button type="button" class="button-secondary" id="cancelPriceIncreaseModal">لغو</button>
            </div>
        </form>
        <div class="price-result" id="priceIncreaseResult" style="display:none;"></div>
    </div>
</div>

<div class="modal" id="productImportModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2>ورود کالاها و خدمات از اکسل</h2>
            <button class="modal-close" id="closeProductImportModal" type="button">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <label>فایل اکسل (.xlsx یا .xls)</label>
                <input type="file" id="productImportFile" accept=".xlsx,.xls">
                <span class="import-help">ردیف اول باید عنوان ستون‌ها باشد (کد کالا، بارکد، نام کالا، دسته‌بندی، نوع، واحد، قیمت خرید، قیمت فروش، موجودی، حداقل موجودی، توضیحات). بارکد اختیاری است. ترتیب ستون‌ها آزاد است.</span>
            </div>
            <div class="form-row" style="grid-column: span 2;">
                <label>کالاهای تکراری (بر اساس کد یا بارکد)</label>
                <div style="display:flex; gap:12px; align-items:center;">
                    <label style="display:flex; gap:6px; align-items:center;">
                        <input type="radio" name="import_mode" value="skip_duplicates" checked>
                        صرف‌نظر از کالاهای تکراری
                    </label>
                    <label style="display:flex; gap:6px; align-items:center;">
                        <input type="radio" name="import_mode" value="update_existing">
                        بروزرسانی اطلاعات
                    </label>
                </div>
                <span class="import-help">در حالت «صرف‌نظر» کالاهای موجود بدون هیچ تغییری رد می‌شوند و فقط کالاهای جدید ثبت می‌شوند. در حالت «بروزرسانی» اطلاعات کالاهای موجود از فایل به‌روزرسانی شده و کالاهای جدید ثبت می‌شوند.</span>
            </div>
        </div>
        <div class="import-actions">
            <button type="button" class="button" id="productImportPreview" disabled>خواندن و پیش‌نمایش</button>
            <button type="button" class="button button-success" id="productImportSubmit" disabled>ثبت نهایی</button>
            <span class="import-spinner" id="productImportSpinner" style="display:none;">در حال پردازش...</span>
        </div>
        <div class="import-result" id="productImportResult" style="display:none;"></div>
        <div class="import-summary" id="productImportSummary" style="display:none;"></div>
        <div class="import-errors-wrap" id="productImportErrorsWrap" style="display:none;">
            <div class="import-sub-title">لیست خطاها:</div>
            <ul class="import-errors" id="productImportErrors"></ul>
        </div>
        <div class="import-preview-wrap" id="productImportPreviewWrap" style="display:none;">
            <div class="import-sub-title">پیش‌نمایش رکوردها:</div>
            <div class="import-preview-scroll">
                <table class="action-table import-preview-table" id="productImportPreviewTable">
                    <thead>
                        <tr>
                            <th>ردیف</th><th>کد</th><th>بارکد</th><th>نام</th><th>دسته‌بندی</th><th>نوع</th><th>واحد</th>
                            <th>قیمت خرید</th><th>قیمت فروش</th><th>موجودی</th><th>حداقل موجودی</th><th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="form-actions modal-actions">
            <button type="button" class="button-secondary" id="cancelProductImportModal">بستن</button>
        </div>
    </div>
</div>

<!-- ============================================================
     مودال بسته‌بندی کالا (Product Bundling)
     ============================================================ -->
<div class="modal" id="bundleModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2 id="bundleModalTitle">بسته‌بندی کالا</h2>
            <button class="modal-close" id="closeBundleModal" type="button">×</button>
        </div>
        <form id="bundleForm" method="post" action="assets/php/bundle_action.php">
            <input type="hidden" name="bundle_id" id="bundleId" value="">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="components" id="bundleComponentsJson" value="">
            <div class="form-grid">
                <div class="form-row">
                    <label>کد بسته</label>
                    <input type="text" id="bundleCode" readonly disabled placeholder="به صورت خودکار تولید می‌شود">
                </div>
                <div class="form-row">
                    <label>بارکد</label>
                    <input type="text" name="barcode" id="bundleBarcode" maxlength="100" autocomplete="off" placeholder="بارکد بسته">
                </div>
                <div class="form-row">
                    <label>نام بسته <span class="required">*</span></label>
                    <input type="text" name="name" id="bundleName" required autocomplete="off">
                </div>
                <div class="form-row">
                    <label>دسته‌بندی</label>
                    <select name="category_id" id="bundleCategory">
                        <?php echo $categoryOptions; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label>نوع</label>
                    <input type="text" value="محصول" readonly disabled>
                    <input type="hidden" name="type" value="product">
                </div>
                <div class="form-row">
                    <label>واحد</label>
                    <input type="text" value="بسته" readonly disabled>
                    <input type="hidden" name="unit" value="بسته">
                </div>
                <div class="form-row">
                    <label>قیمت خرید (نهایی)</label>
                    <input type="text" name="purchase_price" id="bundlePurchasePrice" inputmode="numeric" autocomplete="off">
                    <span class="bundle-hint" id="bundlePurchaseCalc"></span>
                </div>
                <div class="form-row">
                    <label>قیمت فروش (نهایی)</label>
                    <input type="text" name="sale_price" id="bundleSalePrice" inputmode="numeric" autocomplete="off">
                    <span class="bundle-hint" id="bundleSaleCalc"></span>
                </div>
                <div class="form-row">
                    <label>موجودی قابل فروش</label>
                    <input type="text" id="bundleStock" readonly disabled value="0">
                </div>
                <div class="form-row">
                    <label>حداقل موجودی</label>
                    <input type="number" name="min_stock" id="bundleMinStock" min="0" step="1" value="0">
                </div>
                <div class="form-row" style="grid-column: span 2;">
                    <label>توضیحات</label>
                    <textarea name="description" id="bundleDescription" rows="2"></textarea>
                </div>
            </div>

            <div class="bundle-components-editor">
                <div class="bundle-components-head">
                    <h3>کالاهای تشکیل‌دهنده بسته</h3>
                    <button type="button" class="button-secondary small" id="bundleAddProducts">+ افزودن کالا</button>
                </div>
                <p class="units-help">موجودی بسته به‌صورت خودکار از ترکیب کالاهای زیر محاسبه می‌شود (حداقل اجزا) و قابل ورود دستی نیست. فروش هر بسته، به‌اندازه تعداد هر کالا از موجودی همان کالا کم می‌کند.</p>
                <div class="table-wrapper">
                    <table class="action-table" id="bundleComponentsTable">
                        <thead>
                            <tr>
                                <th>کد</th><th>بارکد</th><th>نام</th><th>واحد</th><th>قیمت فروش</th><th>موجودی</th><th>تعداد در بسته</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="empty-state-row"><td colspan="8" class="empty-state">کالایی افزوده نشده است. از دکمه «افزودن کالا» استفاده کنید.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-actions modal-actions">
                <button type="submit" class="button" id="saveBundleButton">ذخیره</button>
                <button type="button" class="button-secondary" id="cancelBundleModal">لغو</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال انتخاب کالای جزء بسته -->
<div class="modal" id="bundleProductModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2>انتخاب کالا</h2>
            <button class="modal-close" id="closeBundleProductModal" type="button">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <input type="search" id="bundleProductSearch" placeholder="جستجو (نام / کد / بارکد)...">
            </div>
        </div>
        <p class="units-help">فقط کالاهای «محصول» استانداردِ دارای موجودی نمایش داده می‌شوند. با انتخاب، تعداد در بسته یک واحد افزایش می‌یابد و این مودال باز می‌ماند.</p>
        <div class="table-wrapper">
            <table class="action-table" id="bundleProductTable">
                <thead>
                    <tr>
                        <th>کد</th><th>بارکد</th><th>نام</th><th>واحد</th><th>قیمت فروش</th><th>موجودی</th><th>تعداد در بسته</th><th></th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="8" class="empty-state">در حال بارگذاری...</td></tr></tbody>
            </table>
        </div>
        <div class="form-actions modal-actions">
            <button type="button" class="button-secondary" id="cancelBundleProductModal">بستن</button>
        </div>
    </div>
</div>

<style>
/* =========================================================
   بسته‌بندی کالا — استایل مودال (مخصوص همین صفحه)
   ========================================================= */
.bundle-components-editor { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border, #dbe1ee); }
.bundle-components-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
.bundle-components-head h3 { margin: 0; font-size: 15px; color: var(--text, #1f3256); }
.bundle-hint { display: block; font-size: 12px; color: var(--muted, #6b7787); margin-top: 4px; }
.bundle-qty { width: 70px; text-align: center; font-weight: 700; border: 1px solid var(--border, #dbe1ee); border-radius: 6px; padding: 2px 4px; background: #fff; }
.bc-qty-wrap { display: inline-flex; align-items: center; gap: 2px; }
.bc-qty-btn { width: 26px; height: 26px; border: 1px solid var(--border, #dbe1ee); background: #f4f7fc; border-radius: 6px; cursor: pointer; font-weight: 700; color: #1f3256; display: inline-flex; align-items: center; justify-content: center; }
.bc-qty-btn:hover { background: #e6edf8; }
.bc-qty-btn:disabled { opacity: .45; cursor: not-allowed; }
</style>

<style>
/* =========================================================
   بارکد داخلی — پیش‌نمایش و چاپ برچسب (مخصوص همین صفحه)
   استایل چاپ به‌صورت جدا اسکوپ شده است تا روی چاپ فاکتورها اثر نگذارد.
   ========================================================= */
.barcode-print-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 22px;
    margin-bottom: 4px;
    font-size: 14px;
    color: var(--text);
    direction: rtl;
}
.barcode-print-summary strong { color: var(--muted); font-weight: 600; margin-left: 4px; }
#barcodePrintQty { width: 120px; }
.barcode-print-area {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
    margin-top: 16px;
}
.barcode-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    background: #fff;
    border: 1px dashed #c3cede;
    border-radius: 8px;
    padding: 8px 6px 6px;
    text-align: center;
}
.barcode-label-img {
    display: block;
    width: 100%;
    max-width: 190px;
    height: 52px;
    margin: 0 auto 2px;
}
.barcode-label-pid,
.barcode-label-value {
    font-size: 13px;
    line-height: 1.8;
    color: #000;
    font-weight: 700;
    direction: ltr;
    letter-spacing: .4px;
    word-break: break-all;
}
.barcode-label-value {
    font-weight: 600;
    font-size: 12px;
}

@media print {
    html, body { overflow: visible !important; height: auto !important; }
    body * { visibility: hidden !important; }
    #barcodePrintModal,
    #barcodePrintModal .modal-content,
    #barcodePrintArea,
    #barcodePrintArea * { visibility: visible !important; }

    #barcodePrintModal {
        position: static !important;
        inset: auto !important;
        display: block !important;
        overflow: visible !important;
        padding: 0 !important;
        z-index: auto !important;
    }
    #barcodePrintModal .modal-backdrop { display: none !important; }
    #barcodePrintModal .modal-content {
        position: static !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
    #barcodePrintModal .modal-header,
    #barcodePrintModal .barcode-print-summary,
    #barcodePrintModal .barcode-print-form,
    #barcodePrintModal .modal-actions { display: none !important; }

    #barcodePrintArea {
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
        direction: ltr !important;
    }
    .barcode-label {
        display: inline-block;
        width: 6cm;
        border: none !important;
        border-radius: 0 !important;
        padding: 2mm 2mm 1mm;
        margin: 0;
        page-break-inside: avoid;
        break-inside: avoid;
        vertical-align: top;
    }
    .barcode-label-img {
        width: 100% !important;
        max-width: none !important;
        height: 22mm !important;
        margin: 0 0 1mm !important;
    }
    .barcode-label-pid,
    .barcode-label-value {
        font-size: 11pt !important;
        line-height: 1.35 !important;
        margin: 0 !important;
    }
    .barcode-label-value { font-size: 10pt !important; }
}
</style>

<div class="modal" id="barcodePrintModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2>چاپ بارکد کالا</h2>
            <button class="modal-close" id="closeBarcodePrintModal" type="button">×</button>
        </div>
        <div class="barcode-print-summary">
            <div><strong>کالا:</strong><span id="barcodePrintName"></span></div>
            <div><strong>Product ID:</strong><span id="barcodePrintProductId"></span></div>
            <div><strong>بارکد:</strong><span id="barcodePrintValue"></span></div>
        </div>
        <div class="form-grid barcode-print-form">
            <div class="form-row">
                <label>تعداد برچسب</label>
                <input type="number" id="barcodePrintQty" min="1" max="1000" step="1" value="1" inputmode="numeric">
            </div>
        </div>
        <div class="form-actions modal-actions">
            <button type="button" class="button button-success" id="printBarcodeBtn">پرینت</button>
            <button type="button" class="button-secondary" id="cancelBarcodePrintModal">بستن</button>
        </div>
        <div class="barcode-print-area" id="barcodePrintArea"></div>
    </div>
</div>
<style>
/* ===== تاریخچه قیمت و فروش (Product Price & Sales History) ===== */
#productHistoryModal .modal-content {
    max-height: 92vh;
    overflow-y: auto;
}
.product-history-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}
.product-history-summary .summary-item {
    background: #f8fbff;
    border: 1px solid #dbe1ee;
    border-radius: 14px;
    padding: 10px 16px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #1f3256;
}
.product-history-summary .summary-item strong {
    font-weight: 700;
    color: #1f3256;
}
.product-history-section {
    margin: 18px 0;
}
.product-history-section h3 {
    font-size: 15px;
    font-weight: 700;
    color: #1f3256;
    margin: 0 0 10px;
}
.product-history-explain {
    background: #f8fbff;
    border: 1px solid #dbe1ee;
    border-radius: 14px;
    padding: 14px 16px;
    font-size: 14px;
    line-height: 2;
    white-space: pre-line;
    color: #1f3256;
}
.product-history-chart {
    position: relative;
    height: 300px;
    width: 100%;
}
</style>

<!-- مودال تاریخچه قیمت و فروش کالا -->
<div class="modal" id="productHistoryModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2>تاریخچه قیمت و فروش</h2>
            <button class="modal-close" id="closeProductHistoryModal" type="button">×</button>
        </div>
        <div class="product-history-body" id="productHistoryBody">
            <div class="empty-state">در حال بارگذاری اطلاعات...</div>
        </div>
    </div>
</div>

<!-- Chart.js (کتابخانه نمودار) — فقط برای نمودار تاریخچه قیمت/فروش در این صفحه بارگذاری می‌شود -->
<script src="assets/js/chart.umd.min.js"></script>
