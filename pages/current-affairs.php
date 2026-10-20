<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/current_affairs/ca_loader.php';

$caTypes = [];
foreach (ca_allowed_field_types() as $caT) {
    $caTypes[] = ['value' => $caT, 'label' => ca_field_type_label($caT)];
}
$caMonths = [];
foreach (ca_jalali_month_names() as $caMk => $caName) {
    $caMonths[] = ['value' => (int) $caMk, 'label' => $caName];
}
list($caCurY, $caCurM, $caCurD) = gregorian_to_jalali((int) date('Y'), (int) date('m'), (int) date('d'));
?>
<input type="hidden" id="caCsrfToken" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<input type="hidden" id="caCurYear" value="<?php echo (int) $caCurY; ?>">
<input type="hidden" id="caCurMonth" value="<?php echo (int) $caCurM; ?>">
<script>
    window.CA_FIELD_TYPES = <?php echo json_encode($caTypes, JSON_UNESCAPED_UNICODE); ?>;
    window.CA_MONTHS = <?php echo json_encode($caMonths, JSON_UNESCAPED_UNICODE); ?>;
</script>

<div class="card">
    <div class="settings-tabs" id="caTabs">
        <button type="button" class="active" data-target="caCatsTab">دسته‌بندی‌ها</button>
        <button type="button" data-target="caFieldsTab">فیلدها</button>
        <button type="button" data-target="caRecordsTab">رکوردها</button>
        <button type="button" data-target="caMonthlyTab">گزارش ماهانه</button>
    </div>

    <!-- ==================== دسته‌بندی‌ها ==================== -->
    <div id="caCatsTab" class="settings-section">
        <div class="page-actions" style="margin-bottom:12px;">
            <button type="button" class="button" id="caCatAdd">+ دسته‌بندی جدید</button>
            <button type="button" class="button-secondary" id="caCatRefresh">تازه‌سازی</button>
        </div>
        <div class="table-wrapper">
            <table class="action-table" id="caCatsTable">
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>نام</th>
                        <th>توضیحات</th>
                        <th>فیلدها</th>
                        <th>رکوردها</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="7" class="empty-state">در حال بارگذاری...</td></tr>
                </tbody>
            </table>
        </div>
        <p class="setting-note">دسته‌بندی‌ای که رکورد دارد قابل حذف نیست؛ برای مخفی‌سازی آن را غیرفعال کنید. غیرفعال‌سازی هیچ‌یک از داده‌ها را حذف نمی‌کند.</p>
    </div>

