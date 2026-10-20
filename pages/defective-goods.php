<?php
require_once __DIR__ . '/../assets/php/product_common.php';
require_once __DIR__ . '/../assets/php/jdf.php';
require_once __DIR__ . '/../assets/php/defective_goods_common.php';

$dgFilters = dg_extract_date_filters($_GET);

/* صفحه‌بندی گزارش: ۲۵ رکورد در هر صفحه (سمت سرور) */
$dgPerPage = 25;
$dgPage = max(1, (int) ($_GET['pageno'] ?? 1));
$dgSummary = dg_summary($mysqli, $dgFilters);
$dgData = dg_list($mysqli, $dgFilters, $dgPage, $dgPerPage);

$dgTodayGreg = date('Y-m-d');
$dgTodayJalali = jdate('Y/m/d', time());

if (!function_exists('dgPagerUrl')) {
    function dgPagerUrl(array $filters, int $pageNo): string
    {
        $qs = ['page' => 'defective-goods'];
        if ($pageNo > 1) { $qs['pageno'] = $pageNo; }
        if (!empty($filters['from'])) { $qs['from'] = $filters['from']; }
        if (!empty($filters['to']))   { $qs['to'] = $filters['to']; }
        return 'index.php?' . http_build_query($qs);
    }
}

$dgExportQs = [];
if (!empty($dgFilters['from'])) { $dgExportQs['from'] = $dgFilters['from']; }
if (!empty($dgFilters['to']))   { $dgExportQs['to'] = $dgFilters['to']; }
$dgExportUrl = 'assets/php/defective_goods_export.php';
if ($dgExportQs) { $dgExportUrl .= '?' . http_build_query($dgExportQs); }
?>
<input type="hidden" id="defCsrfToken" value="<?php echo csrf_token(); ?>">
<input type="hidden" id="defCurrentPage" value="<?php echo (int) $dgPage; ?>">

<!-- ============================ ثبت کالای معیوب ============================ -->
<div class="card" id="defRegCard">
    <div class="page-actions">
        <h2 style="margin:0;">ثبت کالای معیوب</h2>
    </div>
    <form id="defRegForm" autocomplete="off">
        <input type="hidden" id="defProductId" name="product_id" value="">
        <input type="hidden" id="defDate" name="defective_date" value="<?php echo $dgTodayGreg; ?>">
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <label>کالا <span class="required">*</span></label>
                <div style="display:flex;gap:8px;align-items:stretch;">
                    <input type="text" id="defProductDisplay" readonly placeholder="برای انتخاب کالا، دکمه «انتخاب کالا» را بزنید..." style="flex:1;min-width:0;">
                    <button type="button" class="button-secondary" id="defPickProduct">انتخاب کالا</button>
                </div>
                <div id="defProductInfo" style="margin-top:6px;font-size:13px;color:#6b7787;min-height:18px;"></div>
            </div>
            <div class="form-row">
                <label>موجودی فعلی</label>
                <div class="value-badge" id="defCurrentStock">—</div>
            </div>
            <div class="form-row">
                <label>تعداد معیوب <span class="required">*</span></label>
                <input type="number" id="defQuantity" name="quantity" min="0" step="1" inputmode="numeric" placeholder="تعداد کالای معیوب">
            </div>
            <div class="form-row">
                <label>تاریخ (شمسی) <span class="required">*</span></label>
                <input type="text" id="defDateDisplay" readonly value="<?php echo $dgTodayJalali; ?>" style="width:150px;">
            </div>
            <div class="form-row" style="grid-column: span 2;">
                <label>دلیل <span class="required">*</span></label>
                <input type="text" id="defReason" name="reason" maxlength="500" placeholder="مثلا: آسیب‌دیده هنگام حمل‌ونقل">
            </div>
            <div class="form-row" style="grid-column: span 2;">
                <label>توضیحات</label>
                <textarea id="defDescription" name="description" rows="3" maxlength="2000"></textarea>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="button button-success" id="defSubmit">ثبت کالای معیوب</button>
            <button type="button" class="button-secondary" id="defReset">تازه‌سازی فرم</button>
            <span id="defResult" style="font-size:13px;font-weight:600;margin-inline-start:10px;"></span>
        </div>
    </form>
