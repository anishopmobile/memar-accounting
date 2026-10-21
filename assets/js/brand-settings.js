/* App-brand appearance is independent of store-logo invoice settings. */
$(function () {
    'use strict';
    var form = document.getElementById('brandSettingsForm');
    if (!form) return;
    var input = document.getElementById('brandLogoImage');
    var preview = document.getElementById('brandSettingsPreview');
    var name = document.getElementById('brandSettingsPreviewName');
    var sample = document.getElementById('brandSettingsSample');
    var message = document.getElementById('brandSettingsMessage');
    var save = document.getElementById('brandSettingsSave');
    var savedLogo = '';
    var objectUrl = '';
    function updatePreview() {
        var selected = form.querySelector('input[name="display_mode"]:checked');
        var mode = selected ? selected.value : 'name';
        sample.classList.toggle('stacked', mode === 'logo_name');
        name.hidden = mode === 'logo';
        var hasLogo = !!(objectUrl || savedLogo);
        preview.hidden = mode === 'name' || !hasLogo;
        if (hasLogo) preview.src = objectUrl || savedLogo;
    }
    function load() {
        $.getJSON('assets/php/app_brand_settings.php').done(function (response) {
            if (!response || !response.success) return;
            var data = response.data || {};
            savedLogo = data.logo_path || '';
            var choice = form.querySelector('input[name="display_mode"][value="' + (data.display_mode || 'name') + '"]');
            if (choice) choice.checked = true;
            updatePreview();
        }).fail(function () {
            message.textContent = 'دریافت تنظیمات نمایشی ناموفق بود.';
        });
    }
    input.addEventListener('change', function () {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = '';
        if (input.files.length) {
            var file = input.files[0];
            if (!['image/png','image/jpeg','image/webp'].includes(file.type)
                || file.size > 2 * 1024 * 1024) {
                message.textContent = 'لوگو باید PNG، JPG یا WebP و حداکثر ۲ مگابایت باشد.';
                input.value = '';
            } else {
                objectUrl = URL.createObjectURL(file);
                message.textContent = '';
            }
        }
        updatePreview();
    });
    form.querySelectorAll('input[name="display_mode"]').forEach(function (radio) {
        radio.addEventListener('change', updatePreview);
    });
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (save.disabled) return;
        save.disabled = true;
        message.textContent = 'در حال ذخیره تنظیمات...';
        $.ajax({
            url: 'assets/php/app_brand_settings.php',
            method: 'POST', data: new FormData(form),
            processData: false, contentType: false, dataType: 'json'
        }).done(function (response) {
            message.textContent = response.message || '';
            if (response.success) {
                if (objectUrl) URL.revokeObjectURL(objectUrl);
                objectUrl = '';
                input.value = '';
                savedLogo = response.data.logo_path || '';
                updatePreview();
                // Apply saved brand to header on next page load; no invoice changes.
                window.location.reload();
            }
        }).fail(function (xhr) {
            message.textContent = (xhr.responseJSON && xhr.responseJSON.message)
                || 'ذخیره تنظیمات نمایشی ناموفق بود.';
        }).always(function () { save.disabled = false; });
    });
    load();
});
