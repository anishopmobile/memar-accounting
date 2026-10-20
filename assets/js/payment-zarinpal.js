/* ============================================================
 * payment-zarinpal.js — تنظیمات درگاه زرین‌پال
 * jQuery + AJAX + JSON. Page-guarded to #payment-zarinpal-settings.
 * ========================================================== */
$(function () {
    if ($('body').attr('id') !== 'payment-zarinpal-settings') return;

    var csrf = String($('#zarinpalSettingsCsrfToken').val() || '');

    function post(action, payload, done) {
        payload = payload || {};
        payload.action = action;
        payload.csrf_token = csrf;
        $.post('assets/php/payment/payment_zarinpal_settings.php', payload)
            .done(done)
            .fail(function () { alert('خطا در ارتباط با سرور.'); });
    }

    /* load masked settings */
    $.getJSON('assets/php/payment/payment_zarinpal_settings.php', { action: 'get' })
        .done(function (res) {
            if (!res.success) return;
            var d = res.data || {};
            $('#zarinpalMerchantHint').text(d.has_merchant_id ? ('Merchant ID ذخیره‌شده: ' + d.merchant_id_masked) : 'Merchant ID تنظیم نشده است.');
            $('#zarinpalSandbox').val(d.zarinpal_sandbox ? '1' : '0');
            $('#zarinpalGatewayEnabled').prop('checked', !!d.zarinpal_gateway_enabled);
        });

    $('#zarinpalSaveSettings').on('click', function () {
        var $msg = $('.zarinpal-save-message');
        $msg.text('در حال ذخیره...');
        post('save', {
            merchant_id: $('#zarinpalMerchantId').val(),
            zarinpal_sandbox: $('#zarinpalSandbox').val(),
            zarinpal_gateway_enabled: $('#zarinpalGatewayEnabled').is(':checked') ? '1' : ''
        }, function (res) {
            $msg.text(res.message || (res.success ? 'ذخیره شد' : 'خطا'));
            if (res.success) { $('#zarinpalMerchantId').val(''); $('#zarinpalMerchantHint').text('Merchant ID ذخیره شد.'); }
        });
    });

    $('#zarinpalTestConfiguration').on('click', function () {
        var $msg = $('.zarinpal-save-message');
        $msg.text('در حال بررسی...');
        post('test', {}, function (res) {
            $msg.text(res.message || (res.success ? 'تنظیمات معتبر است' : 'بررسی ناموفق'));
        });
    });
});