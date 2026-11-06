/* Category Excel import: same two-step modal workflow as product import. */
$(function () {
    'use strict';
    if (!$('#openCategoryImportModal').length) return;

    var $modal = $('#categoryImportModal');
    var $file = $('#categoryImportFile');
    var $preview = $('#categoryImportPreview');
    var $submit = $('#categoryImportSubmit');
    var $result = $('#categoryImportResult');
    var $spinner = $('#categoryImportSpinner');
    var busy = false;
    var version = 0;
    var canSubmit = false;

    function currentFile() {
        var el = $file[0];
        return el && el.files && el.files[0] ? el.files[0] : null;
    }
    function currentMode() {
        return $modal.find('input[name="category_import_mode"]:checked').val() || 'skip_duplicates';
    }
    function buttons() {
        $spinner.toggle(busy);
        $file.prop('disabled', busy);
        $modal.find('input[name="category_import_mode"]').prop('disabled', busy);
        $preview.prop('disabled', busy || !currentFile());
        $submit.prop('disabled', busy || !canSubmit);
    }
    function message(text, error) {
        $result.removeClass('success error')
            .addClass(error ? 'error' : 'success').text(text).show();
    }
    function resetPreview() {
        canSubmit = false;
        $('#categoryImportSummary').hide().empty();
        $('#categoryImportErrors').empty();
        $('#categoryImportErrorsWrap, #categoryImportPreviewWrap').hide();
        $('#categoryImportPreviewTable tbody').empty();
        $result.hide().empty();
        buttons();
    }
    function closeModal() {
        if (busy) return;
        $modal.removeClass('open');
        if (!$('.modal.open').length) $('body').removeClass('modal-open');
        $file.val('');
        version++;
        resetPreview();
    }
    function errorMessage(xhr) {
        return (xhr.responseJSON && xhr.responseJSON.message) || 'خطا در ارتباط با سرور.';
    }
    function sendRequest(action) {
        var form = new FormData();
        form.append('csrf_token', window.APP_CSRF_TOKEN || '');
        form.append('action', action);
        form.append('import_mode', currentMode());
        form.append('file', currentFile());
        return $.ajax({
            url: 'assets/php/category_import.php',
            type: 'POST',
            data: form,
            contentType: false,
            processData: false,
            dataType: 'json'
        });
    }

    $('#openCategoryImportModal').on('click', function () {
        if (busy) return;
        $file.val('');
        $modal.find('input[name="category_import_mode"][value="skip_duplicates"]').prop('checked', true);
        version++;
        resetPreview();
        $modal.addClass('open');
        $('body').addClass('modal-open');
    });
    $('#closeCategoryImportModal, #cancelCategoryImportModal, #categoryImportModal .modal-backdrop')
        .on('click', closeModal);
    $file.on('change', function () {
        if (busy) return;
        version++;
        resetPreview();
        if (currentFile()) message('فایل انتخاب شد. روی «خواندن و پیش‌نمایش» کلیک کنید.', false);
    });
    $modal.find('input[name="category_import_mode"]').on('change', function () {
        if (busy) return;
        version++;
        resetPreview();
    });

    function renderPreview(data) {
        var items = [
            'کل: ' + (data.total || 0),
            'جدید: ' + (data.new || 0),
            'به‌روزرسانی: ' + (data.updates || 0),
            'تکراری: ' + (data.duplicates || 0),
            'خطادار: ' + (data.invalid || 0)
        ];
        var $summary = $('#categoryImportSummary').empty();
        items.forEach(function (label) {
            $('<span>').addClass('import-summary-item').text(label).appendTo($summary);
        });
        $summary.show();

        var $errors = $('#categoryImportErrors').empty();
        if (data.errors && data.errors.length) {
            data.errors.forEach(function (text) { $('<li>').text(text).appendTo($errors); });
        } else {
            $('<li>').addClass('import-no-error').text('همه ردیف‌ها معتبر هستند.').appendTo($errors);
        }
        $('#categoryImportErrorsWrap').show();

        var $tbody = $('#categoryImportPreviewTable tbody').empty();
        var labels = {ok: 'جدید', update: 'به‌روزرسانی', duplicate: 'تکراری', error: 'خطادار'};
        (data.records || []).slice(0, 100).forEach(function (rec) {
            var d = rec.data || {};
            var $tr = $('<tr>');
            if (rec.status === 'error') $tr.addClass('import-row-error');
            [rec.row, d.code || '', d.name || '', d.parent_code || ''].forEach(function (value) {
                $('<td>').text(value).appendTo($tr);
            });
            $('<td>').append($('<span>')
                .addClass(rec.status === 'error'
                    ? 'import-badge import-badge-error' : 'import-badge import-badge-ok')
                .text(labels[rec.status] || rec.status)).appendTo($tr);
            $tbody.append($tr);
        });
        $('#categoryImportPreviewWrap').show();

        canSubmit = Number(data.invalid || 0) === 0 && Number(data.valid || 0) > 0;
        if ((data.records || []).length > 100) {
            message('برای نمایش سریع‌تر فقط ۱۰۰ ردیف اول در جدول پیش‌نمایش آمده است؛ تمام ردیف‌ها بررسی شده‌اند.', false);
        }
        if (!canSubmit) {
            message('هیچ ردیف قابل ثبت وجود ندارد یا فایل خطادار است؛ پیش از ثبت، خطاها را اصلاح کنید.', true);
        }
    }

    $preview.on('click', function () {
        if (busy || !currentFile()) return;
        var requestedVersion = version;
        resetPreview();
        busy = true;
        buttons();
        sendRequest('preview').done(function (response) {
            if (requestedVersion !== version) return;
            if (response && response.success && response.data) {
                renderPreview(response.data);
            } else {
                message(response && response.message ? response.message : 'خواندن فایل ناموفق بود.', true);
            }
        }).fail(function (xhr) {
            if (requestedVersion === version) message(errorMessage(xhr), true);
        }).always(function () {
            if (requestedVersion === version) {
                busy = false;
                buttons();
            }
        });
    });

    $submit.on('click', function () {
        if (busy || !currentFile() || !canSubmit) return;
        var requestedVersion = version;
        busy = true;
        buttons();
        sendRequest('import').done(function (response) {
            if (requestedVersion !== version) return;
            if (!response || !response.success) {
                message(response && response.message ? response.message : 'ثبت نهایی انجام نشد.', true);
                canSubmit = false;
                return;
            }
            var d = response.data || {};
            alert('ورود دسته‌بندی‌ها موفق بود.\n\n'
                + 'جدید: ' + (d.inserted || 0) + '\n'
                + 'به‌روزرسانی: ' + (d.updated || 0) + '\n'
                + 'صرف‌نظر از تکراری‌ها: ' + (d.skipped || 0) + '\n'
                + 'نام‌های بدون تغییر: ' + (d.unchanged || 0));
            window.location.reload();
        }).fail(function (xhr) {
            if (requestedVersion === version) {
                message(errorMessage(xhr), true);
                canSubmit = false;
            }
        }).always(function () {
            if (requestedVersion === version) {
                busy = false;
                buttons();
            }
        });
    });
});
