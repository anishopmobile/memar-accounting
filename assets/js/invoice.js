/* ============================================================
 * invoice.js — ماژول فاکتور فروش/خرید (Frontend)
 * سازگار با ساختار فعلی پروژه (jQuery + AJAX + JSON)
 * ========================================================== */
$(function () {
    var mode = String($('#invoiceMode').val() || '');
    if (mode !== 'sales' && mode !== 'purchase') return; // فقط صفحات فاکتور

    var csrf = String($('#invoiceCsrfToken').val() || '');
    var isSales = mode === 'sales';
    var partyLabel = isSales ? 'مشتری' : 'تامین‌کننده';
    var partyType = isSales ? 'customer' : 'supplier';
    var partyField = isSales ? 'customer_id' : 'supplier_id';
    var priceKey = isSales ? 'sale_price' : 'purchase_price';
    var typeGroup = isSales ? 'sales' : 'purchase';
    var currentInvoiceId = '';
    var clientToken = '';

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function splitNum(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
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
    function uuid() {
        if (window.crypto && window.crypto.getRandomValues) {
            var b = new Uint8Array(16);
            window.crypto.getRandomValues(b);
            b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
            var h = '';
            for (var i = 0; i < 16; i++) h += pad2(b[i].toString(16));
            return h.substr(0, 8) + '-' + h.substr(8, 4) + '-' + h.substr(12, 4) + '-' + h.substr(16, 4) + '-' + h.substr(20);
        }
        return 'tok-' + Date.now() + '-' + Math.floor(Math.random() * 1e9);
    }

    var U1 = { 0: '', 1: 'یک', 2: 'دو', 3: 'سه', 4: 'چهار', 5: 'پنج', 6: 'شش', 7: 'هفت', 8: 'هشت', 9: 'نه', 10: 'ده', 11: 'یازده', 12: 'دوازده', 13: 'سیزده', 14: 'چهارده', 15: 'پانزده', 16: 'شانزده', 17: 'هفده', 18: 'هجده', 19: 'نوزده' };
    var TENS = { 2: 'بیست', 3: 'سی', 4: 'چهل', 5: 'پنجاه', 6: 'شصت', 7: 'هفتاد', 8: 'هشتاد', 9: 'نود' };
    var HUND = { 1: 'صد', 2: 'دویست', 3: 'سیصد', 4: 'چهارصد', 5: 'پانصد', 6: 'ششصد', 7: 'هفتصد', 8: 'هشتصد', 9: 'نهصد' };
    var SCALE = ['', 'هزار', 'میلیون', 'میلیارد'];
    function threeWords(n) {
        var s = '', h = Math.floor(n / 100), r = n % 100;
        if (h) s += HUND[h];
        if (r) { if (s) s += ' و '; if (r < 20) s += U1[r]; else { s += TENS[Math.floor(r / 10)]; if (r % 10) s += ' و ' + U1[r % 10]; } }
        return s;
    }
    function numToWords(num) {
        if (!num) return 'صفر';
        var neg = false;
        if (num < 0) { neg = true; num = -num; }
        var parts = [], scale = 0;
        while (num > 0) {
            var chunk = num % 1000;
            if (chunk) parts.unshift(threeWords(chunk) + (SCALE[scale] ? ' ' + SCALE[scale] : ''));
            num = Math.floor(num / 1000);
            scale++;
        }
        var out = parts.join(' و ');
        return neg ? 'منفی ' + out : out;
    }
    function amountToWords(n) { var s = numToWords(Math.floor(n)); return s ? s + ' ریال' : ''; }

    /* ---------- تبدیل تاریخ جلالی ↔ میلادی (سازگار با jdf.php پروژه) ---------- */
    var INV_GDM = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    // میلادی → شمسی (همان الگوریتم jdf.php که تست شد)
    function gregorianToJalali(gy, gm, gd) {
        var jyBase = (gy > 1600) ? 979 : 0;
        var gy2 = gy - (gy > 1600 ? 1600 : 621);
        var gy3 = (gm > 2) ? gy2 + 1 : gy2;
        var days = 365 * gy2 + Math.floor((gy3 + 3) / 4) - Math.floor((gy3 + 99) / 100) + Math.floor((gy3 + 399) / 400) - 80 + gd + INV_GDM[gm - 1];
        var jy = jyBase;
        jy += 33 * Math.floor(days / 12053);
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
    // شمسی → میلادی (جستجوی دودویی دقیق روی g2j — کاملاً سازگار/تست‌شده)
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
    function gregInputToJalaliStr(gregISO) {
        var p = String(gregISO || '').split('-');
        if (p.length !== 3) return '';
        var j = gregorianToJalali(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2], 10));
        return j[0] + '/' + pad2(j[1]) + '/' + pad2(j[2]);
    }
    function jalaliStrToGregInput(jalaliStr) {
        var m = String(jalaliStr || '').trim().match(/^(\d{4,})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
        if (!m) return '';
        var g = jalaliToGregorian(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10));
        return g ? (g[0] + '-' + pad2(g[1]) + '-' + pad2(g[2])) : '';
    }

    function openModal(id) { $('#' + id).addClass('open'); $('body').addClass('modal-open'); }
    function closeModal(id) { $('#' + id).removeClass('open'); $('body').removeClass('modal-open'); }
    $(document).on('click', '[data-close]', function () { closeModal($(this).data('close')); });

    // ---------- لیست فاکتورها (صفحه‌بندی سمت سرور؛ حداکثر ۱۵ فاکتور در هر صفحه) ----------
    var currentPage = 1;
    function loadInvoices(page) {
        currentPage = (page && page >= 1) ? page : 1;
        var search = encodeURIComponent($('#invoiceSearch').val() || '');
        var status = encodeURIComponent($('#invoiceStatusFilter').val() || '');
        $.getJSON('assets/php/invoice_list.php?type_group=' + typeGroup + '&search=' + search + '&payment_status=' + status + '&page=' + currentPage)
            .done(function (res) { renderInvoiceList(res.data); })
            .fail(function () { $('#invoicesTable tbody').html('<tr><td colspan="7" class="empty-state">خطا در بارگذاری فاکتورها.</td></tr>'); });
    }
    function renderInvoiceList(data) {
        var rows = (data && data.rows) || [];
        var total = data ? (data.total || 0) : 0;
        var page = (data && data.page) || currentPage;
        currentPage = page;
        var perPage = (data && data.per_page) || 15;
        var tbody = $('#invoicesTable tbody');
        if (!rows.length) {
            tbody.html('<tr><td colspan="7" class="empty-state">فاکتوری یافت نشد.</td></tr>');
            renderInvoicePagination({ total: 0, page: 1, perPage: perPage });
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            var isPro = /proforma/.test(r.type);
            var typeLbl = isPro ? 'پیش فاکتور' : 'فاکتور';
            var stLbl = ({ paid: 'پرداخت شده', unpaid: 'پرداخت نشده', partial: 'جزئی' })[r.payment_status] || r.payment_status;
            html += '<tr>'
                + '<td>' + esc(r.invoice_number) + '</td>'
                + '<td>' + esc(r.party_name) + '</td>'
                + '<td>' + esc(r.invoice_date_shamsi) + '</td>'
                + '<td>' + typeLbl + '</td>'
                + '<td>' + stLbl + '</td>'
                + '<td>' + fmtRial(r.payable_amount) + ' ریال</td>'
                + '<td class="invoice-actions">'
                + '<button class="button-secondary small invoice-edit" data-id="' + r.id + '" type="button">مشاهده/ویرایش</button> '
                + (isPro ? '<button class="button-secondary small invoice-convert" data-id="' + r.id + '" type="button">تبدیل به قطعی</button> ' : '')
                + (isSales ? '<button class="button-secondary small invoice-pdf" data-id="' + r.id + '" data-number="' + escAttr(r.invoice_number) + '" type="button">تولید و چاپ فاکتور</button> ' : '')
                + (!isSales ? '<button class="button-secondary small invoice-pdf-purchase" data-id="' + r.id + '" data-number="' + escAttr(r.invoice_number) + '" type="button">تولید و چاپ فاکتور</button> ' : '')
                + '<button class="button-secondary small invoice-delete" data-id="' + r.id + '" type="button">حذف</button>'
                + '</td></tr>';
        });
        tbody.html(html);
        renderInvoicePagination({ total: total, page: page, perPage: perPage });
    }
    function invoicePagerPages(page, pages) {
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
    function renderInvoicePagination(meta) {
        var $wrap = $('#invoicePaginationWrap');
        if (!$wrap.length) return;
        var page = (meta && meta.page) || 1;
        var perPage = (meta && meta.perPage) || 15;
        var total = (meta && meta.total) || 0;
        var totalPages = Math.max(1, Math.ceil(total / perPage));
        if (totalPages <= 1) { $wrap.hide(); return; }
        $wrap.show();
        var rangeStart = total === 0 ? 0 : (page - 1) * perPage + 1;
        var rangeEnd = Math.min(page * perPage, total);
        var $info = $('#invoicePaginationInfo');
        if ($info.length) {
            $info.text('نمایش ' + rangeStart + ' تا ' + rangeEnd + ' از ' + total + ' فاکتور');
        }
        var html = '<button type="button" class="pagination-button invoice-page-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>صفحه قبل</button> ';
        invoicePagerPages(page, totalPages).forEach(function (p) {
            if (p === '...') { html += '<span class="pagination-ellipsis">…</span> '; return; }
            if (p === page) { html += '<span class="pagination-current">' + p + '</span> '; }
            else { html += '<button type="button" class="pagination-button invoice-page-btn" data-page="' + p + '">' + p + '</button> '; }
        });
        html += '<button type="button" class="pagination-button invoice-page-btn" data-page="' + (page + 1) + '"' + (page >= totalPages ? ' disabled' : '') + '>صفحه بعد</button>';
        $('#invoicePagination').html(html);
    }
    $(document).on('click', '.invoice-page-btn', function () {
        if ($(this).prop('disabled')) return;
        var p = parseInt($(this).data('page'), 10);
        if (p >= 1) loadInvoices(p);
    });

    $(document).on('click', '#invoiceRefresh', function (e) { e.preventDefault(); loadInvoices(currentPage); });
    $(document).on('click', '.invoice-new', function (e) { e.preventDefault(); openForm('', $(this).data('type')); });
    $(document).on('input', '#invoiceSearch', function () { loadInvoices(1); });
    $(document).on('change', '#invoiceStatusFilter', function () { loadInvoices(1); });

    // --------------------- دکمه‌های ردیف (delegate) ---------------------
    $(document).on('click', '.invoice-edit', function () {
        var id = $(this).data('id');
        $.getJSON('assets/php/invoice_get.php?id=' + id).done(function (res) {
            if (res.success) openForm(res.data, res.data.type);
            else alert(res.message || 'خطا در دریافت فاکتور.');
        });
    });
    $(document).on('click', '.invoice-delete', function () {
        var id = $(this).data('id');
        if (!confirm('آیا از حذف این فاکتور مطمئن هستید؟\nبا حذف فاکتور، موجودی کالاهای آن به‌روزرسانی می‌شود.')) return;
        $.post('assets/php/invoice_delete.php', { csrf_token: csrf, id: id }).done(function (res) {
            if (res.success) { alert(res.message); loadInvoices(currentPage); } else alert(res.message || 'خطا در حذف.');
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });
    $(document).on('click', '.invoice-convert', function () {
        var id = $(this).data('id');
        if (!confirm('تبدیل این پیش‌فاکتور به فاکتور قطعی؟\nموجودی کالاها یک بار به‌روزرسانی خواهد شد.')) return;
        $.post('assets/php/invoice_convert.php', { csrf_token: csrf, id: id }).done(function (res) {
            if (res.success) { alert(res.message); loadInvoices(currentPage); } else alert(res.message || 'خطا در تبدیل.');
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    // ---------- PDF / چاپ فاکتور (فقط فاکتور فروش) ----------
    $(document).on('click', '.invoice-pdf', function () {
        if (!$('#invoicePdfModal').length) { alert('مودال PDF در دسترس نیست.'); return; }
        var id = $(this).data('id');
        var number = $(this).data('number');
        var base = 'assets/php/invoice_pdf.php?id=' + encodeURIComponent(id);
        $('#invPdfNumber').text(number);
        $('#invoicePdfView').attr('href', base + '&mode=view');
        $('#invoicePdfPrint').attr('href', base + '&mode=view');
        $('#invoicePdfDownload').attr('href', base + '&mode=download');
        openModal('invoicePdfModal');
    });
    // چاپ باید همان سند PDF تولیدشده را باز کند؛ نه صفحه حسابداری را.
    // چاپ نهایی توسط viewer مرورگر (از سند PDF) انجام می‌شود.
    $(document).on('click', '#invoicePdfPrint', function (e) {
        e.preventDefault();
        var href = $(this).attr('href');
        if (!href) return;
        window.open(href, '_blank');
    });

    // ---------- PDF / چاپ فاکتور خرید ----------
    $(document).on('click', '.invoice-pdf-purchase', function () {
        if (!$('#invoicePdfModal').length) { alert('مودال PDF در دسترس نیست.'); return; }
        var id = $(this).data('id');
        var number = $(this).data('number');
        var base = 'assets/php/invoice_pdf_purchase.php?id=' + encodeURIComponent(id);
        $('#invPdfNumber').text(number);
        $('#invoicePdfView').attr('href', base + '&mode=view');
        $('#invoicePdfPrint').attr('href', base + '&mode=view');
        $('#invoicePdfDownload').attr('href', base + '&mode=download');
        openModal('invoicePdfModal');
    });

    // ---------- فرم فاکتور ----------
    function openForm(data, type) {
        currentInvoiceId = data && data.id ? String(data.id) : '';
        clientToken = currentInvoiceId ? '' : uuid();
        var isEdit = !!currentInvoiceId;
        var typeVal = type || (isSales ? 'sales_invoice' : 'purchase_invoice');

        var paymentTypes = '<option value="cash">نقدی</option>'
            + '<option value="pos" selected>کارت خوان</option>'
            + '<option value="bank_transfer">واریز وجه</option>';
        var statuses = '<option value="unpaid">پرداخت نشده</option>'
            + '<option value="paid" selected>پرداخت شده</option>'
            + '<option value="partial">پرداخت جزئی</option>';
        var typeOpts = isSales
            ? '<option value="sales_invoice">فاکتور فروش</option><option value="sales_proforma">پیش فاکتور فروش</option>'
            : '<option value="purchase_invoice">فاکتور خرید</option><option value="purchase_proforma">پیش فاکتور خرید</option>';

        var html = '<div class="invoice-form" id="invoiceForm">'
            + '<div class="page-actions">'
            + '<button type="button" class="button-secondary" id="invoiceBackToList">بازگشت به فاکتورها</button>'
            + '<h2 style="margin:0;">' + (isEdit ? 'ویرایش فاکتور' : 'ایجاد فاکتور') + '</h2>'
            + '</div>'
            + '<input type="hidden" id="invId" value="' + escAttr(currentInvoiceId) + '">'
            + '<div class="form-grid header-section">'
            + '<div class="form-row"><label>شماره فاکتور</label><input id="invNumber" type="text" readonly value="' + escAttr(data ? data.invoice_number : (isEdit ? '' : 'به صورت خودکار')) + '"></div>'
            + '<div class="form-row"><label>نوع</label><select id="invType">' + typeOpts.replace('value="' + typeVal + '"', 'value="' + typeVal + '" selected') + '</select></div>'
            + '<div class="form-row"><label>نوع پرداخت</label><select id="invPaymentType">' + paymentTypes + '</select></div>'
            + '<div class="form-row"><label>وضعیت پرداخت</label><select id="invPaymentStatus">' + statuses + '</select></div>'
            + '<div class="form-row"><label>تاریخ (شمسی)</label>'
            + '<input type="text" id="invDateDisplay" class="inv-date-display" readonly="readonly" autocomplete="off" placeholder="۱۴۰۵/۰۵/۲۳" style="direction:ltr;text-align:center;">'
            + '<input type="hidden" id="invDate" name="invoice_date" value="' + escAttr(data ? data.invoice_date : todayISO()) + '">'
            + '</div>'
            + '<div class="form-row"><label>' + partyLabel + '</label>'
            + '<div class="searchable-select"><input type="search" id="invPickParty" readonly placeholder="انتخاب ' + partyLabel + '..." value="' + escAttr(data ? data.party_name : (isSales ? 'مشتری متفرقه' : '')) + '"><span class="party-id" id="invPartyId">' + escAttr(data ? (isSales ? data.customer_id : data.supplier_id) : (isSales ? '1' : '')) + '</span></div></div>'
            + '</div>'
            + '<div class="products-section">'
            + '<div class="page-actions"><button type="button" class="button" id="invAddProduct">+ افزودن کالا</button>' + (isSales ? ' <button type="button" class="button-secondary" id="invAddService">+ افزودن خدمت</button>' : '') + '</div>'
            + '<div class="table-wrapper"><table class="action-table" id="invItemsTable">'
            + '<thead><tr><th>کالا</th><th>واحد</th><th>قیمت واحد</th><th>تعداد</th><th>تخفیف</th><th>مبلغ ردیف</th><th></th></tr></thead>'
            + '<tbody></tbody></table></div>'
            + '</div>'
            + '<div class="calculation-section form-grid">'
            + '<div class="form-row"><label>جمع ردیف‌ها</label><div id="invSubtotal" class="money-cell">0</div></div>'
            + '<div class="form-row"><label>تخفیف فاکتور</label><input id="invDiscount" type="number" min="0" step="1" value="' + escAttr(data ? data.discount : '0') + '"></div>'
            + '<div class="form-row"><label>مبلغ مشمول مالیات</label><div id="invTaxable" class="money-cell">0</div></div>'
            + '<div class="form-row"><label>مالیات</label><div id="invTax" class="money-cell">0</div></div>'
            + '<div class="form-row"><label>مبلغ قابل پرداخت</label><div id="invPayable" class="money-cell strong">0</div></div>'
            + '</div>'
            + '<div class="form-row"><label>مبلغ به حروف</label><div id="invWords" class="words-cell"></div></div>'
            + '<div class="form-row"><label>یادداشت</label><textarea id="invNote" rows="2">' + esc(data ? data.note : '') + '</textarea></div>'
            + '<div class="form-actions">'
            + '<button type="button" class="button-secondary" id="invoiceFormCancel">لغو</button>'
            + '<button type="button" class="button" id="invoiceFormSave">ذخیره فاکتور</button>'
            + '</div>'
            + '</div>';

        $('#invoiceFormView').html(html);
        $('#invoiceListView').hide();
        $('#invoiceFormView').show();

        if (data && data.payment_type) $('#invPaymentType').val(data.payment_type);
        if (data && data.payment_status) $('#invPaymentStatus').val(data.payment_status);
        if (data && data.items) {
            data.items.forEach(function (it) {
                addItemRow(it.product_id, it.product_name, it.unit_price, it.quantity, it.discount, it.product_code, it.units || [], it.unit_id);
            });
        }
        recalcs();
        bindFormEvents();
        initDatePicker();
    }
    function showList(page) {
        var clean = cleanUrlForList();
        if (clean !== null) history.replaceState(null, '', clean);
        $('#invoiceFormView').hide();
        $('#invoiceListView').show();
        loadInvoices(page);
    }

    // ---------- اقلام + محاسبه لحظه‌ای ----------
    var taxRate = 0, taxEnabled = false;
    function loadTaxForCalc() {
        $.getJSON('assets/php/tax_settings.php').done(function (res) {
            var s = (res.data && res.data.settings) || {};
            taxEnabled = !!Number(s.tax_enabled);
            taxRate = parseFloat(s.tax_rate) || 0;
            recalcs();
        });
    }

    function addItemRow(pid, name, price, qty, disc, pcode, units, selUnitId) {
        units = units || [];
        var isEditPick = (selUnitId !== undefined && selUnitId !== null && selUnitId !== '');
        var opts = '';
        if (units.length) {
            units.forEach(function (u) {
                var up = isSales ? (Number(u.sale_price) || 0) : (Number(u.purchase_price) || 0);
                var sel = (isEditPick && String(u.id) === String(selUnitId)) ? ' selected' : '';
                opts += '<option value="' + u.id + '" data-price="' + up + '"' + sel + '>' + esc(u.name) + '</option>';
            });
        } else {
            opts = '<option value="">' + esc('عدد') + '</option>';
        }
        var tr = '<tr data-pid="' + escAttr(pid) + '">'
            + '<td>' + esc((pcode ? pcode + ' — ' : '') + (name || '')) + '</td>'
            + '<td><select class="ii-unit">' + opts + '</select></td>'
            + '<td><input type="number" class="ii-price" min="0" step="1" value="' + escAttr(price) + '"></td>'
            + '<td><input type="number" class="ii-qty" min="0" step="1" value="' + escAttr(qty) + '"></td>'
            + '<td><input type="number" class="ii-disc" min="0" step="1" value="' + escAttr(disc || 0) + '"></td>'
            + '<td class="ii-line">0</td>'
            + '<td><button type="button" class="button-secondary small ii-remove">حذف</button></td>'
            + '</tr>';
        $('#invItemsTable tbody').append(tr);
        recalcs();
    }

    function recalcs() {
        var subtotal = 0;
        $('#invItemsTable tbody tr').each(function () {
            var price = parseFloat($('.ii-price', this).val()) || 0;
            var qty = parseFloat($('.ii-qty', this).val()) || 0;
            var disc = parseFloat($('.ii-disc', this).val()) || 0;
            var line = price * qty - disc * qty; // تخفیف هر واحد × تعداد
            if (line < 0) line = 0;
            $('.ii-line', this).text(splitNum(Math.round(line)) + ' ریال');
            subtotal += line;
        });
        var invDisc = parseFloat($('#invDiscount').val()) || 0;
        if (invDisc > subtotal) invDisc = subtotal;
        var taxable = subtotal - invDisc;
        var tax = taxEnabled ? Math.round(taxable * taxRate / 100) : 0;
        var payable = taxable + tax;
        $('#invSubtotal').text(splitNum(Math.round(subtotal)) + ' ریال');
        $('#invTaxable').text(splitNum(Math.round(taxable)) + ' ریال');
        $('#invTax').text(splitNum(Math.round(tax)) + ' ریال');
        $('#invPayable').text(splitNum(Math.round(payable)) + ' ریال');
        $('#invWords').text(amountToWords(payable));
    }

    function bindFormEvents() { /* رویدادها به‌صورت delegate روی document بسته شده‌اند */ }

    /* ---------- Date Picker شمسی (kamaDatepicker) ---------- */
    function initDatePicker() {
        var h = $('#invDate').val();
        if (h) { $('#invDateDisplay').val(gregInputToJalaliStr(h)); }
        if (window.kamaDatepicker) {
            window.kamaDatepicker('invDateDisplay', {
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
        $('#invDateDisplay').off('change.invDate').on('change.invDate', function () { syncHiddenFromDisplay(); });
        syncHiddenFromDisplay();
    }
    function syncHiddenFromDisplay() {
        var jalaliVal = String($('#invDateDisplay').val() || '').trim();
        if (!jalaliVal) return;
        var greg = jalaliStrToGregInput(jalaliVal);
        if (greg) $('#invDate').val(greg);
    }

    $(document).on('click', '#invoiceBackToList, #invoiceFormCancel', function () { showList(); });
    $(document).on('input', '#invItemsTable .ii-price, #invItemsTable .ii-qty, #invItemsTable .ii-disc, #invDiscount', function () { recalcs(); });
    $(document).on('click', '.ii-remove', function () { $(this).closest('tr').remove(); recalcs(); });
    $(document).on('click', '#invAddProduct', function () { openProductModal(); });
    $(document).on('click', '#invAddService', function () { openServiceModal(); });

    // ---------- مودال انتخاب کالا ----------
    var pickedScheduled = true;
    var productSearchTimer = null;
    function openProductModal() {
        $('#invoiceProductTable tbody').html('<tr><td colspan="7" class="empty-state">در حال بارگذاری...</td></tr>');
        loadProducts('');
        openModal('invoiceProductModal');
        $('#invoiceProductSearch').val('').focus();
    }
    function loadProducts(q) {
        var url = 'assets/php/invoice_products.php?search=' + encodeURIComponent(q || '');
        if (isSales) {
            url += '&in_stock=1'; // فاکتور فروش: فقط کالاهای «محصول» با موجودی فعلی > 0 (+ بسته‌های دارای موجودی مشتق‌شده)
        } else {
            url += '&exclude_bundles=1'; // فاکتور خرید: بسته (کالای مجازی فروش) در انتخاب کالا نمایش داده نمی‌شود
        }
        $.getJSON(url).done(function (res) {
            var rows = res.data || [];
            var html = '';
            if (!rows.length) html = '<tr><td colspan="7" class="empty-state">کالایی یافت نشد.</td></tr>';
            rows.forEach(function (p) {
                var units = p.units || [];
                var price = (units.length)
                    ? (isSales ? (Number(units[0].sale_price) || 0) : (Number(units[0].purchase_price) || 0))
                    : (p[priceKey] || 0);
                var unitsJson = JSON.stringify(units);
                html += '<tr>'
                    + '<td>' + esc(p.code) + '</td>'
                    + '<td>' + esc(p.barcode) + '</td>'
                    + '<td>' + esc(p.name) + '</td>'
                    + '<td>' + esc(p.unit) + '</td>'
                    + '<td>' + splitNum(price) + '</td>'
                    + '<td>' + splitNum(Number(p.stock).toString()) + '</td>'
                    + '<td><button type="button" class="button-secondary small product-pick" data-id="' + escAttr(p.id) + '" data-code="' + escAttr(p.code) + '" data-barcode="' + escAttr(p.barcode) + '" data-name="' + escAttr(p.name) + '" data-price="' + escAttr(price) + '" data-unit="' + escAttr(p.unit) + '" data-units="' + escAttr(unitsJson) + '">انتخاب</button></td>'
                    + '</tr>';
            });
            $('#invoiceProductTable tbody').html(html);
        }).fail(function () { $('#invoiceProductTable tbody').html('<tr><td colspan="7" class="empty-state">خطا در جستجوی کالا.</td></tr>'); });
    }
    // Debounce برای جلوگیری از ارسال درخواست برای تک‌تک کاراکترهای بارکدخوان (HID/USB)
    $(document).on('input', '#invoiceProductSearch', function () {
        var q = $(this).val();
        clearTimeout(productSearchTimer);
        productSearchTimer = setTimeout(function () { loadProducts(q); }, 120);
    });
    // اسکنرهای بارکد معمولاً Enter اضافه می‌کنند؛ جلوگیری از submit اتفاقی و جستجوی فوری
    $(document).on('keydown', '#invoiceProductSearch', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(productSearchTimer);
            loadProducts($(this).val());
        }
    });
    $(document).on('click', '.product-pick', function () {
        var p = $(this).data();
        var units = p.units || [];
        var q = String($('#invoiceProductSearch').val() || '').trim();
        // اگر بارکد اسکن شده با بارکد یکی از واحدها برابر بود، آن واحد را پیش‌گزین کنیم
        var firstId = units.length ? units[0].id : '';
        if (q) {
            units.forEach(function (u) {
                if (String(u.barcode || '').trim() === q) { firstId = u.id; }
            });
        }

        // Check if product with same product_id AND same unit_id already exists in invoice
        var found = false;
        $('#invItemsTable tbody tr').each(function () {
            if (String($(this).data('pid')) === String(p.id)) {
                var currentUnit = $('.ii-unit', this).val();
                if (String(currentUnit) === String(firstId)) {
                    // Product with same unit exists - increment quantity
                    var $qty = $('.ii-qty', this);
                    var currentQty = parseFloat($qty.val()) || 0;
                    $qty.val(currentQty + 1);
                    found = true;
                    recalcs();
                    return false; // break the each loop
                }
            }
        });

        // If not found, add as new row
        if (!found) {
            addItemRow(p.id, p.name, p.price, 1, 0, p.code, units, firstId);
        }
        // Modal stays open so user can add multiple products
		const searchInput = document.getElementById('invoiceProductSearch');

		if (searchInput) {
			searchInput.value = '';
			searchInput.focus();
		}
    });

    // ---------- مودال انتخاب خدمت (فقط فاکتور فروش) ----------
    var serviceSearchTimer = null;
    function openServiceModal() {
        $('#invoiceServiceTable tbody').html('<tr><td colspan="7" class="empty-state">در حال بارگذاری...</td></tr>');
        loadServices('');
        openModal('invoiceServiceModal');
        $('#invoiceServiceSearch').val('').focus();
    }
    function loadServices(q) {
        $.getJSON('assets/php/invoice_products.php?type=service&search=' + encodeURIComponent(q || '')).done(function (res) {
            var rows = res.data || [];
            var html = '';
            if (!rows.length) html = '<tr><td colspan="7" class="empty-state">خدمتی یافت نشد.</td></tr>';
            rows.forEach(function (p) {
                var units = p.units || [];
                var price = (units.length)
                    ? (isSales ? (Number(units[0].sale_price) || 0) : (Number(units[0].purchase_price) || 0))
                    : (p[priceKey] || 0);
                var unitsJson = JSON.stringify(units);
                html += '<tr>'
                    + '<td>' + esc(p.code) + '</td>'
                    + '<td>' + esc(p.barcode) + '</td>'
                    + '<td>' + esc(p.name) + '</td>'
                    + '<td>' + esc(p.unit) + '</td>'
                    + '<td>' + splitNum(price) + '</td>'
                    + '<td>' + splitNum(Number(p.stock).toString()) + '</td>'
                    + '<td><button type="button" class="button-secondary small service-pick" data-id="' + escAttr(p.id) + '" data-code="' + escAttr(p.code) + '" data-barcode="' + escAttr(p.barcode) + '" data-name="' + escAttr(p.name) + '" data-price="' + escAttr(price) + '" data-unit="' + escAttr(p.unit) + '" data-units="' + escAttr(unitsJson) + '">انتخاب</button></td>'
                    + '</tr>';
            });
            $('#invoiceServiceTable tbody').html(html);
        }).fail(function () { $('#invoiceServiceTable tbody').html('<tr><td colspan="7" class="empty-state">خطا در جستجوی خدمت.</td></tr>'); });
    }
    // Debounce برای جلوگیری از ارسال درخواست برای تک‌تک کاراکترهای بارکدخوان (HID/USB)
    $(document).on('input', '#invoiceServiceSearch', function () {
        var q = $(this).val();
        clearTimeout(serviceSearchTimer);
        serviceSearchTimer = setTimeout(function () { loadServices(q); }, 120);
    });
    // اسکنرهای بارکد معمولاً Enter اضافه می‌کنند؛ جلوگیری از submit اتفاقی و جستجوی فوری
    $(document).on('keydown', '#invoiceServiceSearch', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(serviceSearchTimer);
            loadServices($(this).val());
        }
    });
    $(document).on('click', '.service-pick', function () {
        var p = $(this).data();
        var units = p.units || [];
        var q = String($('#invoiceServiceSearch').val() || '').trim();
        // اگر بارکد اسکن شده با بارکد یکی از واحدها برابر بود، آن واحد را پیش‌گزین کنیم
        var firstId = units.length ? units[0].id : '';
        if (q) {
            units.forEach(function (u) {
                if (String(u.barcode || '').trim() === q) { firstId = u.id; }
            });
        }

        // اگر خدمت با همین شناسه و واحد در فاکتور موجود باشد، تعداد یک واحد افزایش می‌یابد
        var found = false;
        $('#invItemsTable tbody tr').each(function () {
            if (String($(this).data('pid')) === String(p.id)) {
                var currentUnit = $('.ii-unit', this).val();
                if (String(currentUnit) === String(firstId)) {
                    var $qty = $('.ii-qty', this);
                    var currentQty = parseFloat($qty.val()) || 0;
                    $qty.val(currentQty + 1);
                    found = true;
                    recalcs();
                    return false; // break the each loop
                }
            }
        });

        // اگر ردیف مشابه وجود نداشت، ردیف جدید اضافه می‌شود
        if (!found) {
            addItemRow(p.id, p.name, p.price, 1, 0, p.code, units, firstId);
        }
        // مودال باز می‌ماند تا کاربر بتواند چند خدمت اضافه کند
    });
    // تغییر واحد در ردیف → به‌روزرسانی قیمت واحد معتبر آن واحد
    $(document).on('change', '#invItemsTable .ii-unit', function () {
        var $opt = $('option:selected', this);
        if ($opt.length) {
            var pr = Number($opt.data('price')) || 0;
            $('.ii-price', $(this).closest('tr')).val(pr);
        }
        recalcs();
    });

    // ---------- مودال انتخاب طرف معامله ----------
    function openPartyModal() {
        $('#invoicePartyTable tbody').html('<tr><td colspan="4" class="empty-state">در حال بارگذاری...</td></tr>');
        loadParties('');
        openModal('invoicePartyModal');
        $('#invoicePartySearch').val('').focus();
    }
    function loadParties(q) {
        $.getJSON('assets/php/invoice_parties.php?party_type=' + partyType + '&search=' + encodeURIComponent(q || '')).done(function (res) {
            var rows = res.data || [];
            var html = '';
            if (!rows.length) html = '<tr><td colspan="4" class="empty-state">' + partyLabel + 'ی یافت نشد.</td></tr>';
            rows.forEach(function (p) {
                html += '<tr>'
                    + '<td>' + esc(p.name) + '</td>'
                    + '<td>' + esc(p.phone) + '</td>'
                    + '<td>' + esc(p.national_code) + '</td>'
                    + '<td><button type="button" class="button-secondary small party-pick" data-id="' + escAttr(p.id) + '" data-name="' + escAttr(p.name) + '">انتخاب</button></td>'
                    + '</tr>';
            });
            $('#invoicePartyTable tbody').html(html);
        }).fail(function () { $('#invoicePartyTable tbody').html('<tr><td colspan="4" class="empty-state">خطا در جستجو.</td></tr>'); });
    }
    $(document).on('input', '#invoicePartySearch', function () { loadParties($(this).val()); });
    $(document).on('click', '.party-pick', function () {
        var p = $(this).data();
        $('#invPartyId').text(p.id);
        $('#invPickParty').val(p.name);
        closeModal('invoicePartyModal');
    });
    $(document).on('click', '#invPickParty', function () { openPartyModal(); });

    // ---------- ذخیره (Create / Edit) ----------
    function submitForm() {
        var items = [];
        $('#invItemsTable tbody tr').each(function () {
            var pid = $(this).data('pid');
            if (!pid) return;
            items.push({
                product_id: pid,
                unit_id: $('.ii-unit', this).val() || '',
                quantity: $('.ii-qty', this).val(),
                unit_price: $('.ii-price', this).val(),
                discount: $('.ii-disc', this).val() || '0'
            });
        });
        var partyId = String($('#invPartyId').text() || '').trim();
        if (!partyId) { alert('لطفاً ' + partyLabel + ' را انتخاب کنید.'); return; }
        syncHiddenFromDisplay();
        var gDate = $('#invDate').val();
        if (!gDate || !/^\d{4}-\d{2}-\d{2}$/.test(gDate)) { alert('تاریخ فاکتور نامعتبر است.'); return; }
        if (!items.length) { alert('حداقل یک کالا باید اضافه شود.'); return; }

        var payload = {
            csrf_token: csrf,
            invoice_id: currentInvoiceId,
            type: $('#invType').val(),
            payment_type: $('#invPaymentType').val(),
            payment_status: $('#invPaymentStatus').val(),
            invoice_date: gDate,
            discount: $('#invDiscount').val() || '0',
            note: $('#invNote').val(),
            client_token: clientToken,
            items: JSON.stringify(items)
        };
        payload[partyField] = partyId;

        var $btn = $('#invoiceFormSave');
        $btn.prop('disabled', true).text('در حال ذخیره...');
        $.post('assets/php/invoice_save.php', payload).done(function (res) {
            if (res.success) { showList(currentInvoiceId === '' ? 1 : currentPage); alert(res.message); }
            else { alert(res.message || 'خطا در ذخیره.'); }
            $btn.prop('disabled', false).text('ذخیره فاکتور');
        }).fail(function () {
            alert('خطا در ارتباط با سرور.');
            $btn.prop('disabled', false).text('ذخیره فاکتور');
        });
    }
    $(document).on('click', '#invoiceFormSave', submitForm);

    // ---------- مسیر مستقیم ویرایش (از گزارش‌ها) ----------
    // اگر URL شامل action=edit&id=<مثبت> باشد، فاکتور فروش مستقیماً
    // بدون بارگذاری قبلی لیست باز می‌شود.
    function getUrlParams() {
        var out = {};
        var qs = window.location.search.replace(/^\?/, '');
        if (!qs) return out;
        qs.split('&').forEach(function (pair) {
            if (!pair) return;
            var idx = pair.indexOf('=');
            var name = decodeURIComponent(idx === -1 ? pair : pair.slice(0, idx));
            var val = idx === -1 ? '' : decodeURIComponent(pair.slice(idx + 1));
            if (out[name] === undefined) out[name] = val;
        });
        return out;
    }
    // حذف فقط پارامترهای مسیر ویرایش (action/id) از URL؛ بقیه پارامترها حفظ می‌شوند
    function cleanUrlForList() {
        var qs = window.location.search.replace(/^\?/, '');
        var kept = [];
        if (qs) {
            qs.split('&').forEach(function (pair) {
                if (!pair) return;
                var name = pair.split('=')[0];
                if (name === 'action' || name === 'id') return;
                kept.push(pair);
            });
        }
        var next = kept.length ? ('?' + kept.join('&')) : window.location.pathname;
        return next === window.location.search ? null : next;
    }
    function fallbackToList() {
        var clean = cleanUrlForList();
        if (clean !== null) history.replaceState(null, '', clean);
        $('#invoiceFormView').hide();
        $('#invoiceListView').show();
        loadInvoices(1);
    }
    var urlParams = getUrlParams();
    var directEditId = '';
    if (urlParams.action === 'edit' && /^\d+$/.test(String(urlParams.id || ''))) {
        var n = parseInt(urlParams.id, 10);
        if (n > 0) directEditId = String(n);
    }
    function openDirectEdit(id) {
        $.getJSON('assets/php/invoice_get.php?id=' + encodeURIComponent(id)).done(function (res) {
            var inv = (res && res.data) || null;
            if (!res || !res.success || !inv || !inv.type) {
                alert((res && res.message) || 'خطا در دریافت فاکتور.');
                fallbackToList();
                return;
            }
            if (!/^sales/.test(String(inv.type))) {
                alert('فاکتور موردنظر یک فاکتور فروش نیست و در این صفحه قابل ویرایش نیست.');
                fallbackToList();
                return;
            }
            openForm(inv, inv.type);
        }).fail(function () {
            alert('خطا در دریافت فاکتور.');
            fallbackToList();
        });
    }

    // ---------- راه‌اندازی ----------
    if (directEditId !== '' && isSales) {
        openDirectEdit(directEditId);
    } else {
        loadInvoices();
    }
    loadTaxForCalc();
});