<!-- ==================== مودال دسته‌بندی ==================== -->
<!-- ==================== فیلدها ==================== -->
    <div id="caFieldsTab" class="settings-section" style="display:none;">
        <div class="page-actions" style="margin-bottom:12px;flex-wrap:wrap;gap:8px;">
            <label class="page-size-label" for="caFieldCat">دسته‌بندی:</label>
            <select id="caFieldCat" class="ca-cat-select"></select>
            <button type="button" class="button" id="caFieldAdd">+ فیلد جدید</button>
            <button type="button" class="button-secondary" id="caFieldRefresh">تازه‌سازی</button>
        </div>
        <div class="filter-panel" style="flex-wrap:wrap;gap:8px;margin-bottom:10px;">
            <span class="value-badge" id="caFieldCountBadge">—</span>
            <span class="setting-note" id="caFieldRepNote">تاریخ گزارش‌گیری تنظیم نشده است.</span>
        </div>
        <div class="table-wrapper">
            <table class="action-table" id="caFieldsTable">
                <thead>
                    <tr>
                        <th>ترتیب</th>
                        <th>نام نمایشی</th>
                        <th>نوع</th>
                        <th>اجباری</th>
                        <th>در جدول</th>
                        <th>قابل فیلتر</th>
                        <th>در مجموع</th>
                        <th>وضعیت</th>
                        <th>تاریخ گزارش‌گیری</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="10" class="empty-state">ابتدا یک دسته‌بندی انتخاب کنید.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== رکوردها ==================== -->
    <div id="caRecordsTab" class="settings-section" style="display:none;">
        <div class="page-actions" style="margin-bottom:12px;flex-wrap:wrap;gap:8px;">
            <label class="page-size-label" for="caRecordCat">دسته‌بندی:</label>
            <select id="caRecordCat" class="ca-cat-select"></select>
            <input type="search" id="caRecordSearch" placeholder="جستجو در مقادیر...">
            <button type="button" class="button" id="caRecordAdd">+ رکورد جدید</button>
            <button type="button" class="button-secondary" id="caRecordRefresh">تازه‌سازی</button>
            <a href="#" class="button-secondary" id="caRecordExport">خروجی اکسل</a>
            <a href="assets/php/current_affairs_export.php?type=all" class="button-secondary">خروجی تمام دسته‌بندی‌ها</a>
        </div>
        <div class="filter-panel" id="caRecordFilters" style="flex-wrap:wrap;gap:8px;margin-bottom:10px;"></div>
        <div id="caRecordTotals" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;"></div>
        <div class="table-wrapper">
            <table class="action-table" id="caRecordsTable">
                <thead></thead>
                <tbody>
                    <tr><td colspan="5" class="empty-state">در حال بارگذاری...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="product-pagination" style="margin-top:12px;">
            <span class="pagination-info" id="caRecordsInfo">—</span>
            <div class="pagination-controls" id="caRecordsPagination"></div>
        </div>
    </div>

    <!-- ==================== گزارش ماهانه ==================== -->
    <div id="caMonthlyTab" class="settings-section" style="display:none;">
        <div class="page-actions" style="margin-bottom:12px;flex-wrap:wrap;gap:8px;">
            <label class="page-size-label" for="caMonthlyCat">دسته‌بندی:</label>
            <select id="caMonthlyCat" class="ca-cat-select"></select>
            <label class="page-size-label" for="caMonthlyYear">سال:</label>
            <select id="caMonthlyYear"></select>
            <label class="page-size-label" for="caMonthlyMonth">ماه:</label>
            <select id="caMonthlyMonth"></select>
            <button type="button" class="button" id="caMonthlyGenerate">تولید گزارش</button>
            <a href="#" class="button-secondary" id="caMonthlyExport">خروجی اکسل</a>
        </div>
        <p class="setting-note" id="caMonthlyNote">برای تولید گزارش ماهانه، دسته‌بندی باید دقیقاً یک فیلد تاریخ فعال به‌عنوان «تاریخ گزارش‌گیری» داشته باشد.</p>
        <div id="caMonthlySummary" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;"></div>
        <div class="table-wrapper">
            <table class="action-table" id="caMonthlyTable">
                <thead></thead>
                <tbody>
                    <tr><td colspan="5" class="empty-state">گزارشی تولید نشده است.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="product-pagination" style="margin-top:12px;">
            <span class="pagination-info" id="caMonthlyInfo">—</span>
            <div class="pagination-controls" id="caMonthlyPagination"></div>
        </div>
    </div>
</div>
<div class="modal" id="caCatModal">
    <div class="modal-backdrop" data-close="caCatModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="caCatModalTitle">دسته‌بندی جدید</h2>
            <button class="modal-close" data-close="caCatModal" type="button">×</button>
        </div>
        <form id="caCatForm" autocomplete="off">
            <input type="hidden" id="caCatId" name="id" value="">
            <div class="form-grid">
                <div class="form-row">
                    <label for="caCatName">نام دسته‌بندی <span class="required">*</span></label>
                    <input type="text" id="caCatName" name="name" maxlength="150" required placeholder="مثلا: هزینه‌ها">
                </div>
                <div class="form-row">
                    <label for="caCatDesc">توضیحات</label>
                    <textarea id="caCatDesc" name="description" rows="3" maxlength="4000"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="button" id="caCatSaveBtn">ذخیره</button>
                <button type="button" class="button-secondary" data-close="caCatModal">انصراف</button>
                <span id="caCatResult" style="font-size:13px;font-weight:600;margin-inline-start:10px;"></span>
            </div>
        </form>
    </div>
