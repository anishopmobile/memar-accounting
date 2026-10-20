/* ============================================================
 * auth.js — حساب کاربری + تنظیمات امنیت (کپچا / ضد جستجوی رمز)
 * jQuery + AJAX + JSON. Page-guarded: #account و #settings
 * ========================================================== */
$(function () {
    var bodyId = $('body').attr('id');

    /* ================== حساب کاربری ================== */
    if (bodyId === 'account') {
        var csrf = String($('#accountCsrfToken').val() || '');
        var $msg = $('.account-message');

        function post(action, data, done) {
            data = data || {};
            data.action = action;
            data.csrf_token = csrf;
            $.post('assets/php/account_settings.php', data)
                .done(done)
                .fail(function () { $msg.text('خطا در ارتباط با سرور.'); });
        }

        $('#accSaveProfile').on('click', function () {
            $msg.text('در حال ذخیره...');
            post('update_profile', {
                first_name: $('#accFirstName').val(),
                last_name: $('#accLastName').val()
            }, function (res) {
                $msg.text(res.message || (res.success ? 'ذخیره شد' : 'خطا'));
            });
        });

        $('#accChangePassword').on('click', function () {
            $msg.text('در حال تغییر رمز...');
            post('change_password', {
                current_password: $('#accCurrentPassword').val(),
                new_password: $('#accNewPassword').val(),
                new_password_confirmation: $('#accNewPasswordConfirm').val()
            }, function (res) {
                $msg.text(res.message || (res.success ? 'رمز عبور تغییر کرد' : 'خطا'));
                if (res.success) {
                    $('#accCurrentPassword, #accNewPassword, #accNewPasswordConfirm').val('');
                }
            });
        });

        $('#accSaveAvatar').on('click', function () {
            var file = $('#accAvatarInput')[0].files[0];
            if (!file) {
                $msg.text('فایلی انتخاب نشده است.');
                return;
            }
            var fd = new FormData();
            fd.append('action', 'upload_avatar');
            fd.append('csrf_token', csrf);
            fd.append('avatar', file);

            $msg.text('در حال آپلود...');
            $.ajax({
                url: 'assets/php/account_settings.php',
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (res) {
                $msg.text(res.message || (res.success ? 'ذخیره شد' : 'خطا'));
                if (res.success && res.data && res.data.avatar_path) {
                    $('#accAvatarPreview').attr('src', res.data.avatar_path).removeAttr('hidden');
                    $('#accAvatarPlaceholder').hide();
                }
            }).fail(function () {
                $msg.text('خطا در ارتباط با سرور.');
            });
        });

        $('#accRemoveAvatar').on('click', function () {
            if (!window.confirm('تصویر پروفایل حذف شود؟')) return;
            post('remove_avatar', {}, function (res) {
                $msg.text(res.message || (res.success ? 'حذف شد' : 'خطا'));
                if (res.success) {
                    $('#accAvatarPreview').removeAttr('src').attr('hidden', 'hidden');
                    $('#accAvatarPlaceholder').show();
                }
            });
        });
    }

    /* ================== تنظیمات امنیت (settings) ================== */
    if (bodyId === 'settings') {
        var csrf = String($('#authSecurityCsrfToken').val() || '');
        var $msg = $('.auth-security-message');

        function setVal(v) {
            return (v === undefined || v === null) ? '' : String(v);
        }

        $.getJSON('assets/php/security_settings.php', { action: 'get' })
            .done(function (res) {
                if (!res.success) return;
                var d = (res.data && res.data.settings) || {};
                $('#secLoginCaptcha').prop('checked', !!d.login_captcha_enabled);
                $('#secRegCaptcha').prop('checked', !!d.registration_captcha_enabled);
                $('#secCaptchaLang').val(d.captcha_lang || 'fa');
                $('#secCaptchaCharType').val(d.captcha_char_type || 'mixed');
                $('#secCaptchaNoise').val(d.captcha_noise_level || 'medium');
                $('#secCaptchaLength').val(setVal(d.captcha_length));
                $('#secCaptchaWidth').val(setVal(d.captcha_width));
                $('#secCaptchaHeight').val(setVal(d.captcha_height));
                $('#secCaptchaFontSize').val(setVal(d.captcha_font_size));
                $('#secCaptchaCaseSensitive').prop('checked', !!d.captcha_case_sensitive);
                $('#secCaptchaExpiry').val(setVal(d.captcha_expiry));
                $('#secBruteMax').val(setVal(d.brute_max_attempts));
                $('#secBruteWindow').val(setVal(d.brute_window_minutes));
                $('#secBruteLockout').val(setVal(d.brute_lockout_minutes));
            })
            .fail(function () { $msg.text('خطا در دریافت تنظیمات.'); });

        $('#authSecuritySave').on('click', function () {
            $msg.text('در حال ذخیره...');
            $.post('assets/php/security_settings.php', {
                csrf_token: csrf,
                captcha_provider: 'pure',
                login_captcha_enabled: $('#secLoginCaptcha').is(':checked') ? '1' : '',
                registration_captcha_enabled: $('#secRegCaptcha').is(':checked') ? '1' : '',
                captcha_lang: $('#secCaptchaLang').val(),
                captcha_char_type: $('#secCaptchaCharType').val(),
                captcha_noise_level: $('#secCaptchaNoise').val(),
                captcha_length: $('#secCaptchaLength').val(),
                captcha_width: $('#secCaptchaWidth').val(),
                captcha_height: $('#secCaptchaHeight').val(),
                captcha_font_size: $('#secCaptchaFontSize').val(),
                captcha_case_sensitive: $('#secCaptchaCaseSensitive').is(':checked') ? '1' : '',
                captcha_expiry: $('#secCaptchaExpiry').val(),
                brute_max_attempts: $('#secBruteMax').val(),
                brute_window_minutes: $('#secBruteWindow').val(),
                brute_lockout_minutes: $('#secBruteLockout').val()
            }).done(function (res) {
                $msg.text(res.message || (res.success ? 'ذخیره شد' : 'خطا'));
            }).fail(function () {
                $msg.text('خطا در ارتباط با سرور.');
            });
        });
    }
});

