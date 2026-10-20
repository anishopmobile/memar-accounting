<?php
require_once __DIR__ . '/../assets/php/boot.php';
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/jdf.php';

// دریافت پارامترهای فیلتر از URL (برای پیش‌فرش ورودی‌های فیلتر)
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['item_search'] ?? '';

// تبدیل تاریخ میلادی به شمسی برای نمایش در اینپوت‌ها
$date_from_display = '';
if ($date_from) {
    $parts = explode('-', $date_from);
    if (count($parts) === 3) {
        $date_from_display = jdate('Y/m/d', mktime(0, 0, 0, $parts[1], $parts[2], $parts[0]));
    }
}

$date_to_display = '';
if ($date_to) {
    $parts = explode('-', $date_to);
    if (count($parts) === 3) {
        $date_to_display = jdate('Y/m/d', mktime(0, 0, 0, $parts[1], $parts[2], $parts[0]));
    }
}
?>

<div class="card">
    <!-- پنل فیلتر -->
    <form method="GET" action="?page=report-sold-items-detail" class="filter-panel" style="margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
        <input type="hidden" name="page" value="report-sold-items-detail">

        <div style="display: flex; flex-direction: column;">
            <label style="font-size: 12px; margin-bottom: 4px; color: #555;">از تاریخ</label>
            <input type="text" id="dateFromDisplay" class="inv-date-display" readonly autocomplete="off" placeholder="۱۴۰۵/۰۱/۰۱" value="<?php echo htmlspecialchars($date_from_display); ?>" style="direction:ltr; text-align:center; width: 130px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <input type="hidden" id="dateFrom" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        </div>

        <div style="display: flex; flex-direction: column;">
            <label style="font-size: 12px; margin-bottom: 4px; color: #555;">تا تاریخ</label>
            <input type="text" id="dateToDisplay" class="inv-date-display" readonly autocomplete="off" placeholder="۱۴۰۵/۱۲/۲۹" value="<?php echo htmlspecialchars($date_to_display); ?>" style="direction:ltr; text-align:center; width: 130px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            <input type="hidden" id="dateTo" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
        </div>

        <div style="display: flex; flex-direction: column; flex-grow: 1; max-width: 350px;">
            <label style="font-size: 12px; margin-bottom: 4px; color: #555;">جستجو (کد، نام یا بارکد کالا)</label>
            <input type="text" id="itemSearch" name="item_search" value="<?php echo htmlspecialchars($search); ?>" placeholder="مثال: 178 یا خودکار بیک" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
        </div>

        <button type="submit" class="button" style="height: 38px; padding: 0 20px;">اعمال فیلتر</button>
        <a href="?page=report-sold-items-detail" class="button-secondary" style="height: 38px; display: inline-flex; align-items: center; padding: 0 15px; text-decoration: none;">پاک کردن</a>
    </form>

    <!-- نمایش تعداد نتایج (فقط هنگام فعال بودن فیلتر) -->
    <div id="soldItemsReportCount" style="margin-bottom: 15px; font-weight: bold; color: #2068ff; font-size: 14px;"></div>

    <!-- نتایج؛ فقط صفحه درخواست‌شده از سرور واکشی و رندر می‌شود -->
    <div id="soldItemsReport">
        <div class="table-wrapper">
            <table class="action-table">
                <tbody>
                    <tr><td colspan="6" class="empty-state">در حال بارگذاری...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- صفحه‌بندی (کلاس‌های مشترک صفحه‌بندی پروژه) -->
    <div class="product-pagination" id="soldItemsPaginationWrap" style="display:none;">
        <span class="pagination-info" id="soldItemsPaginationInfo">نمایش 0 تا 0 از 0 فاکتور</span>
        <div class="pagination-controls" id="soldItemsPagination"></div>
    </div>
</div>

