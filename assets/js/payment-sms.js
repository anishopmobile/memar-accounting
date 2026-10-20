/* ============================================================
 * payment-sms.js — تنظیمات پیامک (فراز اس ام اس)
 * jQuery + AJAX + JSON. Page-guarded to #payment-sms-settings.
 * ========================================================== */
$(function () {
    if ($('body').attr('id') !== 'payment-sms-settings') return;

    var csrf = String($('#smsSettingsCsrfToken').val() || '');

    function post(action, payload, done) {
        payload = payload || {};
        payload.action = action;
        payload.csrf_token = csrf;
        $.post('assets/php/payment/payment_sms_settings.php', payload)
            .done(done)
            .fail(function () { alert('خطا در ارتباط با سرور.'); });
    }

    /* load masked settings */
    $.getJSON('assets/php/payment/payment_sms_settings.php', { action: 'get' })
        .done(function (res) {
            if (!res.success) return;
            var d = res.data || {};
            $('#smsKeyHint').text(d.has_sms_api_key ? ('کلید ذخیره شده: ' + d.sms_api_key_masked) : 'کلید API تنظیم نشده است.');
            $('#smsSenderLine').val(d.sms_sender_line || '');
            $('#smsSuccessPattern').val(d.sms_success_pattern_code || '');
            $('#smsAdminPattern').val(d.sms_admin_pattern_code || '');
            $('#smsAdminMobile').val(d.sms_admin_mobile || '');
            $('#smsCustomerEnabled').prop('checked', !!d.sms_customer_enabled);
            $('#smsAdminEnabled').prop('checked', !!d.sms_admin_enabled);
        });

    $('#smsSaveSettings').on('click', function () {
        var $msg = $('.sms-save-message');
        $msg.text('در حال ذخیره...');
        post('save', {
            sms_api_key: $('#smsApiKey').val(),
            sms_sender_line: $('#smsSenderLine').val(),
            sms_success_pattern_code: $('#smsSuccessPattern').val(),
            sms_admin_pattern_code: $('#smsAdminPattern').val(),
            sms_admin_mobile: $('#smsAdminMobile').val(),
            sms_customer_enabled: $('#smsCustomerEnabled').is(':checked') ? '1' : '',
            sms_admin_enabled: $('#smsAdminEnabled').is(':checked') ? '1' : ''
        }, function (res) {
            $msg.text(res.message || (res.success ? 'ذخیره شد' : 'خطا'));
            if (res.success) { $('#smsApiKey').val(''); $('#smsKeyHint').text('کلید ذخیره شد.'); }
        });
    });

    $('#smsTestConnection').on('click', function () {
        var $msg = $('.sms-save-message');
        $msg.text('در حال تست...');
        post('test', {}, function (res) {
            $msg.text(res.message || (res.success ? 'اتصال برقرار است' : 'تست ناموفق'));
        });
    });
});