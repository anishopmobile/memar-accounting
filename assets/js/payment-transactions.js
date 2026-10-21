/* ============================================================
 * payment-transactions.js — تراکنش‌های پرداخت (مدیریت)
 * jQuery + AJAX + JSON. Page-guarded to #payment-transactions.
 * ========================================================== */
$(function () {
    if ($('body').attr('id') !== 'payment-transactions') return;

    var state = {
        page: 1,
        perPage: 20,
        sort: 'date',
        dir: 'desc'
    };

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    function numberToShort(n) {
        n = parseInt(n, 10) || 0;
        if (n >= 1000000000) return (n / 1000000000) + ' میلیارد';
        if (n >= 1000000) return (n / 1000000) + ' میلیون';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /* ---------- dashboard ---------- */
    function loadDashboard() {
        $.getJSON('assets/php/payment/payment_transaction_action.php', { action: 'dashboard' })
            .done(function (res) {
                if (!res.success) return;
                var s = res.data.stats || {};
                $('#statTotal').text(s.total_all || 0);
                $('#statPaid').text(s.total_paid || 0);
                $('#statFailed').text(s.total_failed || 0);
                $('#statPending').text(s.total_pending || 0);
                $('#statToday').text(s.paid_today || 0);
                $('#statRevenue').text(numberToShort(s.total_revenue || 0) + ' ریال');
            });
    }

    /* ---------- list ---------- */
    function currentFilters() {
        return {
            search: $('#txSearch').val(),
            status: $('#txStatus').val(),
            form_id: $('#txForm').val(),
            amount_min: $('#txAmountMin').val(),
            amount_max: $('#txAmountMax').val(),
            date_from: $('#txDateFrom').val(),
            date_to: $('#txDateTo').val()
        };
    }

    function loadList(page) {
        state.page = page || 1;
        var qs = $.param($.extend({ action: 'list' }, currentFilters(), state));
        $.getJSON('assets/php/payment/payment_transaction_action.php?' + qs)
            .done(function (res) {
                if (!res.success) {
                    $('#paymentTxTable tbody').html('<tr><td colspan="7" class="empty-state">خطا در دریافت تراکنش‌ها.</td></tr>');
                    return;
                }
                renderRows(res.data.items || []);
                renderPagination(res.data);
            })
            .fail(function () {
                $('#paymentTxTable tbody').html('<tr><td colspan="7" class="empty-state">خطا در ارتباط.</td></tr>');
            });
    }

    function statusBadge(status) {
        var map = {
            paid: '<span class="badge">پرداخت شده</span>',
            failed: '<span class="badge badge-danger">ناموفق</span>',
            pending: '<span class="badge badge-warning">در انتظار</span>',
            created: '<span class="badge">ایجاد شده</span>'
        };
        return map[status] || esc(status);
    }

    function renderRows(items) {
        var html = '';
        if (!items.length) {
            $('#paymentTxTable tbody').html('<tr><td colspan="7" class="empty-state">تراکنشی یافت نشد.</td></tr>');
            return;
        }
        for (var i = 0; i < items.length; i++) {
            var t = items[i];
            html += '<tr data-id="' + esc(t.id) + '">'
                + '<td dir="ltr">' + esc(t.number) + '</td>'
                + '<td>' + esc(t.form_name) + '</td>'
                + '<td>' + esc(t.amount_display) + '</td>'
                + '<td>' + statusBadge(t.status) + '</td>'
                + '<td>' + esc(t.customer_name || '-') + ' <small dir="ltr">' + esc(t.customer_mobile || '') + '</small></td>'
                + '<td>' + esc(t.created_at_display) + '</td>'
                + '<td><button type="button" class="button-secondary small tx-detail">جزئیات</button></td>'
                + '</tr>';
        }
        $('#paymentTxTable tbody').html(html);
    }

    /* ---------- detail ---------- */
    function loadDetail(id) {
        $('#txDetailBody').html('<p class="empty-state">در حال بارگذاری...</p>');
        $('#txDetailModal').addClass('open');
        $('body').addClass('modal-open');
        $.getJSON('assets/php/payment/payment_transaction_action.php', { action: 'get', id: id })
            .done(function (res) {
                if (!res.success) { $('#txDetailBody').html('<p>خطا: ' + esc(res.message) + '</p>'); return; }
                $('#txDetailBody').html(detailHtml(res.data));
            })
            .fail(function () { $('#txDetailBody').html('<p>خطا در دریافت جزئیات.</p>'); });
    }

    function detailHtml(t) {
        var sub = '';
        var sd = t.submitted_data || {};
        for (var k in sd) {
            if (!sd.hasOwnProperty(k)) continue;
            sub += '<tr><td>' + esc(k) + '</td><td>' + esc(Array.isArray(sd[k]) ? sd[k].join('، ') : sd[k]) + '</td></tr>';
        }
        return '<div class="form-grid">'
            + '<div class="form-row"><label>شماره تراکنش</label><div dir="ltr">' + esc(t.number) + '</div></div>'
            + '<div class="form-row"><label>فرم</label><div>' + esc(t.form_name) + '</div></div>'
            + '<div class="form-row"><label>مبلغ</label><div>' + esc(t.amount_display) + '</div></div>'
            + '<div class="form-row"><label>وضعیت</label><div>' + statusBadge(t.status) + '</div></div>'
            + '<div class="form-row"><label>مشتری</label><div>' + esc(t.customer_name || '-') + '</div></div>'
            + '<div class="form-row"><label>موبایل</label><div dir="ltr">' + esc(t.customer_mobile || '-') + '</div></div>'
            + '<div class="form-row"><label>درگاه</label><div>' + esc(t.gateway) + '</div></div>'
            + '<div class="form-row"><label>Authority</label><div dir="ltr">' + esc(t.authority || '-') + '</div></div>'
            + '<div class="form-row"><label>کد پیگیری</label><div dir="ltr">' + esc(t.ref_id || '-') + '</div></div>'
            + '<div class="form-row"><label>دلیل خطا</label><div>' + esc(t.failure_reason || '-') + '</div></div>'
            + '<div class="form-row"><label>ساخته شده</label><div>' + esc(t.created_at_display) + '</div></div>'
            + '<div class="form-row"><label>بازگشت از درگاه</label><div>' + esc(t.callback_at_display) + '</div></div>'
            + '<div class="form-row"><label>تایید شد</label><div>' + esc(t.verified_at_display) + '</div></div>'
            + '<div class="form-row"><label>IP</label><div dir="ltr">' + esc(t.ip_address || '-') + '</div></div>'
            + '</div>'
            + '<h3>اطلاعات ارسال شده</h3>'
            + (sub !== '' ? '<table class="action-table"><tbody>' + sub + '</tbody></table>' : '<p class="setting-note">—</p>');
    }

    /* ---------- event bindings ---------- */
    $('#txRefresh').on('click', function () { loadDashboard(); loadList(1); });
    $('#txApply').on('click', function () { state.page = 1; loadList(1); });

    $('#txPagination').on('click', '.dashboard-page-button', function () {
        if ($(this).prop('disabled')) return;
        state.page = parseInt($(this).attr('data-page'), 10) || 1;
        loadList(state.page);
    });

    $('#paymentTxTable th[data-sort]:not(.no-sort)').on('click', function () {
        var col = $(this).attr('data-sort');
        if (state.sort === col) {
            state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sort = col;
            state.dir = 'asc';
        }
        state.page = 1;
        loadList(1);
    });

    $('#paymentTxTable tbody').on('click', '.tx-detail', function () {
        loadDetail($(this).closest('tr').data('id'));
    });

    $(document).on('click', '[data-close="txDetailModal"]', function () {
        $('#txDetailModal').removeClass('open');
        $('body').removeClass('modal-open');
    });

    loadDashboard();
    loadList(1);
});