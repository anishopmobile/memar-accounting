/* ============================================================
 * defective-goods.js — module for the defective goods page
 * jQuery + AJAX + JSON, consistent with the project conventions.
 * ========================================================== */
$(function () {
    if (document.getElementById('defRegForm') === null) return;

    var csrf = String($('#defCsrfToken').val() || '');
    var currentPage = parseInt($('#defCurrentPage').val(), 10);
    if (!(currentPage >= 1)) currentPage = 1;
    var picked = null; // {id,name,code,barcode,stock,unit}
    var productSearchTimer = null;

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function todayISO() { var d = new Date(); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }

    function openModal(id) { $('#' + id).addClass('open'); $('body').addClass('modal-open'); }
    function closeModal(id) { $('#' + id).removeClass('open'); $('body').removeClass('modal-open'); }
    $(document).on('click', '[data-close]', function () { closeModal($(this).data('close')); });
    $(document).on('click', '#defProductModal .modal-backdrop', function () { closeModal('defProductModal'); });
function splitNum(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function showResult(msg, ok) {
        $('#defResult').text(msg).css('color', ok ? '#15803d' : '#c0392b');
    }

    /* ---------- Jalali <-> Gregorian conversion (same algorithm as jdf.php) ---------- */
    var DG_GDM = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    function gregorianToJalali(gy, gm, gd) {
        var jyBase = (gy > 1600) ? 979 : 0;
        var gy2 = gy - (gy > 1600 ? 1600 : 621);
        var gy3 = (gm > 2) ? gy2 + 1 : gy2;
        var days = 365 * gy2 + Math.floor((gy3 + 3) / 4) - Math.floor((gy3 + 99) / 100) + Math.floor((gy3 + 399) / 400) - 80 + gd + DG_GDM[gm - 1];
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
    function jalaliStrToGregInput(str) {
        var m = String(str || '').trim().match(/^(\d{4,})[\-\/](\d{1,2})[\-\/](\d{1,2})$/);
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

/* ---------- registration date ---------- */
    function syncRegisterDate() {
        var g = jalaliStrToGregInput($('#defDateDisplay').val());
        if (g) $('#defDate').val(g);
    }
    function initRegisterDatePicker() {
        if (window.kamaDatepicker) {
            window.kamaDatepicker('defDateDisplay', {
                placeholder: '', twodigit: true, closeAfterSelect: true,
                nextButtonIcon: 'بعدی', previousButtonIcon: 'قبلی',
                forceFarsiDigits: true, markToday: true, highlightSelectedDay: true,
                sync: true, gotoToday: true
            });
        }
        var h = $('#defDate').val();
        if (h) { $('#defDateDisplay').val(gregInputToJalaliStr(h)); }
        $('#defDateDisplay').off('change.defDate').on('change.defDate', syncRegisterDate);
        syncRegisterDate();
    }

    /* ---------- report filter dates ---------- */
    function syncFilterDate(which) {
        var displayId = (which === 'from') ? 'defFromDisplay' : 'defToDisplay';
        var hiddenId = (which === 'from') ? 'defFrom' : 'defTo';
        var v = String($('#' + displayId).val() || '').trim();
        if (!v) { $('#' + hiddenId).val(''); return; }
        var g = jalaliStrToGregInput(v);
        $('#' + hiddenId).val(g ? g : '');
    }
    function initFilterDatePickers() {
        if (window.kamaDatepicker) {
            var opts = {
                placeholder: '', twodigit: true, closeAfterSelect: true,
                nextButtonIcon: 'بعدی', previousButtonIcon: 'قبلی',
                forceFarsiDigits: true, markToday: true, highlightSelectedDay: true,
                sync: true, gotoToday: true
            };
            window.kamaDatepicker('defFromDisplay', opts);
            window.kamaDatepicker('defToDisplay', opts);
        }
        var hf = $('#defFrom').val();
        if (hf) { $('#defFromDisplay').val(gregInputToJalaliStr(hf)); }
        var ht = $('#defTo').val();
        if (ht) { $('#defToDisplay').val(gregInputToJalaliStr(ht)); }
        $('#defFromDisplay').off('change.defFrom').on('change.defFrom', function () { syncFilterDate('from'); });
        $('#defToDisplay').off('change.defTo').on('change.defTo', function () { syncFilterDate('to'); });
        syncFilterDate('from');
        syncFilterDate('to');
    }

    /* ---------- product picker modal ---------- */
    function loadProducts(q) {
        $.getJSON('assets/php/invoice_products.php?type=product&search=' + encodeURIComponent(q || ''))
            .done(function (res) {
                var rows = res.data || [];
                var html = '';
                if (!rows.length) html = '<tr><td colspan="6" class="empty-state">کالایی یافت نشد.</td></tr>';
                rows.forEach(function (p) {
                    html += '<tr>'
                        + '<td>' + esc(p.code) + '</td>'
                        + '<td dir="ltr">' + esc(p.barcode || '-') + '</td>'
                        + '<td>' + esc(p.name) + '</td>'
                        + '<td>' + esc(p.unit) + '</td>'
                        + '<td>' + splitNum(Number(p.stock).toString()) + '</td>'
                        + '<td><button type="button" class="button-secondary small def-product-pick" data-id="' + escAttr(p.id) + '" data-name="' + escAttr(p.name) + '" data-code="' + escAttr(p.code) + '" data-barcode="' + escAttr(p.barcode || '') + '" data-stock="' + escAttr(p.stock) + '" data-unit="' + escAttr(p.unit) + '">انتخاب</button></td>'
                        + '</tr>';
                });
                $('#defProductTable tbody').html(html);
            })
            .fail(function () { $('#defProductTable tbody').html('<tr><td colspan="6" class="empty-state">خطا در جستجوی کالا.</td></tr>'); });
    }
    $(document).on('click', '#defPickProduct', function () {
        openModal('defProductModal');
        loadProducts('');
        $('#defProductSearch').val('').focus();
    });
    $(document).on('input', '#defProductSearch', function () {
        var q = $(this).val();
        clearTimeout(productSearchTimer);
        productSearchTimer = setTimeout(function () { loadProducts(q); }, 120);
    });
    $(document).on('click', '.def-product-pick', function () {
        picked = {
            id: $(this).data('id'),
            name: $(this).data('name'),
            code: $(this).data('code'),
            barcode: $(this).data('barcode'),
            stock: $(this).data('stock'),
            unit: $(this).data('unit')
        };
        $('#defProductId').val(picked.id);
        $('#defProductDisplay').val(picked.name + ' (کد ' + picked.code + ')');
        $('#defCurrentStock').text(picked.stock + ' ' + picked.unit);
        $('#defProductInfo').text('بارکد: ' + (picked.barcode || '-'));
        closeModal('defProductModal');
    });

/* ---------- submit ---------- */
    $(document).on('submit', '#defRegForm', function (e) {
        e.preventDefault();
        var $btn = $('#defSubmit');
        $btn.prop('disabled', true).text('در حال ثبت...');
        var resetBtn = function () { $btn.prop('disabled', false).text('ثبت کالای معیوب'); };

        if (!picked) { showResult('کالا انتخاب نشده است.', false); resetBtn(); return; }
        var qty = String($('#defQuantity').val() || '').trim();
        var qtyNum = parseFloat(qty);
        if (!qty || !(qtyNum > 0)) { showResult('تعداد معیوب باید عددی بزرگ‌تر از صفر باشد.', false); resetBtn(); return; }
        if (qtyNum > parseFloat(picked.stock)) { showResult('تعداد معیوب بیشتر از موجودی فعلی کالا است.', false); resetBtn(); return; }
        var dateIso = String($('#defDate').val() || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(dateIso)) { showResult('تاریخ ثبت نامعتبر است.', false); resetBtn(); return; }
        var reason = String($('#defReason').val() || '').trim();
        if (!reason) { showResult('دلیل ثبت کالای معیوب الزامی است.', false); resetBtn(); return; }

        var payload = {
            csrf_token: csrf,
            product_id: picked.id,
            quantity: qty,
            defective_date: dateIso,
            reason: reason,
            description: String($('#defDescription').val() || '').trim()
        };
        $.post('assets/php/defective_goods_action.php', payload)
            .done(function (res) {
                if (res.success) {
                    showResult(res.message + ' — موجودی جدید: ' + (res.data ? res.data.stock_after : ''), true);
                    $('#defQuantity').val('');
                    $('#defReason').val('');
                    $('#defDescription').val('');
                    if (res.data) {
                        picked.stock = String(res.data.stock_after);
                        $('#defCurrentStock').text(picked.stock + ' ' + picked.unit);
                    }
                    loadDgReport(currentPage); // به‌روزرسانی فوری جدول/شمارنده‌ها بدون رفرش
                } else {
                    showResult(res.message || 'خطا در ثبت.', false);
                }
                resetBtn();
            })
            .fail(function () {
                showResult('خطا در ارتباط با سرور.', false);
                resetBtn();
            });
    });

    /* ---------- به‌روزرسانی AJAX گزارش (بدون رفرش کامل صفحه) ---------- */
    /* قالب‌بندی عدد صحیح مطابق number_format سرور (گرد کردن به واحد) */
    function numFmt(n) {
        var v = Math.round(parseFloat(n == null || n === '' ? '0' : String(n)));
        if (isNaN(v)) return '0';
        return String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function dgPagerBtn(p, disabled, label) {
        return '<button type="button" class="pagination-button" data-page="' + p + '"' + (disabled ? ' disabled' : '') + '>' + esc(label) + '</button>';
    }
    function renderDgReport(data) {
        if (!data) return;
        var summary = data.summary || {};
        $('#defSummaryRecords').text('تعداد رکوردها: ' + numFmt(summary.records));
        $('#defSummaryQty').text('جمع تعداد معیوب: ' + numFmt(summary.total_quantity));

        var rows = data.rows || [];
        var html;
        if (!rows.length) {
            html = '<tr class="empty-state-row"><td colspan="9" class="empty-state">رکوردی یافت نشد</td></tr>';
        } else {
            html = '';
            rows.forEach(function (r) {
                html += '<tr>'
                    + '<td>' + esc(r.row_no) + '</td>'
                    + '<td>' + esc(r.defective_date_jalali) + '</td>'
                    + '<td>' + esc(r.product_name) + '</td>'
                    + '<td dir="ltr">' + esc(r.product_barcode) + '</td>'
                    + '<td dir="ltr">' + esc(r.product_code) + '</td>'
                    + '<td>' + esc(r.quantity_display) + ' ' + esc(r.product_unit) + '</td>'
                    + '<td>' + esc(r.reason) + '</td>'
                    + '<td>' + esc(r.description) + '</td>'
                    + '<td><button type="button" class="button-secondary small def-return-btn"'
                    + ' data-id="' + escAttr(r.id) + '"'
                    + ' data-qty="' + escAttr(r.quantity) + '"'
                    + ' data-product="' + escAttr(r.product_name) + '"'
                    + ' data-unit="' + escAttr(r.product_unit) + '">برگشت به موجودی</button></td>'
                    + '</tr>';
            });
        }
        $('#defReportBody').html(html);

        $('#defPaginationInfo').text('نمایش ' + data.rangeStart + ' تا ' + data.rangeEnd + ' از ' + data.total + ' رکورد');
        var pager = '';
        if (data.totalPages > 1) {
            var isFirst = data.page <= 1;
            var isLast = data.page >= data.totalPages;
            pager = dgPagerBtn(1, isFirst, 'اولین')
                + dgPagerBtn(data.page - 1, isFirst, 'قبلی')
                + '<span class="pagination-current">صفحه ' + data.page + ' از ' + data.totalPages + '</span>'
                + dgPagerBtn(data.page + 1, isLast, 'بعدی')
                + dgPagerBtn(data.totalPages, isLast, 'آخرین');
        }
        $('#defPaginationControls').html(pager);

        if (data.page >= 1) currentPage = data.page;
    }
    function loadDgReport(page) {
        var p = (page && page >= 1) ? page : currentPage;
        $.getJSON('assets/php/defective_goods_report.php', {
            pageno: p,
            from: String($('#defFrom').val() || '').trim(),
            to: String($('#defTo').val() || '').trim()
        }).done(function (res) {
            if (res.success) renderDgReport(res.data || {});
        }).fail(function () {
            showResult('خطا در به‌روزرسانی گزارش.', false);
        });
    }

    // صفحه‌بندی بدون رفرش (لینک‌های اولیه و دکمه‌های رندر مجدد)
    $(document).on('click', '#defPaginationControls .pagination-button', function (e) {
        if ($(this).prop('disabled') || $(this).hasClass('disabled')) return;
        e.preventDefault();
        var p = parseInt($(this).data('page'), 10);
        if (p >= 1) loadDgReport(p);
    });

    /* ---------- برگشت کالای معیوب به موجودی ---------- */
    var defReturnCtx = null; // { id, qty, product, unit }

    function doDgReturn(id, qty) {
        $.post('assets/php/defective_goods_return_action.php', {
            csrf_token: csrf,
            defective_id: id,
            quantity: qty
        }).done(function (res) {
            if (res.success) {
                closeModal('defReturnModal');
                alert(res.message);
                loadDgReport(currentPage); // به‌روزرسانی فوری بدون رفرش
            } else {
                $('#defReturnQtyError').text(res.message || 'خطا در برگشت کالا.').show();
            }
        }).fail(function () {
            $('#defReturnQtyError').text('خطا در ارتباط با سرور.').show();
        }).always(function () {
            $('#defReturnConfirm').prop('disabled', false);
        });
    }

    $(document).on('click', '.def-return-btn', function () {
        var id = $(this).data('id');
        var qtyRaw = String($(this).data('qty') == null ? '' : $(this).data('qty')).trim();
        var qtyNum = parseFloat(qtyRaw);
        var product = String($(this).data('product') || '');
        var unit = String($(this).data('unit') || '');

        if (!id || !(qtyNum > 0)) { alert('اطلاعات رکورد نامعتبر است.'); return; }

        // مقدار ۱ → فقط تایید ساده
        if (qtyNum === 1) {
            if (!confirm('آیا از برگشت ۱ عدد کالا به موجودی اطمینان دارید؟')) return;
            doDgReturn(id, '1');
            return;
        }

        // مقدار بیشتر → پرسیدن تعداد از کاربر
        defReturnCtx = { id: id, qty: qtyRaw, product: product, unit: unit };
        $('#defReturnProduct').text(product);
        $('#defReturnAvail').text(qtyRaw + ' ' + unit);
        $('#defReturnQty').val('');
        $('#defReturnQtyError').hide();
        openModal('defReturnModal');
        setTimeout(function () { $('#defReturnQty').focus(); }, 60);
    });

    $(document).on('click', '#defReturnConfirm', function () {
        if (!defReturnCtx) return;
        var v = String($('#defReturnQty').val() || '').trim();
        if (!/^\d+(\.\d{1,2})?$/.test(v) || parseFloat(v) <= 0) {
            $('#defReturnQtyError').text('تعداد برگشتی باید عددی بزرگ‌تر از صفر با حداکثر دو رقم اعشار باشد.').show();
            return;
        }
        if (parseFloat(v) > parseFloat(defReturnCtx.qty)) {
            $('#defReturnQtyError').text('تعداد درخواستی بیشتر از مقدار معیوب باقی‌مانده است.').show();
            return;
        }
        if (!confirm('آیا ' + v + ' عدد از ' + defReturnCtx.qty + ' عدد کالای معیوب به موجودی کالا برگردانده شود؟')) return;
        $('#defReturnConfirm').prop('disabled', true);
        doDgReturn(defReturnCtx.id, v);
    });

    $(document).on('click', '[data-close="defReturnModal"]', function () { defReturnCtx = null; });

    /* ---------- reset form ---------- */
    $(document).on('click', '#defReset', function () {
        picked = null;
        $('#defProductId').val('');
        $('#defProductDisplay').val('');
        $('#defProductInfo').text('');
        $('#defCurrentStock').text('—');
        $('#defQuantity').val('');
        $('#defReason').val('');
        $('#defDescription').val('');
        $('#defDate').val(todayISO());
        $('#defDateDisplay').val(gregInputToJalaliStr(todayISO()));
        $('#defResult').text('').css('color', '');
    });

    initRegisterDatePicker();
    initFilterDatePickers();
});