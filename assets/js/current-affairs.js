/* ============================================================
 * current-affairs.js — امور جاری (Current Affairs) module.
 * jQuery + AJAX + JSON, consistent with project conventions.
 * Page-guarded to #current-affairs. All mutations POST + CSRF.
 * ========================================================== */
$(function () {
    if ($('body').attr('id') !== 'current-affairs') return;

    /* Idempotent initialization: even if this script is ever included more
     * than once, everything below (including every event handler) is
     * registered exactly one time. */
    if (window.CA_INITIALIZED) return;
    window.CA_INITIALIZED = true;

    var csrf = String($('#caCsrfToken').val() || '');
    var curYear = parseInt($('#caCurYear').val(), 10) || 1400;
    var curMonth = parseInt($('#caCurMonth').val(), 10) || 1;

    var state = {
        categories: [],
        fieldCatId: 0,
        recordCatId: 0,
        monthlyCatId: 0
    };

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function openModal(id) { $('#' + id).addClass('open'); $('body').addClass('modal-open'); }
    function closeModal(id) { $('#' + id).removeClass('open'); $('body').removeClass('modal-open'); }
    function showResult(id, msg, ok) {
        $('#' + id).text(msg || '').css('color', ok ? '#15803d' : '#c0392b');
    }

    /* ---------- Module-scoped modal lifecycle (bound exactly once) ----------
     * The Close/X button, Cancel button and the backdrop of every Current
     * Affairs modal are handled here so the modals never depend on global
     * data-close listeners from other page scripts. Escape also closes the
     * open module modal. */
    var caModalHandlersBound = false;
    function bindCaModalHandlers() {
        if (caModalHandlersBound) return;
        caModalHandlersBound = true;
        $(document).on('click', '#caCatModal [data-close], #caFieldModal [data-close], #caRecordModal [data-close]', function (e) {
            e.preventDefault();
            closeModal($(this).data('close'));
        });
        $(document).on('keydown.ca-modal', function (e) {
            if (e.key !== 'Escape' && e.keyCode !== 27) return;
            ['caCatModal', 'caFieldModal', 'caRecordModal'].forEach(function (id) {
                if ($('#' + id).hasClass('open')) closeModal(id);
            });
        });
    }
    function numFmt(n) {
        var v = Math.round(parseFloat(n == null || n === '' ? '0' : String(n)));
        if (isNaN(v)) return '0';
        var neg = v < 0;
        var s = String(Math.abs(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return neg ? '-' + s : s;
    }

    /* ---------- Jalali <-> Gregorian conversion (same algorithm as jdf.php) ---------- */
    var CA_GDM = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    function gregorianToJalali(gy, gm, gd) {
        var jyBase = (gy > 1600) ? 979 : 0;
        var gy2 = gy - (gy > 1600 ? 1600 : 621);
        var gy3 = (gm > 2) ? gy2 + 1 : gy2;
        var days = 365 * gy2 + Math.floor((gy3 + 3) / 4) - Math.floor((gy3 + 99) / 100)
            + Math.floor((gy3 + 399) / 400) - 80 + gd + CA_GDM[gm - 1];
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
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function jalaliStrToGregInput(str) {
        var m = String(str || '').trim().match(/^(\d{4,})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
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

    /* ============================================================
     * Tabs
     * ========================================================== */
    $('#caTabs button').on('click', function () {
        $('#caTabs button').removeClass('active');
        $(this).addClass('active');
        $('.settings-section').hide();
        $('#' + $(this).data('target')).show();
    });

    /* ============================================================
     * Shared category selectors
     * ========================================================== */
    function fillCategorySelects(categories) {
        ['caFieldCat', 'caRecordCat', 'caMonthlyCat'].forEach(function (sel) {
            var $sel = $('#' + sel);
            var prev = $sel.val();
            var html = '<option value="">— انتخاب کنید —</option>';
            categories.forEach(function (c) {
                html += '<option value="' + (c.id | 0) + '">' + esc(c.name)
                    + (c.is_active ? '' : ' (غیرفعال)') + '</option>';
            });
            $sel.html(html);
            if (prev) $sel.val(prev);
        });
        ['caFieldCat', 'caRecordCat', 'caMonthlyCat'].forEach(function (sel) {
            var $sel = $('#' + sel);
            if (!$sel.val() && categories.length) {
                $sel.val(String(categories[0].id));
            }
        });
    }

    /* ============================================================
     * Categories
     * ========================================================== */
    function loadCategories(cb) {
        $.getJSON('assets/php/current_affairs_action.php', { action: 'cats' })
            .done(function (res) {
                if (!res.success || !res.data) return;
                state.categories = res.data.categories || [];
                renderCategories();
                fillCategorySelects(state.categories);
                if (cb) cb();
            })
            .fail(function () {
                $('#caCatsTable tbody').html('<tr><td colspan="7" class="empty-state">خطا در ارتباط با سرور.</td></tr>');
            });
    }

    function findCat(id) {
        var found = null;
        (state.categories || []).forEach(function (c) { if ((c.id | 0) === (id | 0)) found = c; });
        return found;
    }

    function renderCategories() {
        var cats = state.categories || [];
        var html = '';
        if (!cats.length) {
            html = '<tr class="empty-state-row"><td colspan="7" class="empty-state">دسته‌بندی‌ای یافت نشد.</td></tr>';
        } else {
            cats.forEach(function (c) {
                html += '<tr class="ca-cat-row" data-id="' + (c.id | 0) + '">'
                    + '<td>' + (c.id | 0) + '</td>'
                    + '<td><strong>' + esc(c.name) + '</strong></td>'
                    + '<td>' + esc(c.description || '-') + '</td>'
                    + '<td>' + (c.field_count | 0) + '</td>'
                    + '<td>' + (c.record_count | 0) + '</td>'
                    + '<td>' + (c.is_active ? '<span class="badge">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>') + '</td>'
                    + '<td class="action-buttons-row">'
                    + '<button type="button" class="button-secondary small ca-cat-edit">ویرایش</button> '
                    + '<button type="button" class="button-secondary small ca-cat-toggle">' + (c.is_active ? 'غیرفعال‌سازی' : 'فعال‌سازی') + '</button> '
                    + '<button type="button" class="button-secondary small ca-cat-del">حذف</button>'
                    + '</td></tr>';
            });
        }
        $('#caCatsTable tbody').html(html);
    }

    $('#caCatAdd').on('click', function () {
        $('#caCatModalTitle').text('دسته‌بندی جدید');
        $('#caCatId').val('');
        $('#caCatName').val('');
        $('#caCatDesc').val('');
        $('#caCatResult').text('').css('color', '');
        openModal('caCatModal');
        setTimeout(function () { $('#caCatName').focus(); }, 60);
    });

    $(document).on('click', '.ca-cat-edit', function () {
        var c = findCat($(this).closest('.ca-cat-row').data('id'));
        if (!c) return;
        $('#caCatModalTitle').text('ویرایش دسته‌بندی');
        $('#caCatId').val(c.id);
        $('#caCatName').val(c.name);
        $('#caCatDesc').val(c.description || '');
        $('#caCatResult').text('').css('color', '');
        openModal('caCatModal');
    });

    var caCatSaving = false;
    $('#caCatForm').on('submit', function (e) {
        e.preventDefault();
        if (caCatSaving) return;
        caCatSaving = true;
        var $btn = $('#caCatSaveBtn');
        $btn.prop('disabled', true).text('در حال ذخیره...');
        var restore = function () { caCatSaving = false; $btn.prop('disabled', false).text('ذخیره'); };
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'cat_save',
            id: $('#caCatId').val(),
            name: $('#caCatName').val(),
            description: $('#caCatDesc').val()
        }).done(function (res) {
            if (res.success) { closeModal('caCatModal'); loadCategories(); }
            else { showResult('caCatResult', res.message || 'خطا در ذخیره.', false); }
        }).fail(function () { showResult('caCatResult', 'خطا در ارتباط با سرور.', false); })
          .always(restore);
    });

    $(document).on('click', '.ca-cat-toggle', function () {
        var c = findCat($(this).closest('.ca-cat-row').data('id'));
        if (!c) return;
        var turningOff = (c.is_active === '1' || c.is_active === 1);
        if (turningOff && !confirm('دسته‌بندی «' + c.name + '» غیرفعال شود؟ داده‌ها حفظ می‌شوند.')) return;
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'cat_toggle',
            id: c.id,
            is_active: turningOff ? 0 : 1
        }).done(function (res) {
            if (res.success) { loadCategories(); } else { alert(res.message || 'خطا.'); }
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    $(document).on('click', '.ca-cat-del', function () {
        var c = findCat($(this).closest('.ca-cat-row').data('id'));
        if (!c) return;
        if (!confirm('آیا از حذف دسته‌بندی «' + c.name + '» اطمینان دارید؟ فقط دسته‌بندی بدون رکورد قابل حذف است.')) return;
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'cat_delete',
            id: c.id
        }).done(function (res) {
            if (res.success) { loadCategories(); } else { alert(res.message || 'خطا در حذف.'); }
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    /* ============================================================
     * Fields
     * ========================================================== */
    var fieldState = { fields: [] };

    function typeLabel(t) {
        var found = t;
        (window.CA_FIELD_TYPES || []).forEach(function (x) { if (x.value === t) found = x.label; });
        return found;
    }

    function loadFields(catId) {
        if (!catId) {
            $('#caFieldsTable tbody').html('<tr><td colspan="10" class="empty-state">ابتدا یک دسته‌بندی انتخاب کنید.</td></tr>');
            $('#caFieldCountBadge').text('—');
            $('#caFieldRepNote').text('تاریخ گزارش‌گیری تنظیم نشده است.');
            return;
        }
        $.getJSON('assets/php/current_affairs_action.php', { action: 'fields', category_id: catId })
            .done(function (res) {
                if (!res.success) return;
                fieldState.fields = res.data.fields || [];
                renderFields(fieldState.fields);
            })
            .fail(function () {
                $('#caFieldsTable tbody').html('<tr><td colspan="10" class="empty-state">خطا در دریافت فیلدها.</td></tr>');
            });
    }

    function renderFields(fields) {
        if (!fields.length) {
            $('#caFieldsTable tbody').html('<tr class="empty-state-row"><td colspan="10" class="empty-state">فیلدی تعریف نشده است. با دکمه «+ فیلد جدید» شروع کنید.</td></tr>');
            $('#caFieldCountBadge').text('۰ فیلد');
            $('#caFieldRepNote').text('تاریخ گزارش‌گیری تنظیم نشده است.');
            return;
        }
        var activeCount = 0;
        var repField = null;
        fields.forEach(function (f) { if (f.is_active) activeCount++; if (f.is_reporting_date === '1' || f.is_reporting_date === 1) repField = f; });

        var catId = $('#caFieldCat').val() || state.fieldCatId;
        var html = '';
        fields.forEach(function (f) {
            var isActive = (f.is_active === '1' || f.is_active === 1);
            var isRep = (f.is_reporting_date === '1' || f.is_reporting_date === 1);
            var repBadge = isRep
                ? '<span class="badge" title="تاریخ گزارش‌گیری" style="background:#fff3e0;color:#e65100;">📅 فعال</span>'
                : (f.field_type === 'date'
                    ? '<button type="button" class="button-secondary small ca-field-setrep" data-cat="' + catId + '" data-id="' + (f.id | 0) + '">تعیین تاریخ گزارش‌گیری</button>'
                    : '<span class="setting-note">—</span>');
            html += '<tr class="ca-field-row" data-id="' + (f.id | 0) + '">'
                + '<td>' + esc(f.sort_order) + '</td>'
                + '<td><strong>' + esc(f.label) + '</strong><br><small class="setting-note" dir="ltr">' + esc(f.name) + '</small></td>'
                + '<td>' + esc(typeLabel(f.field_type)) + '</td>'
                + '<td>' + (f.is_required ? '<span class="badge">بله</span>' : '—') + '</td>'
                + '<td>' + (f.show_in_table ? '<span class="badge">بله</span>' : '—') + '</td>'
                + '<td>' + (f.is_filterable ? '<span class="badge">بله</span>' : '—') + '</td>'
                + '<td>' + (f.include_in_totals ? '<span class="badge">بله</span>' : '—') + '</td>'
                + '<td>' + (isActive ? '<span class="badge">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>') + '</td>'
                + '<td>' + repBadge + '</td>'
                + '<td class="action-buttons-row">'
                + '<button type="button" class="button-secondary small ca-field-edit">ویرایش</button> '
                + '<button type="button" class="button-secondary small ca-field-toggle">' + (isActive ? 'غیرفعال' : 'فعال') + '</button> '
                + '<button type="button" class="button-secondary small ca-field-del">حذف</button>'
                + '</td></tr>';
        });
        $('#caFieldsTable tbody').html(html);
        $('#caFieldCountBadge').text(numFmt(fields.length) + ' فیلد (' + numFmt(activeCount) + ' فعال)');
        $('#caFieldRepNote').text(repField
            ? 'تاریخ گزارش‌گیری: «' + repField.label + '» — مبنای گزارش ماهانه است.'
            : 'هشدار: این دسته‌بندی تاریخ گزارش‌گیری فعالی ندارد؛ گزارش ماهانه تا تعیین آن مسدود است.');
        $('#caFieldRepNote').css('color', repField ? '' : '#e65100');
    }

    $('#caFieldCat').on('change', function () {
        state.fieldCatId = parseInt($(this).val(), 10) || 0;
        loadFields(state.fieldCatId);
    });

    $('#caFieldRefresh').on('click', function () {
        state.fieldCatId = parseInt($('#caFieldCat').val(), 10) || 0;
        loadFields(state.fieldCatId);
    });

    function fillFieldTypeSelect(selected) {
        var html = '';
        (window.CA_FIELD_TYPES || []).forEach(function (t) {
            var sel = t.value === selected ? ' selected' : '';
            html += '<option value="' + escAttr(t.value) + '"' + sel + '>' + esc(t.label) + '</option>';
        });
        $('#caFieldType').html(html);
    }

    function syncFieldModalRows() {
        var t = $('#caFieldType').val();
        $('#caFieldOptionsRow').toggle(t === 'select');
        $('#caFieldRepRow').toggle(t === 'date');
    }
    $('#caFieldType').on('change', syncFieldModalRows);

    function openFieldModal(f) {
        $('#caFieldResult').text('').css('color', '');
        if (f) {
            $('#caFieldModalTitle').text('ویرایش فیلد');
            $('#caFieldId').val(f.id);
            $('#caFieldLabel').val(f.label);
            $('#caFieldSort').val(f.sort_order);
            fillFieldTypeSelect(f.field_type);
            $('#caFieldType').prop('disabled', f.values_count > 0);
            $('#caFieldTypeLock').toggle(f.values_count > 0);
            $('#caFieldRequired').prop('checked', f.is_required === '1' || f.is_required === 1);
            $('#caFieldShowTable').prop('checked', f.show_in_table === '1' || f.show_in_table === 1);
            $('#caFieldFilterable').prop('checked', f.is_filterable === '1' || f.is_filterable === 1);
            $('#caFieldInTotals').prop('checked', f.include_in_totals === '1' || f.include_in_totals === 1);
            $('#caFieldActive').prop('checked', f.is_active === '1' || f.is_active === 1);
            $('#caFieldIsRep').prop('checked', f.is_reporting_date === '1' || f.is_reporting_date === 1);
            var opts = Array.isArray(f.options) && f.options.length
                ? f.options.map(function (o) { return o.option_value; }) : [];
            $('#caFieldOptions').val(opts.join('\n'));
        } else {
            $('#caFieldModalTitle').text('فیلد جدید');
            $('#caFieldId').val('');
            $('#caFieldLabel').val('');
            $('#caFieldSort').val(fieldState.fields.length + 1);
            fillFieldTypeSelect('text');
            $('#caFieldType').prop('disabled', false);
            $('#caFieldTypeLock').hide();
            $('#caFieldRequired').prop('checked', false);
            $('#caFieldShowTable').prop('checked', true);
            $('#caFieldFilterable').prop('checked', true);
            $('#caFieldInTotals').prop('checked', false);
            $('#caFieldActive').prop('checked', true);
            $('#caFieldIsRep').prop('checked', false);
            $('#caFieldOptions').val('');
        }
        syncFieldModalRows();
        openModal('caFieldModal');
    }

    $('#caFieldAdd').on('click', function () {
        var catId = parseInt($('#caFieldCat').val(), 10) || 0;
        if (!catId) { alert('ابتدا یک دسته‌بندی انتخاب کنید.'); return; }
        $('#caFieldCatId').val(catId);
        openFieldModal(null);
    });

    $(document).on('click', '.ca-field-edit', function () {
        var fid = $(this).closest('.ca-field-row').data('id');
        $('#caFieldCatId').val($('#caFieldCat').val());
        var f = null;
        fieldState.fields.forEach(function (x) { if ((x.id | 0) === (fid | 0)) f = x; });
        if (f) openFieldModal(f);
    });

    var caFieldSaving = false;
    $('#caFieldForm').on('submit', function (e) {
        e.preventDefault();
        if (caFieldSaving) return;
        var catId = $('#caFieldCatId').val();
        if (!catId) { alert('دسته‌بندی انتخاب نشده است.'); return; }
        var options = [];
        if ($('#caFieldType').val() === 'select') {
            options = String($('#caFieldOptions').val() || '')
                .split(/\r?\n/).map(function (s) { return { value: s.trim() }; })
                .filter(function (o) { return o.value !== ''; });
            if (!options.length) { showResult('caFieldResult', 'فیلد انتخابی حداقل یک گزینه لازم دارد.', false); return; }
        }
        caFieldSaving = true;
        var $btn = $('#caFieldSaveBtn');
        $btn.prop('disabled', true).text('در حال ذخیره...');
        var restore = function () { caFieldSaving = false; $btn.prop('disabled', false).text('ذخیره'); };
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'field_save',
            id: $('#caFieldId').val(),
            category_id: catId,
            label: $('#caFieldLabel').val(),
            field_type: $('#caFieldType').val(),
            sort_order: $('#caFieldSort').val() || 0,
            is_required: $('#caFieldRequired').is(':checked') ? 1 : 0,
            show_in_table: $('#caFieldShowTable').is(':checked') ? 1 : 0,
            is_filterable: $('#caFieldFilterable').is(':checked') ? 1 : 0,
            include_in_totals: $('#caFieldInTotals').is(':checked') ? 1 : 0,
            is_active: $('#caFieldActive').is(':checked') ? 1 : 0,
            is_reporting_date: ($('#caFieldType').val() === 'date' && $('#caFieldIsRep').is(':checked')) ? 1 : 0,
            options: JSON.stringify(options)
        }).done(function (res) {
            if (res.success) { closeModal('caFieldModal'); loadFields(catId); }
            else { showResult('caFieldResult', res.message || 'خطا در ذخیره فیلد.', false); }
        }).fail(function () { showResult('caFieldResult', 'خطا در ارتباط با سرور.', false); })
          .always(restore);
    });

    function findField(fid) {
        var found = null;
        fieldState.fields.forEach(function (x) { if ((x.id | 0) === (fid | 0)) found = x; });
        return found;
    }

    $(document).on('click', '.ca-field-toggle', function () {
        var f = findField($(this).closest('.ca-field-row').data('id'));
        if (!f) return;
        var turningOff = (f.is_active === '1' || f.is_active === 1);
        if (turningOff && (f.is_reporting_date === '1' || f.is_reporting_date === 1)) {
            alert('این فیلد «تاریخ گزارش‌گیری» است؛ با غیرفعال‌سازی، تعیین آن حذف می‌شود و گزارش ماهانه مسدود می‌ماند.');
        }
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'field_toggle',
            id: f.id,
            is_active: turningOff ? 0 : 1
        }).done(function (res) {
            if (res.success) { loadFields($('#caFieldCat').val()); if (res.message) alert(res.message); }
            else { alert(res.message || 'خطا.'); }
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    $(document).on('click', '.ca-field-del', function () {
        var f = findField($(this).closest('.ca-field-row').data('id'));
        if (!f) return;
        if (!confirm('آیا از حذف فیلد «' + f.label + '» اطمینان دارید؟ فیلد دارای مقدار تاریخی قابل حذف نیست.')) return;
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'field_delete',
            id: f.id
        }).done(function (res) {
            if (res.success) { loadFields($('#caFieldCat').val()); }
            else { alert(res.message || 'خطا در حذف.'); }
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    $(document).on('click', '.ca-field-setrep', function () {
        var catId = $(this).data('cat');
        var fid = $(this).data('id');
        if (!confirm('این فیلد به‌عنوان «تاریخ گزارش‌گیری» تعیین شود؟ تعیین قبلی همین دسته‌بندی حذف می‌شود.')) return;
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'set_reporting_date',
            category_id: catId,
            field_id: fid
        }).done(function (res) {
            if (res.success) { loadFields(catId); alert(res.message); }
            else { alert(res.message || 'خطا در تعیین.'); }
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    /* ============================================================
     * Records
     * ========================================================== */
    var recordState = {
        catId: 0, page: 1, perPage: 10, sort: '__id', dir: 'desc',
        search: '', filters: {}, filterDraft: {}, fields: [], columns: [], filtersMeta: [], reporting: null
    };

    function recordQueryParams(page) {
        return {
            action: 'records',
            category_id: recordState.catId,
            page: page || recordState.page,
            per_page: recordState.perPage,
            search: recordState.search,
            filters: JSON.stringify(recordState.filters),
            sort: recordState.sort,
            dir: recordState.dir
        };
    }

    function loadRecords(page) {
        var catId = parseInt($('#caRecordCat').val(), 10) || 0;
        if (!catId) {
            $('#caRecordsTable thead').empty();
            $('#caRecordsTable tbody').html('<tr><td class="empty-state">ابتدا یک دسته‌بندی انتخاب کنید.</td></tr>');
            $('#caRecordsInfo').text('—');
            $('#caRecordsPagination').empty();
            $('#caRecordTotals').empty();
            $('#caRecordFilters').empty();
            return;
        }
        if (recordState.catId !== catId) {
            recordState.catId = catId;
            recordState.filters = {};
            recordState.search = '';
            recordState.sort = '__id';
            recordState.dir = 'desc';
            $('#caRecordSearch').val('');
        }
        $('#caRecordsTable tbody').html('<tr><td colspan="5" class="empty-state">در حال بارگذاری...</td></tr>');
        $.getJSON('assets/php/current_affairs_action.php', recordQueryParams(page))
            .done(function (res) {
                if (!res.success || !res.data) {
                    $('#caRecordsTable tbody').html('<tr><td class="empty-state">' + esc(res.message || 'خطا') + '</td></tr>');
                    return;
                }
                var d = res.data;
                recordState.fields = d.fields || [];
                recordState.columns = d.columns || [];
                recordState.filtersMeta = d.filters || [];
                recordState.page = d.page || 1;
                recordState.reporting = d.reporting;
                renderRecordTable(d);
                renderRecordTotals(d.totals || []);
                renderRecordFilters();
                renderRecordPagination(d);
				updateRecordExportUrl();
            })
            .fail(function () {
                $('#caRecordsTable tbody').html('<tr><td class="empty-state">خطا در ارتباط با سرور.</td></tr>');
            });
    }

    function cellDisplay(type, cell) {
        var d = cell && cell.d != null ? cell.d : '';
        if (type === 'yesno') {
            if (cell && cell.v === '1') return '<span class="badge">بله</span>';
            if (cell && cell.v === '0') return '<span class="badge badge-danger">خیر</span>';
            return '';
        }
        if (type === 'money') return '<strong>' + esc(d) + '</strong>';
        return esc(d);
    }

    function renderRecordTable(d) {
        var cols = d.columns || [];
        var ths = '';
        cols.forEach(function (c) {
            var arrow = (c.sortable && c.key === recordState.sort)
                ? '<span class="sort-arrow">' + (recordState.dir === 'desc' ? '↓' : '↑') + '</span>' : '';
            var cls = c.sortable ? ' sortable' : ' no-sort';
            ths += '<th data-sort="' + escAttr(c.key) + '" class="' + cls + '">' + esc(c.label) + arrow + '</th>';
        });
        $('#caRecordsTable thead').html('<tr>' + ths + '</tr>');

        var rows = d.rows || [];
        var html;
        if (!rows.length) {
            html = '<tr class="empty-state-row"><td colspan="' + cols.length + '" class="empty-state">رکوردی یافت نشد.</td></tr>';
        } else {
            html = '';
            rows.forEach(function (r) {
                html += '<tr class="ca-record-row" data-id="' + escAttr(r.id) + '">';
                cols.forEach(function (c) {
                    if (c.key === '__no') { html += '<td>' + esc(r.__no) + '</td>'; return; }
                    if (c.key === '__created') { html += '<td>' + esc(r.__created) + '</td>'; return; }
                    if (c.key === '__actions') {
                        html += '<td class="action-buttons-row">'
                            + '<button type="button" class="button-secondary small ca-record-edit">ویرایش</button> '
                            + '<button type="button" class="button-secondary small ca-record-del">حذف</button>'
                            + '</td>';
                        return;
                    }
                    html += '<td>' + cellDisplay(c.type, r[c.key]) + '</td>';
                });
                html += '</tr>';
            });
        }
        $('#caRecordsTable tbody').html(html);
    }

    function renderRecordTotals(totals) {
        if (!totals.length) {
            $('#caRecordTotals').html('<span class="setting-note">مجموع‌هایی برای نمایش وجود ندارد (فیلد عددی/مبلغ با گزینه «شرکت در مجموع‌ها» تعریف کنید).</span>');
            return;
        }
        var html = '';
        totals.forEach(function (t) {
            html += '<span class="value-badge">جمع ' + esc(t.label) + ': <strong>' + esc(t.value) + '</strong></span>';
        });
        $('#caRecordTotals').html(html);
    }

    function caPagerBtn(page, disabled, label) {
        return '<button type="button" class="pagination-button" data-page="' + page + '"' + (disabled ? ' disabled' : '') + '>' + esc(label) + '</button>';
    }
    function renderRecordPagination(d) {
        $('#caRecordsInfo').text('نمایش ' + d.range_start + ' تا ' + d.range_end + ' از ' + d.total + ' رکورد');
        var pager = '';
        if (d.total_pages > 1) {
            var isFirst = d.page <= 1;
            var isLast = d.page >= d.total_pages;
            pager = caPagerBtn(1, isFirst, 'اولین')
                + caPagerBtn(d.page - 1, isFirst, 'قبلی')
                + '<span class="pagination-current">صفحه ' + d.page + ' از ' + d.total_pages + '</span>'
                + caPagerBtn(d.page + 1, isLast, 'بعدی')
                + caPagerBtn(d.total_pages, isLast, 'آخرین');
        }
        $('#caRecordsPagination').html(pager);
    }

    $('#caRecordCat').on('change', function () { loadRecords(1); });
    $('#caRecordRefresh').on('click', function () { loadRecords(recordState.page); });
    $('#caRecordSearch').on('input', function () {
        var self = this;
        if (recordState.searchTimer) clearTimeout(recordState.searchTimer);
        recordState.searchTimer = setTimeout(function () {
            recordState.search = String($(self).val() || '').trim();
            loadRecords(1);
        }, 250);
    });

    $('#caRecordsPagination').on('click', '.pagination-button', function () {
        if ($(this).prop('disabled')) return;
        loadRecords(parseInt($(this).data('page'), 10) || 1);
    });

    /* sortable headers */
    $('#caRecordsTable').on('click', 'th.sortable', function () {
        var key = $(this).data('sort');
        if (!key) return;
        if (recordState.sort === key) {
            recordState.dir = recordState.dir === 'asc' ? 'desc' : 'asc';
        } else {
            recordState.sort = key;
            recordState.dir = 'asc';
        }
        loadRecords(1);
    });

    /* ---------- server-side filter panel ---------- */
    function renderRecordFilters() {
        var meta = recordState.filtersMeta || [];
        var $wrap = $('#caRecordFilters');
        $wrap.empty();
        if (!meta.length) {
            $wrap.html('<span class="setting-note">فیلد قابل فیلتری تعریف نشده است.</span>');
            return;
        }
        meta.forEach(function (f) {
            var fid = f.id;
            var $item = $('<span class="ca-filter-item"></span>');
            $item.append('<span>' + esc(f.label) + ':</span>');
            if (f.type === 'text' || f.type === 'textarea') {
                $item.append('<input type="search" data-fid="' + fid + '" placeholder="جستجو...">');
            } else if (f.type === 'number' || f.type === 'money') {
                $item.append('<input type="number" data-fid="' + fid + '" data-bound="from" placeholder="از">');
                $item.append('<input type="number" data-fid="' + fid + '" data-bound="to" placeholder="تا">');
            } else if (f.type === 'date') {
                $item.append('<input type="text" id="caFld_' + fid + '_from" class="ca-date-display" data-fid="' + fid + '" data-bound="from" readonly placeholder="از تاریخ" style="width:130px;">');
                $item.append('<input type="text" id="caFld_' + fid + '_to" class="ca-date-display" data-fid="' + fid + '" data-bound="to" readonly placeholder="تا تاریخ" style="width:130px;">');
                $item.append('<input type="hidden" data-fid="' + fid + '" data-bound="from" class="ca-date-iso">');
                $item.append('<input type="hidden" data-fid="' + fid + '" data-bound="to" class="ca-date-iso">');
            } else if (f.type === 'select') {
                var opts = f.options || [];
                var selHtml = '<select data-fid="' + fid + '"><option value="">همه</option>';
                opts.forEach(function (o) { selHtml += '<option value="' + escAttr(o) + '">' + esc(o) + '</option>'; });
                selHtml += '</select>';
                $item.append(selHtml);
            } else if (f.type === 'yesno') {
                $item.append('<select data-fid="' + fid + '"><option value="">همه</option><option value="1">بله</option><option value="0">خیر</option></select>');
            }
            var cur = caFilterValue(fid);
            $item.find('.ca-date-iso[data-fid="' + fid + '"][data-bound="from"]').val(cur.from || '');
            $item.find('.ca-date-iso[data-fid="' + fid + '"][data-bound="to"]').val(cur.to || '');
            if (f.type === 'date') {
                $item.find('.ca-date-display[data-fid="' + fid + '"][data-bound="from"]').val(cur.from ? gregInputToJalaliStr(cur.from) : '');
                $item.find('.ca-date-display[data-fid="' + fid + '"][data-bound="to"]').val(cur.to ? gregInputToJalaliStr(cur.to) : '');
            } else {
                $item.find('[data-fid="' + fid + '"][data-bound="from"]').not('.ca-date-iso').val(cur.from || '');
                $item.find('[data-fid="' + fid + '"][data-bound="to"]').not('.ca-date-iso').val(cur.to || '');
            }
            $item.find('input[type="search"][data-fid="' + fid + '"]').val(cur.value || '');
            $item.find('select[data-fid="' + fid + '"]').val(cur.value || '');
            $wrap.append($item);
        });
        $wrap.append('<button type="button" class="button-secondary small" id="caRecordFiltersApply">اعمال فیلتر</button>');
        $wrap.append('<button type="button" class="button-secondary small" id="caRecordFiltersClear">حذف فیلترها</button>');
        initFilterDatePickers();
    }

    /* Typed-but-unapplied filter values stay available while the user sorts
     * or pages (the panel is rebuilt from the applied filters after each
     * request). caFilterDraft holds the live values; Apply commits them. */
    function caFilterValue(fid) {
        var d = recordState.filterDraft && recordState.filterDraft[String(fid)];
        if (d) return d;
        return recordState.filters[String(fid)] || {};
    }
    function snapshotFilterDraft() {
        var draft = {};
        (recordState.filtersMeta || []).forEach(function (f) {
            var spec = {};
            var any = false;
            if (f.type === 'text' || f.type === 'textarea') {
                var v = String($('#caRecordFilters input[type="search"][data-fid="' + f.id + '"]').val() || '').trim();
                if (v) { spec.value = v; any = true; }
            } else if (f.type === 'number' || f.type === 'money' || f.type === 'date') {
                var from = String($('#caRecordFilters input[data-fid="' + f.id + '"][data-bound="from"].ca-date-iso').val()
                    || $('#caRecordFilters input[type="number"][data-fid="' + f.id + '"][data-bound="from"]').val() || '').trim();
                var to = String($('#caRecordFilters input[data-fid="' + f.id + '"][data-bound="to"].ca-date-iso').val()
                    || $('#caRecordFilters input[type="number"][data-fid="' + f.id + '"][data-bound="to"]').val() || '').trim();
                if (from) { spec.from = from; any = true; }
                if (to) { spec.to = to; any = true; }
            } else {
                var sv = String($('#caRecordFilters select[data-fid="' + f.id + '"]').val() || '').trim();
                if (sv) { spec.value = sv; any = true; }
            }
            if (any) draft[String(f.id)] = spec;
        });
        recordState.filterDraft = draft;
    }
    $(document).on('input change.ca-draft', '#caRecordFilters input, #caRecordFilters select', snapshotFilterDraft);

    function initFilterDatePickers() {
        $('.ca-date-display').each(function () {
            var $display = $(this);
            if ($display.data('capick')) return;
            $display.data('capick', true);
            if (window.kamaDatepicker && $display.attr('id')) {
                window.kamaDatepicker($display.attr('id'), {
                    placeholder: '', twodigit: true, closeAfterSelect: true,
                    nextButtonIcon: 'بعدی', previousButtonIcon: 'قبلی',
                    forceFarsiDigits: true, markToday: true, highlightSelectedDay: true,
                    sync: true, gotoToday: true
                });
            }
            $display.off('change.caDate').on('change.caDate', function () {
                var g = jalaliStrToGregInput($display.val());
                $display.closest('.ca-filter-item')
                    .find('.ca-date-iso[data-fid="' + $display.data('fid') + '"][data-bound="' + $display.data('bound') + '"]')
                    .val(g ? g : '');
            });
            var g2 = jalaliStrToGregInput($display.val());
            if (g2) {
                $display.closest('.ca-filter-item')
                    .find('.ca-date-iso[data-fid="' + $display.data('fid') + '"][data-bound="' + $display.data('bound') + '"]')
                    .val(g2);
            }
        });
    }

    function collectRecordFilters() {
        var filters = {};
        recordState.filtersMeta.forEach(function (f) {
            var spec = {};
            var any = false;
            if (f.type === 'text' || f.type === 'textarea') {
                var v = String($('#caRecordFilters input[type="search"][data-fid="' + f.id + '"]').val() || '').trim();
                if (v) { spec.value = v; any = true; }
            } else if (f.type === 'number' || f.type === 'money' || f.type === 'date') {
                var from = String($('#caRecordFilters input[data-fid="' + f.id + '"][data-bound="from"].ca-date-iso').val()
                    || $('#caRecordFilters input[type="number"][data-fid="' + f.id + '"][data-bound="from"]').val() || '').trim();
                var to = String($('#caRecordFilters input[data-fid="' + f.id + '"][data-bound="to"].ca-date-iso').val()
                    || $('#caRecordFilters input[type="number"][data-fid="' + f.id + '"][data-bound="to"]').val() || '').trim();
                if (from) { spec.from = from; any = true; }
                if (to) { spec.to = to; any = true; }
            } else {
                var sv = String($('#caRecordFilters select[data-fid="' + f.id + '"]').val() || '').trim();
                if (sv) { spec.value = sv; any = true; }
            }
            if (any) filters[String(f.id)] = spec;
        });
        return filters;
    }

    $(document).on('click', '#caRecordFiltersApply', function () {
        recordState.filters = collectRecordFilters();
        snapshotFilterDraft();
        loadRecords(1);
    });
    $(document).on('click', '#caRecordFiltersClear', function () {
        recordState.filters = {};
        recordState.filterDraft = {};
        renderRecordFilters();
        loadRecords(1);
    });

    function updateRecordExportUrl() {
    var catId = parseInt($('#caRecordCat').val(), 10) || 0;
    if (!catId) {
        $('#caRecordExport').attr('href', '#');
        return;
    }
    var qs = 'type=category&category_id=' + catId
        + '&search=' + encodeURIComponent(recordState.search)
        + '&filters=' + encodeURIComponent(JSON.stringify(recordState.filters));
    $('#caRecordExport').attr('href', 'assets/php/current_affairs_export.php?' + qs);
}

    /* ---------- record modal (dynamic form) ---------- */
    function renderRecordFormFields(fields, values) {
        values = values || {};
        var html = '';
        fields.forEach(function (f) {
            var val = values[String(f.id)] != null ? String(values[String(f.id)]) : '';
            var req = f.is_required ? ' <span class="required">*</span>' : '';
            if (f.field_type === 'textarea') {
                html += '<div class="form-row" style="grid-column: span 2;"><label>' + esc(f.label) + req + '</label>'
                    + '<textarea class="ca-record-val" data-fid="' + f.id + '" rows="3">' + escAttr(val) + '</textarea></div>';
            } else if (f.field_type === 'text' || f.field_type === 'number' || f.field_type === 'money') {
                var inputType = f.field_type === 'number' ? 'number' : 'text';
                html += '<div class="form-row"><label>' + esc(f.label) + req + '</label>'
                    + '<input type="' + inputType + '" class="ca-record-val" data-fid="' + f.id + '" value="' + escAttr(val) + '"'
                    + (f.field_type === 'text' ? ' maxlength="500"' : '') + '></div>';
            } else if (f.field_type === 'date') {
                html += '<div class="form-row"><label>' + esc(f.label) + req + '</label>'
                    + '<input type="text" id="caRec_' + f.id + '_disp" class="ca-record-date-display" readonly data-fid="' + f.id + '" value="' + escAttr(val ? gregInputToJalaliStr(val) : '') + '">'
                    + '<input type="hidden" class="ca-record-date-iso ca-record-val" data-fid="' + f.id + '" value="' + escAttr(val) + '"></div>';
            } else if (f.field_type === 'select') {
                var opts = f.options || [];
                var hasStored = false;
                opts.forEach(function (o) { if (val === String(o)) hasStored = true; });
                var sel = '<select class="ca-record-val" data-fid="' + f.id + '"><option value="">— انتخاب کنید —</option>';
                if (val !== '' && !hasStored) {
                    sel += '<option value="' + escAttr(val) + '" selected>' + esc(val) + ' (قبلی)</option>';
                }
                opts.forEach(function (o) {
                    sel += '<option value="' + escAttr(o) + '"' + (val === String(o) ? ' selected' : '') + '>' + esc(o) + '</option>';
                });
                sel += '</select>';
                html += '<div class="form-row"><label>' + esc(f.label) + req + '</label>' + sel + '</div>';
            } else if (f.field_type === 'yesno') {
                html += '<div class="form-row"><label>' + esc(f.label) + req + '</label>'
                    + '<select class="ca-record-val" data-fid="' + f.id + '"><option value="">—</option>'
                    + '<option value="1"' + (val === '1' ? ' selected' : '') + '>بله</option>'
                    + '<option value="0"' + (val === '0' ? ' selected' : '') + '>خیر</option></select></div>';
            }
        });
        $('#caRecordDynamicFields').html(html || '<p class="setting-note">این دسته‌بندی فیلد فعالی ندارد.</p>');
        initRecordDatePickers();
    }

function initRecordDatePickers() {
    $('.ca-record-date-display').each(function () {
        var $display = $(this);
        if ($display.data('capick')) return;
        $display.data('capick', true);
        if (window.kamaDatepicker && $display.attr('id')) {
            window.kamaDatepicker($display.attr('id'), {
                placeholder: '', twodigit: true, closeAfterSelect: true,
                nextButtonIcon: 'بعدی', previousButtonIcon: 'قبلی',
                forceFarsiDigits: true, markToday: true, highlightSelectedDay: true,
                sync: true, gotoToday: true
            });
        }

        // تابع کمکی برای همگام‌سازی input مخفی
        function syncHidden() {
            var g = jalaliStrToGregInput($display.val());
            var fid = $display.data('fid');
            $display.closest('.form-row').find('.ca-record-date-iso[data-fid="' + fid + '"]').val(g ? g : '');
        }

        // روی چند رویداد گوش بده تا مطمئن شویم hidden همیشه sync است
        $display.off('.caRecDate')
            .on('change.caRecDate', syncHidden)
            .on('blur.caRecDate', syncHidden)
            .on('input.caRecDate', syncHidden);

        // مقدارگذاری اولیه
        syncHidden();
    });
}

    function openRecordModal(recordId) {
        $('#caRecordResult').text('').css('color', '');
        var fields = recordState.fields;
        if (!recordId) {
            $('#caRecordModalTitle').text('رکورد جدید');
            $('#caRecordId').val('');
            renderRecordFormFields(fields, {});
            openModal('caRecordModal');
            return;
        }
        $.getJSON('assets/php/current_affairs_action.php', { action: 'record_get', id: recordId })
            .done(function (res) {
                var rec = res.data && res.data.record;
                if (!rec) { alert('رکورد یافت نشد.'); return; }
                $('#caRecordModalTitle').text('ویرایش رکورد #' + rec.id);
                $('#caRecordId').val(rec.id);
                var vals = {};
                Object.keys(rec.values || {}).forEach(function (fid) {
                    var v = rec.values[fid];
                    var f = null;
                    fields.forEach(function (x) { if (String(x.id) === fid) f = x; });
                    if (!f) return;
                    if (f.field_type === 'number' || f.field_type === 'money') vals[fid] = (v.value_number != null ? String(v.value_number) : '');
                    else if (f.field_type === 'date') vals[fid] = (v.value_date != null ? v.value_date : '');
                    else vals[fid] = (v.value_text != null ? v.value_text : '');
                });
                renderRecordFormFields(fields, vals);
                openModal('caRecordModal');
            })
            .fail(function () { alert('خطا در دریافت رکورد.'); });
    }

    $('#caRecordAdd').on('click', function () {
        if (!recordState.catId) { alert('ابتدا یک دسته‌بندی انتخاب کنید.'); return; }
        $('#caRecordCatId').val(recordState.catId);
        openRecordModal(0);
    });

    $(document).on('click', '.ca-record-edit', function () {
        $('#caRecordCatId').val(recordState.catId);
        openRecordModal($(this).closest('.ca-record-row').data('id'));
    });

    var caRecordSaving = false;
    $('#caRecordForm').on('submit', function (e) {
        e.preventDefault();
        if (caRecordSaving) return;
        var values = {};
		$('#caRecordDynamicFields .ca-record-val').each(function () {
			var $el = $(this);
			var fid = $el.data('fid');

			// input نمایشی تاریخ را نادیده بگیر
			if ($el.hasClass('ca-record-date-display')) {
				return;
			}

			var v = String($el.val() || '').trim();
			if (v !== '') values[String(fid)] = v;
		});
        caRecordSaving = true;
        var $btn = $('#caRecordSaveBtn');
        $btn.prop('disabled', true).text('در حال ذخیره...');
        var restore = function () { caRecordSaving = false; $btn.prop('disabled', false).text('ذخیره'); };
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'record_save',
            id: $('#caRecordId').val(),
            category_id: $('#caRecordCatId').val(),
            values: JSON.stringify(values)
        }).done(function (res) {
            if (res.success) { closeModal('caRecordModal'); loadRecords(recordState.page); }
            else { showResult('caRecordResult', res.message || 'خطا در ذخیره رکورد.', false); }
        }).fail(function () { showResult('caRecordResult', 'خطا در ارتباط با سرور.', false); })
          .always(restore);
    });

    $(document).on('click', '.ca-record-del', function () {
        var rid = $(this).closest('.ca-record-row').data('id');
        if (!confirm('آیا از حذف این رکورد اطمینان دارید؟ این عمل غیرقابل بازگشت است.')) return;
        $.post('assets/php/current_affairs_action.php', {
            csrf_token: csrf,
            action: 'record_delete',
            id: rid
        }).done(function (res) {
            if (res.success) { loadRecords(recordState.page); }
            else { alert(res.message || 'خطا در حذف.'); }
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    });

    /* ============================================================
     * Monthly Reporting
     * ========================================================== */
    var monthlyState = { year: curYear, month: curMonth, page: 1, sort: '__id', dir: 'desc' };

    function fillMonthlyOptions() {
        var yearHtml = '';
        for (var y = curYear - 5; y <= curYear + 1; y++) {
            yearHtml += '<option value="' + y + '"' + (y === curYear ? ' selected' : '') + '>' + y + '</option>';
        }
        $('#caMonthlyYear').html(yearHtml);
        var monthHtml = '';
        (window.CA_MONTHS || []).forEach(function (m) {
            var sel = m.value === curMonth ? ' selected' : '';
            monthHtml += '<option value="' + m.value + '"' + sel + '>' + m.label + '</option>';
        });
        $('#caMonthlyMonth').html(monthHtml);
    }

    $('#caMonthlyYear').on('change', function () { monthlyState.year = parseInt($(this).val(), 10) || curYear; });
    $('#caMonthlyMonth').on('change', function () { monthlyState.month = parseInt($(this).val(), 10) || curMonth; });
    $('#caMonthlyGenerate').on('click', function () {
        var $btn = $('#caMonthlyGenerate');
        $btn.prop('disabled', true).text('در حال تولید...');
        loadMonthly(1, function () {
            $btn.prop('disabled', false).text('تولید گزارش');
        });
    });
    $('#caMonthlyPagination').on('click', '.pagination-button', function () {
        if ($(this).prop('disabled')) return;
        loadMonthly(parseInt($(this).data('page'), 10) || 1);
    });
    $('#caMonthlyTable').on('click', 'th.sortable', function () {
        var key = $(this).data('sort');
        if (!key) return;
        if (monthlyState.sort === key) {
            monthlyState.dir = monthlyState.dir === 'asc' ? 'desc' : 'asc';
        } else {
            monthlyState.sort = key;
            monthlyState.dir = 'asc';
        }
        loadMonthly(1);
    });

    function loadMonthly(page, done) {
        var catId = parseInt($('#caMonthlyCat').val(), 10) || 0;
        if (!catId) {
            alert('ابتدا یک دسته‌بندی انتخاب کنید.');
            if (done) done();
            return;
        }
        $('#caMonthlyTable thead').empty();
        $('#caMonthlyTable tbody').html('<tr><td colspan="5" class="empty-state">در حال تولید گزارش...</td></tr>');
        $('#caMonthlyInfo').text('—');
        $('#caMonthlyPagination').empty();
        $.getJSON('assets/php/current_affairs_action.php', {
            action: 'monthly',
            category_id: catId,
            year: monthlyState.year,
            month: monthlyState.month,
            page: page || monthlyState.page,
            per_page: 10,
            sort: monthlyState.sort,
            dir: monthlyState.dir
        }).done(function (res) {
            if (!res.success) {
                $('#caMonthlySummary').html('<span class="badge badge-danger">گزارش تولید نشد</span>');
                var msg = res.message || 'خطا در تولید گزارش.';
                if (res.data && res.data.no_reporting_date) {
                    msg += ' به بخش «فیلدها» بروید و یک فیلد تاریخ فعال را به‌عنوان تاریخ گزارش‌گیری تعیین کنید.';
                }
                $('#caMonthlyTable tbody').html('<tr><td class="empty-state">' + esc(msg) + '</td></tr>');
                $('#caMonthlyInfo').text('—');
                return;
            }
            var d = res.data;
            monthlyState.page = d.page || 1;
            renderMonthlySummary(d);
            renderMonthlyTable(d);
            renderMonthlyPagination(d);
            $('#caMonthlyExport').attr('href',
                'assets/php/current_affairs_export.php?type=monthly&category_id=' + catId
                + '&year=' + monthlyState.year + '&month=' + monthlyState.month);
        }).fail(function () {
            $('#caMonthlyTable tbody').html('<tr><td class="empty-state">خطا در ارتباط با سرور.</td></tr>');
        }).always(function () {
            if (done) done();
        });
    }

    function renderMonthlySummary(d) {
        var html = '<span class="value-badge"><strong>' + esc(d.month_name + ' ' + d.year) + '</strong></span>'
            + '<span class="value-badge">بازه: ' + esc(d.range_display) + '</span>'
            + '<span class="value-badge">تعداد رکورد: ' + numFmt(d.total) + '</span>'
            + '<span class="value-badge">تاریخ گزارش‌گیری: ' + esc(d.reporting_label) + '</span>';
        (d.totals || []).forEach(function (t) {
            html += '<span class="value-badge">جمع ' + esc(t.label) + ': <strong>' + esc(t.value) + '</strong></span>';
        });
        $('#caMonthlySummary').html(html);
    }

    function renderMonthlyTable(d) {
        var cols = d.columns || [];
        var ths = '';
        cols.forEach(function (c) {
            var arrow = (c.sortable && c.key === monthlyState.sort)
                ? '<span class="sort-arrow">' + (monthlyState.dir === 'desc' ? '↓' : '↑') + '</span>' : '';
            var cls = c.sortable ? ' sortable' : ' no-sort';
            ths += '<th data-sort="' + escAttr(c.key) + '" class="' + cls + '">' + esc(c.label) + arrow + '</th>';
        });
        $('#caMonthlyTable thead').html('<tr>' + ths + '</tr>');

        var rows = d.rows || [];
        var html;
        if (!rows.length) {
            html = '<tr class="empty-state-row"><td colspan="' + cols.length + '" class="empty-state">در این ماه رکوردی یافت نشد.</td></tr>';
        } else {
            html = '';
            rows.forEach(function (r) {
                html += '<tr>';
                cols.forEach(function (c) {
                    if (c.key === '__no') { html += '<td>' + esc(r.__no) + '</td>'; return; }
                    if (c.key === '__created') { html += '<td>' + esc(r.__created) + '</td>'; return; }
                    if (c.key === '__actions') { html += '<td>—</td>'; return; }
                    html += '<td>' + cellDisplay(c.type, r[c.key]) + '</td>';
                });
                html += '</tr>';
            });
        }
        $('#caMonthlyTable tbody').html(html);
    }

    function renderMonthlyPagination(d) {
        $('#caMonthlyInfo').text('نمایش ' + d.range_start + ' تا ' + d.range_end + ' از ' + d.total + ' رکورد');
        var pager = '';
        if (d.total_pages > 1) {
            var isFirst = d.page <= 1;
            var isLast = d.page >= d.total_pages;
            pager = caPagerBtn(1, isFirst, 'اولین')
                + caPagerBtn(d.page - 1, isFirst, 'قبلی')
                + '<span class="pagination-current">صفحه ' + d.page + ' از ' + d.total_pages + '</span>'
                + caPagerBtn(d.page + 1, isLast, 'بعدی')
                + caPagerBtn(d.total_pages, isLast, 'آخرین');
        }
        $('#caMonthlyPagination').html(pager);
    }

    /* ============================================================
     * Init
     * ========================================================== */
    bindCaModalHandlers();
    fillMonthlyOptions();
    loadCategories(function () {
        loadRecords(1);
        loadFields(parseInt($('#caFieldCat').val(), 10) || 0);
    });
});