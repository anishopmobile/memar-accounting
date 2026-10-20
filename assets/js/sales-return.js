/* ============================================================
 * sales-return.js — ماژول برگشت فروش (Frontend)
 * هم‌ساز با کنوانسیون‌های پروژه (jQuery + AJAX + JSON)
 * تمام رویدادها اسکوپ‌شده به صفحه برگشت فروش هستند.
 * ========================================================== */
$(function () {
    if (document.getElementById('srListView') === null) return; // فقط صفحه برگشت فروش

    var csrf = String($('#srCsrfToken').val() || '');
    var currentPage = 1;
    var currentReturnId = '';
    var currentInvoiceId = '';
    var currentCtx = null;      // invoice + items بارگذاری‌شده از سرور
    var taxRate = 0, taxEnabled = false;

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/\"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    //function splitNum(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
	function splitNum(n) {
    const num = Number(n);
    if (!Number.isFinite(num)) return String(n);

    return num.toLocaleString('en-US', {
        maximumFractionDigits: 2,
        useGrouping: true
    });
}
    /* نمایش مبلغ ریالی به‌صورت عدد صحیح با جداکننده هزارگان (بدون اعشار) — فقط لایه نمایش */
    function fmtRial(n) {
        if (n === null || n === undefined || n === '') return '0';
        var neg = '';
        var s = String(n).trim();
        if (s.charAt(0) === '-') { neg = '-'; s = s.slice(1); }
        s = s.replace(/,/g, '').split('.')[0];
        if (!/^\d+$/.test(s)) s = '0';
        return neg + s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function todayISO() { var d = new Date(); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }

    function openModal(id) { $('#' + id).addClass('open'); $('body').addClass('modal-open'); }
    function closeModal(id) { $('#' + id).removeClass('open'); $('body').removeClass('modal-open'); }
    $(document).on('click', '[data-close]', function () { closeModal($(this).data('close')); });

    /* ---------- تبدیل تاریخ جلالی ↔ میلادی (همان الگوریتم jdf.php / invoice.js) ---------- */
    var SR_GDM = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    function gregorianToJalali(gy, gm, gd) {
        var jyBase = (gy > 1600) ? 979 : 0;
        var gy2 = gy - (gy > 1600 ? 1600 : 621);
        var gy3 = (gm > 2) ? gy2 + 1 : gy2;
        var days = 365 * gy2 + Math.floor((gy3 + 3) / 4) - Math.floor((gy3 + 99) / 100) + Math.floor((gy3 + 399) / 400) - 80 + gd + SR_GDM[gm - 1];
        var jy = jyBase + 33 * Math.floor(days / 12053);
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var jm, jd, part;
        if (days < 186) { jm = 1 + Math.floor(days / 31); part = days % 31; }
        else { jm = 7 + Math.floor((days - 186) / 30); part = (days - 186) % 30; }
        jd = 1 + part;
        return [jy, jm, jd];
    }
    function jalaliToGregorian(jy, jm, jd) {
        var DAY = 86400000;
        var target = jy * 10000 + jm * 100 + jd;
        var lo = Date.UTC(1800, 0, 1) / DAY;
        var hi = Date.UTC(2100, 11, 31) / DAY;
        while (lo <= hi) {
            var mid = lo + ((hi - lo) >> 1);
            var t = new Date(mid * DAY);
            var g = [t.getUTCFullYear(), t.getUTCMonth() + 1, t.getUTCDate()];
            var j = gregorianToJalali(g[0], g[1], g[2]);
            var cm = (j[0] * 10000 + j[1] * 100 + j[2]) - target;
            if (cm === 0) return g;
            if (cm < 0) lo = mid + 1; else hi = mid - 1;
        }
        return null;
    }
    function jalaliStrToGregInput(jalaliStr) {
        var m = String(jalaliStr || '').trim().match(/^(\d{4,})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
        if (!m) return '';
        var g = jalaliToGregorian(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10));
        return g ? (g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2])) : '';
    }
    function gregInputToJalaliStr(gregISO) {
        var p = String(gregISO || '').split('-');
        if (p.length !== 3) return '';
        var j = gregorianToJalali(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2], 10));
        return j[0] + '/' + pad2(j[1]) + '/' + pad2(j[2]);
    }

    /* ---------- لیست سندهای برگشت ---------- */
    function loadList(page) {
        page = page || 1;
        currentPage = page;
        var search = encodeURIComponent($('#srSearch').val() || '');
        $.getJSON('assets/php/sales_return_list.php?page=' + page + '&search=' + search)
            .done(function (res) {
                if (res && res.data && res.data.page) { currentPage = res.data.page; }
                renderList(res.data);
            })
            .fail(function () { $('#srTable tbody').html('<tr><td colspan="7" class="empty-state" style="padding:30px 16px;">خطا در بارگذاری برگشت‌ها.</td></tr>'); });
    }
    function renderList(data) {
        var rows = (data && data.rows) || [];
        var total = data ? data.total : 0;
        var tbody = $('#srTable tbody');
        if (!rows.length) { tbody.html('<tr><td colspan="7" class="empty-state" style="padding:30px 16px;">سند برگشتی یافت نشد.</td></tr>'); $('#srPager').empty(); return; }
        var html = '';
        rows.forEach(function (r) {
            html += '<tr>'
                + '<td dir="ltr">' + esc(r.return_number) + '</td>'
                + '<td dir="ltr">' + esc(r.invoice_number) + '</td>'
                + '<td>' + esc(r.customer_name || '—') + '</td>'
                + '<td>' + esc(r.return_date_shamsi) + '</td>'
                + '<td class="sr-money-cell">' + fmtRial(r.payable_amount) + ' ریال</td>'
                + '<td>' + esc(r.item_count) + '</td>'
                + '<td class="invoice-actions">'
                + '<button type="button" class="button-secondary small sr-view" data-id="' + escAttr(r.id) + '">مشاهده</button> '
                + '<button type="button" class="button-secondary small sr-edit" data-id="' + escAttr(r.id) + '">ویرایش</button> '
                + '<button type="button" class="button-secondary small sr-pdf" data-id="' + escAttr(r.id) + '" data-number="' + escAttr(r.return_number) + '">چاپ</button> '
                + '<button type="button" class="button-secondary small sr-delete" data-id="' + escAttr(r.id) + '">حذف</button>'
                + '</td></tr>';
        });
        tbody.html(html);
        renderPager((data && data.page) || page, total, data.per_page || 15);
    }
    function srPagerPages(page, pages) {
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
    function renderPager(page, total, perPage) {
        var $p = $('#srPager');
        $p.empty();
        var pages = Math.max(1, Math.ceil(total / perPage));
        if (pages <= 1) return; // یک صفحه یا بدون رکورد → بدون صفحه‌بندی
        var rangeStart = total === 0 ? 0 : (page - 1) * perPage + 1;
        var rangeEnd = Math.min(page * perPage, total);
        var html = '<span class="sr-totals-note">نمایش ' + rangeStart + ' تا ' + rangeEnd + ' از ' + total + ' برگشت</span> ';
        html += '<button type="button" class="pagination-button sr-page-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>صفحه قبل</button> ';
        srPagerPages(page, pages).forEach(function (p) {
            if (p === '...') { html += '<span class="pagination-ellipsis">…</span> '; return; }
            if (p === page) { html += '<span class="pagination-current">' + p + '</span> '; }
            else { html += '<button type="button" class="pagination-button sr-page-btn" data-page="' + p + '">' + p + '</button> '; }
        });
        html += '<button type="button" class="pagination-button sr-page-btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>صفحه بعد</button>';
        $p.html(html);
    }
    $(document).on('click', '.sr-page-btn', function () {
        if ($(this).prop('disabled')) return;
        var p = parseInt($(this).data('page'), 10);
        if (p >= 1) loadList(p);
    });

    $(document).on('click', '#srRefresh', function () { loadList(currentPage); });
    $(document).on('input', '#srSearch', function () { loadList(1); });

    /* ---------- مودال انتخاب فاکتور فروش قطعی ---------- */
    function openInvoiceModal() {
        $('#srInvoiceTable tbody').html('<tr><td colspan="5">در حال بارگذاری...</td></tr>');
        loadEligible('');
        openModal('srInvoiceModal');
        $('#srInvoiceSearch').val('').focus();
    }
    function loadEligible(q) {
        $.getJSON('assets/php/sales_return_invoices.php?search=' + encodeURIComponent(q || '')).done(function (res) {
            var rows = res.data || [];
            var html = '';
            if (!rows.length) html = '<tr><td colspan="5">فاکتور فروش قطعی یافت نشد.</td></tr>';
            rows.forEach(function (r) {
                var st = ({ paid: 'پرداخت شده', unpaid: 'پرداخت نشده', partial: 'جزئی' })[r.payment_status] || r.payment_status;
                html += '<tr>'
                    + '<td dir="ltr">' + esc(r.invoice_number) + '</td>'
                    + '<td>' + esc(r.customer_name || '—') + ' <span class="sr-returnable-hint">' + esc(r.customer_phone || '') + '</span></td>'
                    + '<td>' + esc(r.invoice_date_shamsi) + '</td>'
                    + '<td class="sr-money-cell">' + splitNum(r.payable_amount) + ' ریال <span class="sr-returnable-hint">' + esc(st) + '</span></td>'
                    + '<td><button type="button" class="button-secondary small sr-invoice-pick" data-id="' + escAttr(r.id) + '">انتخاب</button></td>'
                    + '</tr>';
            });
            $('#srInvoiceTable tbody').html(html);
        }).fail(function () { $('#srInvoiceTable tbody').html('<tr><td colspan="5">خطا در بارگذاری فاکتورها.</td></tr>'); });
    }
    var srInvoiceSearchTimer = null;
    $(document).on('input', '#srInvoiceSearch', function () {
        var q = $(this).val();
        clearTimeout(srInvoiceSearchTimer);
        srInvoiceSearchTimer = setTimeout(function () { loadEligible(q); }, 150);
    });
    $(document).on('click', '.sr-invoice-pick', function () {
        var invId = $(this).data('id');
        closeModal('srInvoiceModal');
        currentReturnId = '';
        fetchCtx(invId, 0, null);
    });

    /* ---------- بارگذاری بافت فاکتور از سرور ---------- */
    function fetchCtx(invoiceId, exclude, prefilled) {
        var url = 'assets/php/sales_return_items.php?id=' + encodeURIComponent(invoiceId);
        if (exclude) url += '&exclude=' + encodeURIComponent(exclude);
        $.getJSON(url).done(function (res) {
            if (!res.success) { alert(res.message || 'خطا در دریافت فاکتور.'); return; }
            currentInvoiceId = invoiceId;
            currentCtx = res.data;
            openForm(res.data, prefilled);
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    }

    /* ---------- فرم ساخت / ویرایش برگشت ---------- */
    function openForm(ctx, prefilled) {
        var inv = ctx.invoice || {};
        var isEdit = !!currentReturnId;
        var items = ctx.items || [];
        var hasReturnable = items.some(function (it) { return Number(it.returnable_base) > 0; });

        var html = '<div class="invoice-form sr-form" id="srForm">'
            + '<div class="page-actions">'
            + '<button type="button" class="button-secondary" id="srBackToList">بازگشت به برگشت‌ها</button>'
            + '<h2 style="margin:0;">' + (isEdit ? 'ویرایش برگشت فروش' : 'برگشت فروش جدید') + '</h2>'
            + '</div>'
            + '<input type="hidden" id="srReturnIdHidden" value="' + escAttr(currentReturnId) + '">'
            + '<div class="form-grid header-section">'
            + '<div class="form-row"><label>شماره برگشت</label><input id="srNumber" type="text" readonly value="' + escAttr(prefilled && prefilled.return_number ? prefilled.return_number : 'به صورت خودکار') + '"></div>'
            + '<div class="form-row"><label>فاکتور فروش</label>'
            + '<div class="searchable-select"><input type="search" id="srInvPick" readonly placeholder="انتخاب فاکتور فروش..." value="' + escAttr(inv.invoice_number || '') + '"><span class="party-id" id="srInvId">' + escAttr(inv.id || '') + '</span></div></div>'
            + '<div class="form-row"><label>مشتری</label><input id="srCustomerName" type="text" readonly value="' + escAttr(inv.customer_name || '') + '"></div>'
            + '<div class="form-row"><label>تاریخ برگشت (شمسی)</label>'
            + '<input type="text" id="srDateDisplay" class="inv-date-display" readonly="readonly" autocomplete="off" placeholder="۱۴۰۵/۰۵/۲۳" style="direction:ltr;text-align:center;">'
            + '<input type="hidden" id="srDate" value="' + escAttr(prefilled && prefilled.return_date ? prefilled.return_date : todayISO()) + '">'
            + '</div>'
            + '</div>'
            + '<div class="products-section">'
            + '<div class="table-wrapper"><table class="action-table sr-items-table" id="srItemsTable">'
            + '<thead><tr><th>کالا</th><th>واحد</th><th>فروخته‌شده</th><th>برگشت قبل</th><th>قابل برگشت</th><th>قیمت واحد</th><th>تخفیف</th><th>تعداد برگشتی</th><th>مبلغ ردیف</th><th></th></tr></thead>'
            + '<tbody></tbody></table></div>'
            + (hasReturnable ? '' : '<p class="sr-totals-note">برای این فاکتور موردی برای برگشت باقی نمانده است.</p>')
            + '</div>'
            + '<div class="calculation-section form-grid">'
            + '<div class="form-row"><label>جمع ردیف‌ها</label><div id="srSubtotal" class="money-cell">0</div></div>'
            + '<div class="form-row"><label>تخفیف سند (سرور)</label><div id="srDocDiscount" class="money-cell">0</div></div>'
            + '<div class="form-row"><label>مالیات</label><div id="srTax" class="money-cell">0</div></div>'
            + '<div class="form-row"><label>مبلغ برگشتی</label><div id="srPayable" class="money-cell strong">0</div></div>'
            + '</div>'
            + '<div class="form-row"><label>یادداشت</label><textarea id="srNote" rows="2">' + esc(prefilled && prefilled.note ? prefilled.note : '') + '</textarea></div>'
            + '<div class="form-actions">'
            + '<button type="button" class="button-secondary" id="srFormCancel">لغو</button>'
            + '<button type="button" class="button" id="srFormSave">' + (isEdit ? 'ذخیره ویرایش' : 'ثبت برگشت فروش') + '</button>'
            + '</div>'
            + '</div>';

        $('#srFormView').html(html);
        $('#srListView').hide();
        $('#srDetailView').hide();
        $('#srFormView').show();

        // دکمه انتخاب فاکتور فقط برای ساخت؛ در ویرایش فاکتور قفل است
        if (!isEdit) {
            $('#srInvPick').prop('readonly', false).css('cursor', 'pointer');
        }

        if (prefilled && prefilled.items) {
            var qtyById = {};
            prefilled.items.forEach(function (ri) { qtyById[ri.invoice_item_id] = ri.quantity; });
            items.forEach(function (it) { addItemRow(it, qtyById[it.id] != null ? qtyById[it.id] : ''); });
        } else {
            items.forEach(function (it) { addItemRow(it, ''); });
        }
        initSrDatePicker();
        recalcs();
    }

    function addItemRow(it, qtyVal) {
        var factor = Number(it.conversion_factor) || 1;
        var hint = 'مبنا: ' + splitNum(it.returnable_base) + (factor === 1 ? '' : '/' + factor);
        var unitName = it.unit_name || it.unit || '';
        var tr = '<tr data-iid="' + escAttr(it.id) + '" data-pid="' + escAttr(it.product_id) + '" data-unit-id="' + escAttr(it.unit_id != null ? it.unit_id : '') + '"'
            + ' data-factor="' + escAttr(it.conversion_factor != null ? it.conversion_factor : 1) + '"'
            + ' data-sold-qty="' + escAttr(it.quantity) + '" data-price="' + escAttr(it.unit_price) + '"'
            + ' data-disc="' + escAttr(it.discount) + '" data-returnable-base="' + escAttr(it.returnable_base) + '">'
            + '<td>' + esc((it.product_code ? it.product_code + ' — ' : '') + (it.product_name || '')) + '</td>'
            + '<td>' + esc(unitName) + '</td>'
            + '<td>' + splitNum(it.quantity) + '</td>'
            + '<td>' + splitNum(it.returned_display != null ? it.returned_display : '0') + '</td>'
            + '<td>' + splitNum(it.returnable_display) + '<span class="sr-returnable-hint">' + esc(hint) + '</span></td>'
            + '<td>' + splitNum(it.unit_price) + '</td>'
            + '<td class="sr-line-disc">0</td>'
            + '<td><input type="number" class="sr-qty sr-qty-input" min="0" step="0.01" value="' + escAttr(qtyVal) + '" placeholder="0"></td>'
            + '<td class="sr-line">0</td>'
            + '<td><button type="button" class="button-secondary small sr-remove">حذف</button></td>'
            + '</tr>';
        $('#srItemsTable tbody').append(tr);
    }

    function lineFields($row) {
        var qty = parseFloat($('.sr-qty', $row).val()) || 0;
        var price = parseFloat($row.data('price')) || 0;
        var disc = parseFloat($row.data('disc')) || 0;
        var sold = parseFloat($row.data('sold-qty')) || 0;
        var allocDisc = (disc && sold) ? Math.round(disc * qty / sold) : 0;
        if (allocDisc < 0) allocDisc = 0;
        var line = Math.max(0, Math.round(qty * price) - allocDisc);
        return { qty: qty, price: price, allocDisc: allocDisc, line: line };
    }

    function recalcs() {
        var subtotal = 0;
        var invDisc = 0, invSubtotal = 0;
        if (currentCtx && currentCtx.invoice) {
            invDisc = parseFloat(currentCtx.invoice.discount) || 0;
            invSubtotal = parseFloat(currentCtx.invoice.subtotal) || 0;
        }
        $('#srItemsTable tbody tr').each(function () {
            var f = lineFields($(this));
            $('.sr-line-disc', this).text(f.allocDisc ? splitNum(f.allocDisc) : '0');
            $('.sr-line', this).text(splitNum(f.line) + ' ریال');
            subtotal += f.line;
        });
        var docDisc = (invDisc && invSubtotal && subtotal) ? Math.round(invDisc * subtotal / invSubtotal) : 0;
        var taxable = Math.max(0, subtotal - docDisc);
        var tax = (taxEnabled && taxRate > 0) ? Math.round(taxable * taxRate / 100) : 0;
        if (currentCtx && currentCtx.invoice && Number(currentCtx.invoice.tax_amount) > 0) {
            var invRate = parseFloat(currentCtx.invoice.tax_rate) || 0;
            tax = (invRate > 0) ? Math.round(taxable * invRate / 100) : 0;
        }
        var payable = taxable + tax;
        $('#srSubtotal').text(splitNum(subtotal) + ' ریال');
        $('#srDocDiscount').text(docDisc ? splitNum(docDisc) + ' ریال' : '0');
        $('#srTax').text(splitNum(tax) + ' ریال');
        $('#srPayable').text(splitNum(payable) + ' ریال');
    }

    /* ---------- بارگذاری نرخ مالیات برای پیش‌نمایش (سرور مرجع نهایی است) ---------- */
    function loadTaxForCalc() {
        $.getJSON('assets/php/tax_settings.php').done(function (res) {
            var s = (res.data && res.data.settings) || {};
            taxEnabled = !!Number(s.tax_enabled);
            taxRate = parseFloat(s.tax_rate) || 0;
            if ($('#srFormView').is(':visible')) recalcs();
        });
    }

    function syncHiddenFromDisplay() {
        var jalaliVal = String($('#srDateDisplay').val() || '').trim();
        if (!jalaliVal) return;
        var greg = jalaliStrToGregInput(jalaliVal);
        if (greg) $('#srDate').val(greg);
    }
    function initSrDatePicker() {
        if (window.kamaDatepicker) {
            window.kamaDatepicker('srDateDisplay', {
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
            });
        }
        var h = $('#srDate').val();
        if (h) $('#srDateDisplay').val(gregInputToJalaliStr(h));
        $('#srDateDisplay').off('change.srDate').on('change.srDate', function () { syncHiddenFromDisplay(); });
        syncHiddenFromDisplay();
    }

    /* ---------- رویدادهای فرم ---------- */
    $(document).on('click', '#srBackToList, #srFormCancel', function () { showSrList(); });
    $(document).on('click', '#srNewReturn', function () { openInvoiceModal(); });
    $(document).on('click', '#srInvPick', function () {
        if (currentReturnId) return; // در ویرایش فاکتور قفل است
        openInvoiceModal();
    });
    $(document).on('input', '#srItemsTable .sr-qty', function () { recalcs(); });
    $(document).on('click', '.sr-remove', function () { $(this).closest('tr').remove(); recalcs(); });

    function showSrList() {
        $('#srFormView').hide();
        $('#srDetailView').hide();
        $('#srListView').show();
        loadList(currentPage);
    }

    /* ---------- ذخیره (Create / Edit) ---------- */
    function submitForm() {
        var items = [];
        $('#srItemsTable tbody tr').each(function () {
            var qtyText = String($('.sr-qty', this).val() || '').trim();
            var qty = parseFloat(qtyText) || 0;
            if (qty <= 0) return;
            var $row = $(this);
            var maxDisplay = parseFloat($row.data('returnable-base')) / (parseFloat($row.data('factor')) || 1);
            if (qty > maxDisplay + 0.000001) {
                alert('تعداد برگشتی «' + $row.find('td:first').text() + '» بیشتر از مقدار قابل برگشت است.');
                return;
            }
            items.push({
                invoice_item_id: $row.data('iid'),
                product_id: $row.data('pid'),
                unit_id: $row.data('unit-id'),
                quantity: qtyText
            });
        });
        if (!items.length) { alert('حداقل یک ردیف با تعداد مثبت باید وارد شود.'); return; }
        var dateIso = String($('#srDate').val() || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(dateIso)) { alert('تاریخ برگشت نامعتبر است.'); return; }
        var invoiceId = String($('#srInvId').text() || '').trim();
        if (!invoiceId) { alert('فاکتور فروش انتخاب نشده است.'); return; }

        var payload = {
            csrf_token: csrf,
            return_id: currentReturnId,
            invoice_id: invoiceId,
            return_date: dateIso,
            note: String($('#srNote').val() || ''),
            items: JSON.stringify(items)
        };
        var $btn = $('#srFormSave');
        $btn.prop('disabled', true).text('در حال ذخیره...');
        $.post('assets/php/sales_return_save.php', payload).done(function (res) {
            if (res.success) { alert(res.message); showSrList(); }
            else { alert(res.message || 'خطا در ذخیره.'); $btn.prop('disabled', false).text('ذخیره'); }
        }).fail(function () {
            alert('خطا در ارتباط با سرور.');
            $btn.prop('disabled', false).text('ذخیره');
        });
    }
    $(document).on('click', '#srFormSave', submitForm);

    /* ---------- مشاهده جزئیات ---------- */
    function openDetail(returnId) {
        $.getJSON('assets/php/sales_return_get.php?id=' + returnId).done(function (res) {
            if (!res.success) { alert(res.message || 'خطا در دریافت سند.'); return; }
            var r = res.data;
            var cust = r.customer || {};
            var itemsHtml = '';
            var rowNo = 0;
            (r.items || []).forEach(function (it) {
                rowNo++;
                itemsHtml += '<tr>'
                    + '<td>' + rowNo + '</td>'
                    + '<td dir="ltr">' + esc(it.product_code) + '</td>'
                    + '<td>' + esc(it.product_name) + '</td>'
                    + '<td>' + splitNum(it.quantity) + ' ' + esc(it.unit_name || '') + '</td>'
                    + '<td>' + splitNum(it.unit_price) + '</td>'
                    + '<td>' + splitNum(it.discount) + '</td>'
                    + '<td>' + splitNum(it.line_total) + '</td>'
                    + '</tr>';
            });
            if (!itemsHtml) itemsHtml = '<tr><td colspan="7">—</td></tr>';
            var taxable = Number(r.subtotal) - Number(r.discount);
            var html = '<div class="sr-detail-grid">'
                + '<div class="page-actions">'
                + '<button type="button" class="button-secondary" id="srDetailBack">بازگشت به برگشت‌ها</button>'
                + '<h2 style="margin:0;">جزئیات برگشت فروش — <span dir="ltr">' + esc(r.return_number) + '</span></h2>'
                + '<button type="button" class="button-secondary sr-pdf" data-id="' + escAttr(r.id) + '" data-number="' + escAttr(r.return_number) + '">چاپ سند</button>'
                + '</div>'
                + '<div class="form-grid header-section">'
                + '<div class="form-row"><label>فاکتور اصلی</label><div class="money-cell" dir="ltr">' + esc(r.invoice_number) + '</div></div>'
                + '<div class="form-row"><label>مشتری</label><div class="money-cell">' + esc(cust.name || '—') + '</div></div>'
                + '<div class="form-row"><label>تاریخ برگشت</label><div class="money-cell">' + esc(r.return_date_shamsi) + '</div></div>'
                + '<div class="form-row"><label>مبلغ فاکتور اصلی</label><div class="money-cell">' + splitNum(r.invoice_payable) + ' ریال</div></div>'
                + '</div>'
                + '<h3>اقلام برگشتی</h3>'
                + '<div class="table-wrapper"><table class="action-table sr-detail-items">'
                + '<thead><tr><th>ردیف</th><th>کد</th><th>نام کالا</th><th>تعداد</th><th>قیمت واحد</th><th>تخفیف</th><th>مبلغ</th></tr></thead>'
                + '<tbody>' + itemsHtml + '</tbody></table></div>'
                + '<div class="calculation-section form-grid">'
                + '<div class="form-row"><label>جمع ردیف‌ها</label><div class="money-cell">' + splitNum(r.subtotal) + ' ریال</div></div>'
                + '<div class="form-row"><label>تخفیف سند</label><div class="money-cell">' + splitNum(r.discount) + ' ریال</div></div>'
                + '<div class="form-row"><label>مالیات</label><div class="money-cell">' + splitNum(r.tax_amount) + ' ریال</div></div>'
                + '<div class="form-row"><label>مبلغ برگشتی</label><div class="money-cell strong">' + splitNum(r.payable_amount) + ' ریال</div></div>'
                + '</div>'
                + (r.note ? '<div class="form-row"><label>یادداشت</label><div>' + esc(r.note) + '</div></div>' : '')
                + '</div>';
            $('#srDetailView').html(html);
            $('#srFormView').hide();
            $('#srListView').hide();
            $('#srDetailView').show();
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    }
    $(document).on('click', '#srDetailBack', function () { showSrList(); });
    $(document).on('click', '.sr-view', function () { openDetail($(this).data('id')); });

    /* ---------- ویرایش ---------- */
    $(document).on('click', '.sr-edit', function () {
        var id = $(this).data('id');
        $.getJSON('assets/php/sales_return_get.php?id=' + id).done(function (res) {
            if (!res.success) { alert(res.message || 'خطا در دریافت سند.'); return; }
            var r = res.data;
            currentReturnId = String(r.id);
            // بافت فاکتور با حذف اقلام همین سند (برای محاسبه صحیح «قابل برگشت» در ویرایش)
            fetchCtx(r.invoice_id, r.id, r);
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    /* ---------- حذف ---------- */
    $(document).on('click', '.sr-delete', function () {
        var id = $(this).data('id');
        if (!confirm('آیا از حذف این برگشت فروش مطمئن هستید؟\nبا حذف برگشت، موجودی کالاها به‌روزرسانی (کاهش) می‌شود.')) return;
        $.post('assets/php/sales_return_delete.php', { csrf_token: csrf, id: id }).done(function (res) {
            if (res.success) { alert(res.message); loadList(currentPage); }
            else alert(res.message || 'خطا در حذف.');
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    /* ---------- PDF / چاپ سند برگشت ---------- */
    $(document).on('click', '.sr-pdf', function () {
        if (!$('#srPdfModal').length) { alert('مودال PDF در دسترس نیست.'); return; }
        var id = $(this).data('id');
        var number = $(this).data('number');
        var base = 'assets/php/sales_return_pdf.php?id=' + encodeURIComponent(id);
        $('#srPdfNumber').text(number);
        $('#srPdfView').attr('href', base + '&mode=view');
        $('#srPdfView2').attr('href', base + '&mode=view');
        $('#srPdfDownload').attr('href', base + '&mode=download');
        openModal('srPdfModal');
    });

    /* ---------- راه‌اندازی ---------- */
    loadList(1);
    loadTaxForCalc();
});