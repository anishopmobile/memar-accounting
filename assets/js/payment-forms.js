/* ============================================================
 * payment-forms.js — فرمهای پرداخت (مدیریت فرم پرداخت)
 * jQuery + AJAX + JSON، سازگار با ساختار فعلی پروژه.
 * ========================================================== */
$(function () {
    if ($('body').attr('id') !== 'payment-forms') return;

    var csrf = String($('#paymentFormsCsrfToken').val() || '');
    var types = window.PAYMENT_FIELD_TYPES || [];
    var isSelectingType = ['select', 'radio', 'checkbox'];

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function optHtml() {
        var labels = { name: 'نام', mobile: 'موبایل', email: 'ایمیل', description: 'توضیحات', text: 'متن', textarea: 'متن بلند', number: 'عدد', select: 'انتخابی', radio: 'تکگزین', checkbox: 'چکباکس' };
        return $.map(types, function (t) {
            return '<option value="' + esc(t) + '">' + esc(labels[t] || t) + '</option>';
        }).join('');
    }

    function addFieldRow(data) {
        data = data || {};
        var optionsVal = (data.options && data.options.length) ? data.options.join('\n') : '';
        var row = $('<tr>')
            .append('<td><input type="text" class="pf-fkey" placeholder="mobile" value="' + esc(data.key) + '"></td>')
            .append('<td><input type="text" class="pf-flabel" placeholder="برچسب" value="' + esc(data.label) + '"></td>')
            .append('<td><select class="pf-ftype">' + optHtml() + '</select></td>')
            .append('<td><input type="checkbox" class="pf-frequired"' + (data.required ? ' checked' : '') + '></td>')
            .append('<td><textarea class="pf-foptions" rows="2" placeholder="گزینهها (هر کدام در یک خط)">' + esc(optionsVal) + '</textarea></td>')
            .append('<td><button type="button" class="button-danger small pf-field-remove">×</button></td>');
        row.find('.pf-ftype').val(data.type || 'text');
        toggleOptionsCell(row);
        $('#pfFieldsTable tbody').append(row);
    }

    function toggleOptionsCell(row) {
        var type = row.find('.pf-ftype').val();
        if ($.inArray(type, isSelectingType) !== -1) {
            row.find('.pf-foptions').prop('disabled', false);
        } else {
            row.find('.pf-foptions').prop('disabled', true);
        }
    }

    function setAmountMode(mode) {
        $('.amount-fixed').toggle(mode === 'fixed');
        $('.amount-custom').toggle(mode === 'custom');
        $('.amount-selectable').toggle(mode === 'selectable');
    }

    function openEditor(form) {
        form = form || {};
        $('#paymentFormId').val(form.id || 0);
        $('#paymentFormEditorTitle').text(form.id ? 'ویرایش فرم پرداخت' : 'فرم پرداخت جدید');
        $('#pfTitle').val(form.title || '');
        $('#pfSlug').val(form.slug || '');
        $('#pfDescription').val(form.description || '');
        $('#pfPurpose').val(form.purpose || '');
        $('#pfTheme').val(form.theme || 'default');
        $('#pfAmountMode').val(form.amount_mode || 'fixed');
        $('#pfFixedAmount').val(form.fixed_amount || '');
        $('#pfMinAmount').val(form.min_amount || '');
        $('#pfMaxAmount').val(form.max_amount || '');
        $('#pfAmountOptions').val((form.amount_options || []).join('\n'));
        $('#pfRedirectUrl').val(form.redirect_url || '');
        $('#pfRedirectDelay').val(form.redirect_delay_seconds || 0);
        $('#pfSuccessMessage').val(form.success_message || '');
        $('#pfExpiresAt').val(form.expires_at || '');
        $('#pfIsActive').prop('checked', form.is_active !== false);
        $('#pfFieldsTable tbody').empty();
        (form.fields || []).forEach(addFieldRow);

        setAmountMode($('#pfAmountMode').val());
        $('#paymentFormListView').hide();
        $('#paymentFormEditView').show();
        window.scrollTo(0, 0);
    }

    function closeEditor() {
        $('#paymentFormEditView').hide();
        $('#paymentFormListView').show();
        location.reload();
    }
    function openModal() {
        $('#paymentPreviewModal').addClass('open');
        $('body').addClass('modal-open');
    }
    function closeModal() {
        $('#paymentPreviewModal').removeClass('open');
        $('body').removeClass('modal-open');
    }

    function collectForm() {
        var data = {
            id: $('#paymentFormId').val(),
            title: $('#pfTitle').val(),
            slug: $('#pfSlug').val(),
            description: $('#pfDescription').val(),
            purpose: $('#pfPurpose').val(),
            theme: $('#pfTheme').val(),
            amount_mode: $('#pfAmountMode').val(),
            fixed_amount: $('#pfFixedAmount').val(),
            min_amount: $('#pfMinAmount').val(),
            max_amount: $('#pfMaxAmount').val(),
            redirect_url: $('#pfRedirectUrl').val(),
            redirect_delay_seconds: $('#pfRedirectDelay').val(),
            success_message: $('#pfSuccessMessage').val(),
            expires_at: $('#pfExpiresAt').val(),
            is_active: $('#pfIsActive').is(':checked') ? '1' : '',
            csrf_token: csrf,
            field_key: [], field_label: [], field_type: [], field_required: [],
            field_options: [],
            amount_options: $('#pfAmountOptions').val().split(/\r?\n/).filter(function (s) { return s.trim() !== ''; })
        };
        $('#pfFieldsTable tbody tr').each(function () {
            var $r = $(this);
            data.field_key.push($r.find('.pf-fkey').val());
            data.field_label.push($r.find('.pf-flabel').val());
            data.field_type.push($r.find('.pf-ftype').val());
            data.field_required.push($r.find('.pf-frequired').is(':checked') ? '1' : '');
            data.field_options.push($r.find('.pf-foptions').val() || '');
        });
        return data;
    }

    function post(action, payload, done) {
        payload = payload || {};
        payload.action = action;
        payload.csrf_token = csrf;
        $.post('assets/php/payment/payment_form_action.php', payload)
            .done(done)
            .fail(function () { alert('خطا در ارتباط با سرور.'); });
    }

    /* ----- toolbar ----- */
    $('#paymentFormNew').on('click', function () { openEditor(null); });
    $('#paymentFormCancel').on('click', closeEditor);
    $('#pfSaveCancel').on('click', closeEditor);
    $('#pfAddField').on('click', function () { addFieldRow(null); });
    $('#pfAmountMode').on('change', function () { setAmountMode($(this).val()); });

    $('#pfFieldsTable tbody').on('click', '.pf-field-remove', function () { $(this).closest('tr').remove(); });
    $('#pfFieldsTable tbody').on('change', '.pf-ftype', function () { toggleOptionsCell($(this).closest('tr')); });

    $('#pfSaveForm').on('click', function () {
        var $msg = $('.payment-save-message');
        $msg.text('در حال ذخیره...');
        var data = collectForm();
        var action = (data.id && data.id !== '0') ? 'update' : 'create';
        post(action, data, function (res) {
            if (res.success) { $msg.text(res.message); setTimeout(closeEditor, 500); }
            else { $msg.text(res.message || 'خطا در ذخیره'); }
        });
    });

    /* ----- row actions (event delegation) ----- */
    $('#paymentFormsTable').on('click', '.payment-edit', function () {
        var id = $(this).closest('tr').data('id');
        $.getJSON('assets/php/payment/payment_form_action.php', { action: 'get', id: id })
            .done(function (res) { if (res.success) openEditor(res.data); else alert(res.message); })
            .fail(function () { alert('خطا در دریافت فرم.'); });
    });

    $('#paymentFormsTable').on('click', '.payment-duplicate', function () {
        var id = $(this).closest('tr').data('id');
        if (!confirm('از فرم کپی گرفته شود؟')) return;
        post('duplicate', { id: id }, function (res) {
            alert(res.message || 'نتیجه'); if (res.success) location.reload();
        });
    });

    $('#paymentFormsTable').on('click', '.payment-toggle', function () {
        var id = $(this).closest('tr').data('id');
        if (!confirm('وضعیت این فرم تغییر کند؟')) return;
        post('toggle', { id: id }, function (res) {
            if (res.success) location.reload(); else alert(res.message);
        });
    });

    $('#paymentFormsTable').on('click', '.payment-delete', function () {
        var id = $(this).closest('tr').data('id');
        if (!confirm('این فرم حذف شود؟ (در صورت وجود تراکنش حذف نمیشود)')) return;
        post('delete', { id: id }, function (res) {
            alert(res.message || 'نتیجه'); if (res.success) location.reload();
        });
    });

    $('#paymentFormsTable').on('click', '.payment-preview', function () {
        var id = $(this).closest('tr').data('id');
        $('#paymentPreviewBody').html('<p>در حال بارگذاری...</p>');
        openModal();
        $.get('assets/php/payment/payment_form_preview.php', { id: id })
            .done(function (html) { $('#paymentPreviewBody').html(html); })
            .fail(function () { $('#paymentPreviewBody').html('<p>خطا در دریافت پیشنمایش.</p>'); });
    });

    $(document).on('click', '[data-close="paymentPreviewModal"]', closeModal);
});
