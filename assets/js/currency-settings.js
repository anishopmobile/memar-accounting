/* Settings-only controller. Saving the display currency reloads the page. */
(function () {
    'use strict';
    function init() {
        var form = document.getElementById('currencySettingsForm');
        if (!form) return;
        var select = document.getElementById('currencyCode');
        var preview = document.getElementById('currencyPreview');
        var message = document.getElementById('currencySaveMessage');
        var button = document.getElementById('currencySave');
        select.value = window.APP_CURRENCY === 'IRR' ? 'IRR' : 'TOMAN';
        function render() { preview.textContent = select.value === 'IRR' ? '۱۰۰٬۰۰۰ ریال' : '۱۰٬۰۰۰ تومان'; }
        render();
        select.addEventListener('change', render);
        fetch('assets/php/currency_settings.php', {credentials:'same-origin',headers:{Accept:'application/json'}})
        .then(function (res) {return res.json();})
        .then(function (body) {
            if (!body.success || !body.data) throw Error(body.message || 'دریافت تنظیمات ناموفق بود.');
            select.value = body.data.currency === 'IRR' ? 'IRR' : 'TOMAN';
            render();
        }).catch(function (err) { message.textContent = err.message || 'خطا در دریافت تنظیمات'; });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            button.disabled = true;
            message.textContent = 'در حال ذخیره...';
            fetch('assets/php/currency_settings.php', {
                method:'POST',credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',Accept:'application/json'},
                body:new URLSearchParams(new FormData(form)).toString()
            }).then(function (res) {return res.json();}).then(function (body) {
                if (!body.success) throw Error(body.message || 'ذخیره ناموفق بود.');
                message.textContent = 'تنظیمات ذخیره شد.';
                window.location.reload();
            }).catch(function (err) {
                message.textContent = err.message || 'خطا در ذخیره تنظیمات.';
                button.disabled = false;
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