</div>
<!-- ==================== مودال فیلد ==================== -->
<div class="modal" id="caFieldModal">
    <div class="modal-backdrop" data-close="caFieldModal"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2 id="caFieldModalTitle">فیلد جدید</h2>
            <button class="modal-close" data-close="caFieldModal" type="button">×</button>
        </div>
        <form id="caFieldForm" autocomplete="off">
            <input type="hidden" id="caFieldId" name="id" value="">
            <input type="hidden" id="caFieldCatId" name="category_id" value="">
            <div class="form-grid">
                <div class="form-row">
                    <label for="caFieldLabel">نام نمایشی فیلد <span class="required">*</span></label>
                    <input type="text" id="caFieldLabel" name="label" maxlength="255" required placeholder="مثلا: مبلغ پرداختی">
                </div>
                <div class="form-row">
                    <label for="caFieldType">نوع فیلد</label>
                    <select id="caFieldType" name="field_type"></select>
                    <p class="setting-note" id="caFieldTypeLock" style="display:none;margin:0;">این فیلد دارای مقادیر تاریخی است و تغییر نوع آن مجاز نیست.</p>
                </div>
                <div class="form-row">
                    <label for="caFieldSort">ترتیب نمایش</label>
                    <input type="number" id="caFieldSort" name="sort_order" value="0" style="width:110px;">
                </div>
                <div class="form-row" style="display:none;" id="caFieldOptionsRow">
                    <label for="caFieldOptions">گزینه‌ها (هر گزینه در یک سطر)</label>
                    <textarea id="caFieldOptions" rows="6" placeholder="گزینه ۱&#10;گزینه ۲&#10;گزینه ۳"></textarea>
                </div>
            </div>
            <div class="ca-toggle-grid">
                <span class="toggle-text">الزامی</span>
                <label class="switch" for="caFieldRequired">
                    <input type="checkbox" id="caFieldRequired" value="1"><span class="slider"></span>
                </label>
                <span class="toggle-text">نمایش در جدول</span>
                <label class="switch" for="caFieldShowTable">
                    <input type="checkbox" id="caFieldShowTable" value="1" checked><span class="slider"></span>
                </label>
                <span class="toggle-text">قابل فیلتر</span>
                <label class="switch" for="caFieldFilterable">
                    <input type="checkbox" id="caFieldFilterable" value="1"><span class="slider"></span>
                </label>
                <span class="toggle-text">شرکت در مجموع‌ها</span>
                <label class="switch" for="caFieldInTotals">
                    <input type="checkbox" id="caFieldInTotals" value="1"><span class="slider"></span>
                </label>
                <span class="toggle-text">فعال</span>
                <label class="switch" for="caFieldActive">
                    <input type="checkbox" id="caFieldActive" value="1" checked><span class="slider"></span>
                </label>
            </div>
            <div class="ca-toggle-grid" style="margin-top:6px;display:none;" id="caFieldRepRow">
                <span class="toggle-text">تاریخ گزارش‌گیری</span>
                <label class="switch" for="caFieldIsRep">
                    <input type="checkbox" id="caFieldIsRep" value="1"><span class="slider"></span>
                </label>
                <span class="setting-note" id="caFieldRepText">مبنای گزارش ماهانه است؛ فقط برای فیلد تاریخ فعال.</span>
            </div>
            <div class="form-actions">
                <button type="submit" class="button" id="caFieldSaveBtn">ذخیره</button>
                <button type="button" class="button-secondary" data-close="caFieldModal">انصراف</button>
                <span id="caFieldResult" style="font-size:13px;font-weight:600;margin-inline-start:10px;"></span>
            </div>
        </form>
    </div>
</div>

<!-- ==================== مودال رکورد ==================== -->
<div class="modal" id="caRecordModal">
    <div class="modal-backdrop" data-close="caRecordModal"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2 id="caRecordModalTitle">رکورد جدید</h2>
            <button class="modal-close" data-close="caRecordModal" type="button">×</button>
        </div>
        <form id="caRecordForm" autocomplete="off">
            <input type="hidden" id="caRecordId" name="id" value="">
            <input type="hidden" id="caRecordCatId" name="category_id" value="">
            <div class="form-grid" id="caRecordDynamicFields">
                <p class="empty-state">در حال بارگذاری فیلدها...</p>
            </div>
            <div class="form-actions">
                <button type="submit" class="button" id="caRecordSaveBtn">ذخیره</button>
                <button type="button" class="button-secondary" data-close="caRecordModal">انصراف</button>
                <span id="caRecordResult" style="font-size:13px;font-weight:600;margin-inline-start:10px;"></span>
            </div>
        </form>
    </div>
</div>