<style>
    .hidden { display: none; }
    .invoice-report-block:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .invoice-header:hover { background: #f1f3f5; }
</style>

<script>
// ============================================================
// ابزارهای رندر امن (escape) و فرمت‌اعداد
// ============================================================
function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
function escAttr(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
// جداکننده هزارگان (فقط لایه نمایش) — معادل number_format سمت سرور
function numFmt(n) {
    if (n === null || n === undefined || n === '') return '0';
    var s = String(n).replace(/,/g, '').split('.')[0];
    if (!/^\d+$/.test(s)) return String(n).replace(/,/g, '');
    return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// ============================================================
// تابع تبدیل اعداد فارسی/عربی به انگلیسی
// ============================================================
function persianToEnglish(str) {
    if (!str) return '';
    var persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    var arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    for (var i = 0; i < 10; i++) {
        str = str.replace(new RegExp(persian[i], 'g'), i)
                 .replace(new RegExp(arabic[i], 'g'), i);
    }
    return str;
}

// ============================================================
// تابع تبدیل تاریخ شمسی (YYYY/MM/DD) به میلادی (YYYY-MM-DD)
// الگوریتم استاندارد و دقیق تبدیل جلالی به میلادی
// ============================================================
function jalaliToGregorian(jy, jm, jd) {
    jy = parseInt(jy, 10);
    jm = parseInt(jm, 10);
    jd = parseInt(jd, 10);

    var gy, gm, gd;
    var days;

    jy += 1595;
    days = -355668 + (365 * jy) + (~~(jy / 33) * 8) + ~~(((jy % 33) + 3) / 4) + jd;
    if (jm < 7) {
        days += (jm - 1) * 31;
    } else {
        days += ((jm - 7) * 30) + 186;
    }

    gy = 400 * ~~(days / 146097);
    days %= 146097;
    if (days > 36524) {
        gy += 100 * ~~(--days / 36524);
        days %= 36524;
        if (days >= 365) days++;
    }
    gy += 4 * ~~(days / 1461);
    days %= 1461;
    if (days > 365) {
        gy += ~~((days - 1) / 365);
        days = (days - 1) % 365;
    }
    gd = days + 1;

    var sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for (gm = 1; gm <= 12 && gd > sal_a[gm]; gm++) {
        gd -= sal_a[gm];
    }

    return gy + '-' + (gm < 10 ? '0' + gm : gm) + '-' + (gd < 10 ? '0' + gd : gd);
}

// ============================================================
// تابع اصلی: تبدیل رشته تاریخ شمسی ورودی به فرمت میلادی دیتابیس
// ============================================================
function jalaliStrToGregInput(jalaliStr) {
    if (!jalaliStr) return '';
    // تبدیل اعداد فارسی/عربی به انگلیسی
    var clean = persianToEnglish(String(jalaliStr).trim());
    // جدا کردن اجزای تاریخ با / یا - یا فاصله
    var parts = clean.split(/[\/\-\s]/);
    if (parts.length < 3) return '';
    var jy = parseInt(parts[0], 10);
    var jm = parseInt(parts[1], 10);
    var jd = parseInt(parts[2], 10);
    if (isNaN(jy) || isNaN(jm) || isNaN(jd)) return '';
    // سال باید 4 رقمی باشد
    if (jy < 100) jy += 1300;
    return jalaliToGregorian(jy, jm, jd);
}

// ============================================================
// همگام‌سازی فیلد مخفی میلادی با فیلد نمایشی شمسی
// ============================================================
function syncHiddenFromDisplay(displayId, hiddenId) {
    var jalaliVal = document.getElementById(displayId).value || '';
    jalaliVal = String(jalaliVal).trim();
    if (!jalaliVal) {
        document.getElementById(hiddenId).value = '';
        return;
    }
    var greg = jalaliStrToGregInput(jalaliVal);
    if (greg) {
        document.getElementById(hiddenId).value = greg;
    }
}

// ============================================================
// بارگذاری گزارش (فقط صفحه درخواست‌شده از سرور؛ صفحه‌بندی سمت سرور)
// ============================================================
var soldItemsCurrentPage = 1;

function loadReport(page) {
    soldItemsCurrentPage = (page && page >= 1) ? page : 1;
    var dateFrom = String($('#dateFrom').val() || '').trim();
    var dateTo = String($('#dateTo').val() || '').trim();
    var search = String($('#itemSearch').val() || '').trim();

    $('#soldItemsReport').html(
        '<div class="table-wrapper"><table class="action-table"><tbody>'
        + '<tr><td colspan="6" class="empty-state">در حال بارگذاری...</td></tr>'
        + '</tbody></table></div>'
    );

    var params = { page: soldItemsCurrentPage, per_page: 25 };
    if (dateFrom !== '') params.date_from = dateFrom;
    if (dateTo !== '') params.date_to = dateTo;
    if (search !== '') params.item_search = search;

    $.getJSON('assets/php/report_sold_items.php', params)
        .done(function (res) {
            if (!res || !res.success) {
                $('#soldItemsReport').html(
                    '<div class="table-wrapper"><table class="action-table"><tbody>'
                    + '<tr><td colspan="6" class="empty-state">' + esc((res && res.message) || 'خطا در دریافت گزارش.') + '</td></tr>'
                    + '</tbody></table></div>'
                );
                $('#soldItemsPaginationWrap').hide();
                $('#soldItemsReportCount').text('');
                return;
            }
            renderReport(res.data || {});
        })
        .fail(function () {
            $('#soldItemsReport').html(
                '<div class="table-wrapper"><table class="action-table"><tbody>'
                + '<tr><td colspan="6" class="empty-state">خطا در ارتباط با سرور.</td></tr>'
                + '</tbody></table></div>'
            );
            $('#soldItemsPaginationWrap').hide();
            $('#soldItemsReportCount').text('');
        });
}

function renderReport(data) {
    var rows = data.rows || [];
    var total = data.total || 0;
    var page = data.page || soldItemsCurrentPage;
    var perPage = data.per_page || 25;
    soldItemsCurrentPage = page;

    var hasFilter = !!(String($('#dateFrom').val() || '').trim()
        || String($('#dateTo').val() || '').trim()
        || String($('#itemSearch').val() || '').trim());
    $('#soldItemsReportCount').text(hasFilter ? (total + ' فاکتور یافت شد') : '');

    if (!rows.length) {
        $('#soldItemsReport').html(
            '<div class="table-wrapper"><table class="action-table"><tbody>'
            + '<tr><td colspan="6" class="empty-state">موردی یافت نشد. لطفاً بازه زمانی یا عبارت جستجو را تغییر دهید.</td></tr>'
            + '</tbody></table></div>'
        );
    } else {
        var html = '';
        rows.forEach(function (inv) { html += invoiceBlockHtml(inv); });
        $('#soldItemsReport').html(html);
    }
    renderPagination({ total: total, page: page, perPage: perPage });
}

function invoiceBlockHtml(inv) {
    var typeLbl = inv.type === 'sales_proforma' ? 'پیش فاکتور' : 'فاکتور';
    var stLbl = ({ paid: 'پرداخت شده', unpaid: 'پرداخت نشده', partial: 'جزئی' })[inv.payment_status] || inv.payment_status || '';
    // لینک واقعی و امن با شناسه دیتابیس فاکتور
    var editHref = '?page=factors&action=edit&id=' + encodeURIComponent(String(inv.id));
    var itemsHtml = '';
    (inv.items || []).forEach(function (it) {
        itemsHtml += '<tr>'
            + '<td style="text-align: right;">' + esc(it.product_code) + ' — ' + esc(it.product_name)
            + (it.barcode ? '<br><small style="color: #888;">بارکد: ' + esc(it.barcode) + '</small>' : '')
            + '</td>'
            + '<td>' + esc(it.unit_name) + '</td>'
            + '<td>' + numFmt(it.unit_price) + '</td>'
            + '<td>' + numFmt(it.quantity) + '</td>'
            + '<td>' + numFmt(it.discount) + '</td>'
            + '<td>' + numFmt(it.line_total) + ' ریال</td>'
            + '</tr>';
    });
    return '<div class="invoice-report-block" style="border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 15px; overflow: hidden; background: #fff;">'
        + '<div class="invoice-header" style="padding: 12px 15px; background: #f8f9fa; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e0e0;" onclick="toggleInvoiceItems(this)">'
        + '<div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 14px; color: #333;">'
        + '<span><strong>شماره:</strong> <a href="' + escAttr(editHref) + '" style="color: #2068ff; text-decoration: none;">' + esc(inv.invoice_number) + '</a></span>'
        + '<span><strong>مشتری:</strong> ' + esc(inv.customer_name) + '</span>'
        + '<span><strong>تاریخ:</strong> ' + esc(inv.invoice_date_shamsi || inv.invoice_date) + '</span>'
        + '<span><strong>نوع:</strong> ' + esc(typeLbl) + '</span>'
        + '<span><strong>وضعیت:</strong> ' + esc(stLbl) + '</span>'
        + '<span><strong>مبلغ کل:</strong> ' + numFmt(inv.payable_amount) + ' ریال</span>'
        + '</div>'
        + '<span class="toggle-icon" style="transition: transform 0.2s; color: #666;">▼</span>'
        + '</div>'
        + '<div class="invoice-items hidden" style="padding: 0;">'
        + '<table class="action-table" style="margin: 0; border: none; box-shadow: none;">'
        + '<thead><tr style="background: #fff;">'
        + '<th style="text-align: right;">کالا</th><th>واحد</th><th>قیمت واحد</th><th>تعداد</th><th>تخفیف</th><th>مبلغ ردیف</th>'
        + '</tr></thead>'
        + '<tbody>' + itemsHtml + '</tbody>'
        + '</table>'
        + '</div>'
        + '</div>';
}

// ============================================================
// صفحه‌بندی (کلاس‌های مشترک پروژه + event delegation)
// ============================================================
function pagerPages(page, pages) {
    if (pages <= 7) {
        var all = [];
        for (var i = 1; i <= pages; i++) all.push(i);
        return all;
    }
    var out = [];
    function push(n) { if (out.length === 0 || out[out.length - 1] !== n) out.push(n); }
    push(1);
    if (page > 4) push('...');
    for (var p = Math.max(2, page - 1); p <= Math.min(pages - 1, page + 1); p++) push(p);
    if (page < pages - 3) push('...');
    push(pages);
    return out;
}

function renderPagination(meta) {
    var $wrap = $('#soldItemsPaginationWrap');
    if (!$wrap.length) return;
    var page = (meta && meta.page) || 1;
    var perPage = (meta && meta.perPage) || 25;
    var total = (meta && meta.total) || 0;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (totalPages <= 1) { $wrap.hide(); return; }
    $wrap.show();
    var rangeStart = total === 0 ? 0 : (page - 1) * perPage + 1;
    var rangeEnd = Math.min(page * perPage, total);
    $('#soldItemsPaginationInfo').text('نمایش ' + rangeStart + ' تا ' + rangeEnd + ' از ' + total + ' فاکتور');
    var html = '<button type="button" class="pagination-button sold-items-page-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>صفحه قبل</button> ';
    pagerPages(page, totalPages).forEach(function (p) {
        if (p === '...') { html += '<span class="pagination-ellipsis">…</span> '; return; }
        if (p === page) { html += '<span class="pagination-current">' + p + '</span> '; }
        else { html += '<button type="button" class="pagination-button sold-items-page-btn" data-page="' + p + '">' + p + '</button> '; }
    });
    html += '<button type="button" class="pagination-button sold-items-page-btn" data-page="' + (page + 1) + '"' + (page >= totalPages ? ' disabled' : '') + '>صفحه بعد</button>';
    $('#soldItemsPagination').html(html);
}

$(document).on('click', '.sold-items-page-btn', function () {
    if ($(this).prop('disabled')) return;
    var p = parseInt($(this).data('page'), 10);
    if (!isNaN(p) && p >= 1) loadReport(p);
});

// ============================================================
// اجرای اولیه هنگام بارگذاری صفحه
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // راه‌اندازی DatePicker شمسی
    if (window.kamaDatepicker) {
        var dpOptions = {
            placeholder: '',
            twodigit: true,
            closeAfterSelect: true,
            nextButtonIcon: 'بعدی',
            previousButtonIcon: 'قبلی',
            forceFarsiDigits: true,
            markToday: true,
            highlightSelectedDay: true,
            sync: true,
            gotoToday: true
        };
        window.kamaDatepicker('dateFromDisplay', dpOptions);
        window.kamaDatepicker('dateToDisplay', dpOptions);
    }

    // همگام‌سازی اولیه هنگام بارگذاری صفحه (اگر مقدار از قبل وجود دارد)
    syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
    syncHiddenFromDisplay('dateToDisplay', 'dateTo');

    // همگام‌سازی هنگام تغییر تاریخ
    document.getElementById('dateFromDisplay').addEventListener('change', function() {
        syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
    });
    document.getElementById('dateToDisplay').addEventListener('change', function() {
        syncHiddenFromDisplay('dateToDisplay', 'dateTo');
    });

    // جلوگیری از ارسال فرم با Enter در فیلدهای تاریخ (برای اطمینان از sync شدن)
    document.getElementById('dateFromDisplay').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
        }
    });
    document.getElementById('dateToDisplay').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            syncHiddenFromDisplay('dateToDisplay', 'dateTo');
        }
    });

    // قبل از submit فرم، یک بار دیگر sync انجام شود (برای اطمینان نهایی)
    var filterForm = document.querySelector('form.filter-panel');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            syncHiddenFromDisplay('dateFromDisplay', 'dateFrom');
            syncHiddenFromDisplay('dateToDisplay', 'dateTo');
        });
    }

    // بارگذاری صفحه اول (فیلترهای فعلی از ورودی‌ها خوانده می‌شوند)
    loadReport(1);
});

// تابع باز و بسته کردن جزئیات فاکتور
function toggleInvoiceItems(header) {
    var items = header.nextElementSibling;
    var icon = header.querySelector('.toggle-icon');
    if (items.classList.contains('hidden')) {
        items.classList.remove('hidden');
        icon.style.transform = 'rotate(180deg)';
    } else {
        items.classList.add('hidden');
        icon.style.transform = 'rotate(0deg)';
    }
}
</script>