</div>

<!-- ============================ گزارش کالاهای معیوب ============================ -->
<div class="card" id="defReportCard">
    <div class="page-actions">
        <h2 style="margin:0;">گزارش کالاهای معیوب</h2>
        <a href="<?php echo htmlspecialchars($dgExportUrl, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary" id="defExportBtn">خروجی اکسل</a>
    </div>
    <form method="get" action="index.php" class="filter-panel" id="defFilterForm">
        <input type="hidden" name="page" value="defective-goods">
        <input type="hidden" name="pageno" value="1">
        <input type="hidden" name="from" id="defFrom" value="<?php echo htmlspecialchars((string) ($dgFilters['from'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="to" id="defTo" value="<?php echo htmlspecialchars((string) ($dgFilters['to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <span>از تاریخ:</span>
        <input type="text" id="defFromDisplay" class="def-date-input" readonly style="width:150px;" value="<?php echo !empty($dgFilters['from']) ? htmlspecialchars(jdate('Y/m/d', strtotime((string) $dgFilters['from'])), ENT_QUOTES, 'UTF-8') : ''; ?>">
        <span>تا تاریخ:</span>
        <input type="text" id="defToDisplay" class="def-date-input" readonly style="width:150px;" value="<?php echo !empty($dgFilters['to']) ? htmlspecialchars(jdate('Y/m/d', strtotime((string) $dgFilters['to'])), ENT_QUOTES, 'UTF-8') : ''; ?>">
        <button type="submit" class="button-secondary">اعمال فیلتر</button>
        <a href="index.php?page=defective-goods" class="button-secondary" id="defFilterClear">حذف فیلتر</a>
    </form>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <span class="value-badge" id="defSummaryRecords">تعداد رکوردها: <?php echo number_format($dgSummary['records']); ?></span>
        <span class="value-badge" id="defSummaryQty">جمع تعداد معیوب: <?php echo number_format((float) $dgSummary['total_quantity']); ?></span>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="defReportTable">
            <thead>
                <tr>
                    <th>ردیف</th>
                    <th>تاریخ (شمسی)</th>
                    <th>نام کالا</th>
                    <th>بارکد</th>
                    <th>کد کالا</th>
                    <th>تعداد</th>
                    <th>دلیل</th>
                    <th>توضیحات</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody id="defReportBody">
                <?php if (count($dgData['rows']) === 0): ?>
                    <tr class="empty-state-row"><td colspan="9" class="empty-state">رکوردی یافت نشد</td></tr>
                <?php else: ?>
                    <?php $dgRowNo = $dgData['rangeStart']; foreach ($dgData['rows'] as $dgRow): ?>
                        <tr>
                            <td><?php echo $dgRowNo++; ?></td>
                            <td><?php echo jdate('Y/m/d', strtotime((string) $dgRow['defective_date'])); ?></td>
                            <td><?php echo htmlspecialchars((string) $dgRow['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars((string) ($dgRow['product_barcode'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars((string) $dgRow['product_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo number_format((float) $dgRow['quantity']); ?> <?php echo htmlspecialchars((string) ($dgRow['product_unit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $dgRow['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($dgRow['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <button type="button" class="button-secondary small def-return-btn"
                                        data-id="<?php echo (int) $dgRow['id']; ?>"
                                        data-qty="<?php echo htmlspecialchars((string) ($dgRow['quantity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-product="<?php echo htmlspecialchars((string) ($dgRow['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-unit="<?php echo htmlspecialchars((string) ($dgRow['product_unit'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">برگشت به موجودی</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<div class="product-pagination" style="margin-top:12px;">
        <span class="pagination-info" id="defPaginationInfo">نمایش <?php echo $dgData['rangeStart']; ?> تا <?php echo $dgData['rangeEnd']; ?> از <?php echo $dgData['total']; ?> رکورد</span>
        <div class="pagination-controls" id="defPaginationControls">
            <?php if ($dgData['totalPages'] > 1): ?>
                <a class="pagination-button<?php echo $dgData['page'] === 1 ? ' disabled' : ''; ?>" data-page="1" href="<?php echo $dgData['page'] === 1 ? '#' : dgPagerUrl($dgFilters, 1); ?>" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;<?php echo $dgData['page'] === 1 ? 'opacity:0.45;pointer-events:none;' : ''; ?>">اولین</a>
                <a class="pagination-button<?php echo $dgData['page'] === 1 ? ' disabled' : ''; ?>" data-page="<?php echo max(1, $dgData['page'] - 1); ?>" href="<?php echo $dgData['page'] === 1 ? '#' : dgPagerUrl($dgFilters, $dgData['page'] - 1); ?>" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;<?php echo $dgData['page'] === 1 ? 'opacity:0.45;pointer-events:none;' : ''; ?>">قبلی</a>
                <span class="pagination-current">صفحه <?php echo $dgData['page']; ?> از <?php echo $dgData['totalPages']; ?></span>
                <a class="pagination-button<?php echo $dgData['page'] === $dgData['totalPages'] ? ' disabled' : ''; ?>" data-page="<?php echo min($dgData['totalPages'], $dgData['page'] + 1); ?>" href="<?php echo $dgData['page'] === $dgData['totalPages'] ? '#' : dgPagerUrl($dgFilters, $dgData['page'] + 1); ?>" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;<?php echo $dgData['page'] === $dgData['totalPages'] ? 'opacity:0.45;pointer-events:none;' : ''; ?>">بعدی</a>
                <a class="pagination-button<?php echo $dgData['page'] === $dgData['totalPages'] ? ' disabled' : ''; ?>" data-page="<?php echo $dgData['totalPages']; ?>" href="<?php echo $dgData['page'] === $dgData['totalPages'] ? '#' : dgPagerUrl($dgFilters, $dgData['totalPages']); ?>" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;<?php echo $dgData['page'] === $dgData['totalPages'] ? 'opacity:0.45;pointer-events:none;' : ''; ?>">آخرین</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ============================ مودال برگشت به موجودی ============================ -->
<div class="modal" id="defReturnModal">
    <div class="modal-backdrop" data-close="defReturnModal"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>برگشت به موجودی</h2>
            <button class="modal-close" data-close="defReturnModal" type="button">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <label>کالا</label>
                <div id="defReturnProduct" class="value-badge"></div>
            </div>
            <div class="form-row" style="grid-column: span 2;">
                <label>مقدار معیوب باقی‌مانده</label>
                <div id="defReturnAvail" class="value-badge"></div>
            </div>
            <div class="form-row" style="grid-column: span 2;">
                <label>تعداد برگشتی <span class="required">*</span></label>
                <input type="number" id="defReturnQty" min="0" step="0.01" inputmode="decimal" placeholder="تعداد کالای ترمیم‌شده">
                <span id="defReturnQtyError" style="display:none;color:#c0392b;font-size:12px;font-weight:600;"></span>
            </div>
        </div>
        <div class="form-actions">
            <button type="button" class="button button-success" id="defReturnConfirm">تایید و برگشت به موجودی</button>
            <button type="button" class="button-secondary" data-close="defReturnModal">انصراف</button>
        </div>
    </div>
</div>

<!-- ============================ مودال انتخاب کالا ============================ -->
<div class="modal" id="defProductModal">
    <div class="modal-backdrop" data-close="defProductModal"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2>انتخاب کالا</h2>
            <button class="modal-close" data-close="defProductModal" type="button">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column: span 2;">
                <input type="search" id="defProductSearch" placeholder="جستجو بر اساس نام، کد یا بارکد...">
            </div>
        </div>
        <div class="table-wrapper">
            <table class="action-table" id="defProductTable">
                <thead>
                    <tr><th>کد</th><th>بارکد</th><th>نام کالا</th><th>واحد</th><th>موجودی</th><th></th></tr>
                </thead>
                <tbody><tr><td colspan="6" class="empty-state">در حال بارگذاری...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script src="assets/js/defective-goods.js" defer></script>