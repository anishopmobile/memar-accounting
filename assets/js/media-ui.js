/* Central media UI. Scoped by editor; never changes quantities, prices or invoices. */
(function ($) {
    'use strict';
    var states = {};
    var active = null;
    var selectedInPicker = new Map();
    var libraryPage = 1;
    var libraryTerm = '';
    var libraryUsage = 'all';
    var libraryFolder = 'all', folders = [], dzInstance = null;
    var pickerRequest = null, pickerSequence = 0, managerRequest = null, managerSequence = 0;
    var uploadRefreshTimer = null, managerSnapshot = null;
    var loadManager = function () {};
    var modal = null, restoreFocus = null;

    function escapeHtml(v) {
        return $('<div>').text(v == null ? '' : String(v)).html();
    }
    function mediaUrl(id, size) {
        return 'assets/php/media_file.php?id=' + encodeURIComponent(id) + '&size=' + (size || 'thumb');
    }
    function csrf() { return window.APP_CSRF_TOKEN || ''; }
    function folderOptions(selected) {
        var html = '<option value="all">همه پوشه‌ها</option><option value="0">بدون پوشه</option>';
        folders.forEach(function(f) {
            html += '<option value="' + Number(f.id) + '"' + (String(selected) === String(f.id) ? ' selected' : '') + '>'
                + escapeHtml(f.name) + ' (' + Number(f.media_count || 0) + ')</option>';
        });
        return html;
    }
    function updateFolderSelectors() {
        $('#mediaPickerFolder,#mediaManagerFolder').each(function () {
            var selected = String($(this).val() || libraryFolder);
            $(this).html(folderOptions(selected)).val(selected);
            if ($(this).val() === null) $(this).val('all');
        });
    }
    function loadFolders() {
        return $.getJSON('assets/php/media_api.php', {action:'folders'}).done(function(res) {
            if (res && res.success) {
                folders = (res.data && res.data.folders) || [];
                updateFolderSelectors();
            }
        });
    }
    function moveOptions(selected) {
        var html = '<option value="0">بدون پوشه</option>';
        folders.forEach(function(f) {
            html += '<option value="' + Number(f.id) + '"' + (String(selected) === String(f.id) ? ' selected' : '') + '>'
                + escapeHtml(f.name) + '</option>';
        });
        return html;
    }
    function state(name) {
        if (!states[name]) states[name] = { items: [], original: '[]', loading: false, seq: 0 };
        return states[name];
    }
    function editor(name) { return $('[data-media-editor="' + name + '"]'); }
    function setItems(name, items, isOriginal) {
        var st = state(name);
        st.items = items.map(function (x) {
            return { id: Number(x.id), url: x.url || mediaUrl(x.id), original_filename: x.original_filename || '' };
        });
        if (isOriginal) st.original = JSON.stringify(st.items.map(function (x) { return x.id; }));
        renderEditor(name);
    }
    function renderEditor(name) {
        var st = state(name), el = editor(name);
        if (!el.length) return;
        var ids = st.items.map(function (x) { return x.id; });
        el.find('input[name="media_ids"]').val(JSON.stringify(ids));
        var html = '';
        st.items.forEach(function (item, n) {
            html += '<div class="media-picked" role="listitem" data-id="' + item.id + '">'
                + '<img loading="lazy" alt="" src="' + escapeHtml(item.url) + '">'
                + (n === 0 ? '<small class="media-primary">اصلی</small>' : '')
                + '<div class="media-picked-controls">'
                + '<button type="button" class="media-preview" data-id="' + item.id + '" aria-label="مشاهده بزرگ">🔍</button>'
                + '<button type="button" class="media-left" data-id="' + item.id + '" aria-label="انتقال به اول" ' + (n === 0 ? 'disabled' : '') + '>↑</button>'
                + '<button type="button" class="media-right" data-id="' + item.id + '" aria-label="انتقال به آخر" ' + (n === st.items.length - 1 ? 'disabled' : '') + '>↓</button>'
                + '<button type="button" class="media-remove" data-id="' + item.id + '" aria-label="حذف ارتباط">×</button></div></div>';
        });
        el.find('.media-selected').html(html || '<p class="media-empty">تصویری انتخاب نشده است.</p>');
        el.find('.media-editor-message').text(st.loading ? 'در حال خواندن تصاویر قبلی...' : ids.length + ' از ۱۰ تصویر انتخاب شده');
    }
    var MediaEditor = {
        prepare: function (name, productId) {
            var st = state(name);
            var token = ++st.seq;
            st.loading = !!productId;
            setItems(name, [], true);
            renderEditor(name);
            if (!productId) return;
            $.getJSON('assets/php/media_api.php', {action:'product', product_id:productId})
                .done(function (res) {
                    if (token !== st.seq) return;
                    if (res && res.success) setItems(name, (res.data && res.data.items) || [], true);
                    else alert((res && res.message) || 'بارگذاری تصاویر محصول ناموفق بود.');
                }).fail(function () {
                    if (token === st.seq) alert('امکان دریافت تصاویر قبلی محصول وجود ندارد.');
                }).always(function () {
                    if (token === st.seq) { st.loading = false; renderEditor(name); }
                });
        },
        isDirty: function (name) {
            var st = state(name);
            return JSON.stringify(st.items.map(function (x) { return x.id; })) !== st.original;
        },
        canSave: function (name) {
            return !state(name).loading && !(dzInstance && (dzInstance.getQueuedFiles().length || dzInstance.getUploadingFiles().length));
        },
        reset: function (name) { this.prepare(name, 0); }
    };
    window.MediaEditor = MediaEditor;

    function ensureModal() {
        if (modal) return modal;
        modal = $('<div id="mediaPicker" class="media-picker-overlay" role="dialog" aria-modal="true" aria-label="کتابخانه تصاویر" hidden>'
            + '<div class="media-picker-panel">'
            + '<header><h2>کتابخانه تصاویر</h2><button type="button" class="media-picker-close" aria-label="بستن">×</button></header>'
            + '<div class="media-picker-tools"><input type="search" id="mediaPickerSearch" placeholder="جستجو نام فایل">'
            + '<select id="mediaPickerUsage"><option value="all">همه تصاویر</option><option value="used">استفاده‌شده</option><option value="unused">بدون استفاده</option></select>'
            + '<select id="mediaPickerFolder" aria-label="انتخاب پوشه"><option value="all">همه پوشه‌ها</option></select>'
            + '<button type="button" class="button-secondary media-create-folder">+ پوشه جدید</button></div>'
            + '<div id="mediaPickerGrid" class="media-grid" role="group"></div>'
            + '<div class="media-pages"><button type="button" id="mediaPrev">قبلی</button><span id="mediaPageText"></span><button type="button" id="mediaNext">بعدی</button></div>'
            + '<div class="media-dropbox dropzone" id="mediaDropArea" tabindex="0">'
            + '<h3>آپلود تصویر</h3><p>تصاویر را اینجا رها کنید یا انتخاب نمایید (JPEG/PNG/WebP؛ هر فایل حداکثر ۵ مگابایت).</p>'
            + '<p>برای انتخاب چند تصویر روی این بخش کلیک کنید.</p>'
            + '<div id="mediaUploadQueue" aria-live="polite"></div></div>'
            + '<footer><span id="mediaSelectedCount">۰ تصویر انتخاب شده</span>'
            + '<button type="button" class="button-secondary media-picker-close">لغو</button>'
            + '<button type="button" class="button" id="mediaPickerConfirm">تأیید انتخاب</button></footer></div></div>');
        $(document.body).append(modal);
        if (!window.Dropzone) {
            $('#mediaUploadQueue').text('کتابخانه محلی Dropzone بارگذاری نشده است.');
            return modal;
        }
        window.Dropzone.autoDiscover = false;
        var dz = new window.Dropzone(modal.find('#mediaDropArea')[0], {
            url:'assets/php/media_api.php', method:'post', paramName:'file',
            maxFilesize:5, parallelUploads:2, uploadMultiple:false,
            acceptedFiles:'image/jpeg,image/png,image/webp', clickable:true,
            addRemoveLinks:true, previewsContainer:'#mediaUploadQueue',
            dictDefaultMessage:'تصاویر را اینجا رها کنید',
            dictRemoveFile:'حذف از صف', dictCancelUpload:'لغو ارسال',
            dictInvalidFileType:'فرمت تصویر مجاز نیست',
            dictFileTooBig:'حجم فایل بیشتر از پنج مگابایت است',
            dictMaxFilesExceeded:'محدودیت تصاویر فقط برای اتصال به هر محصول است'
        });
        dzInstance = dz;
        dz.on('sending', function(file, xhr, formData) {
            formData.append('action','upload');
            formData.append('csrf_token',csrf());
            formData.append('folder_id', /^[0-9]+$/.test(String(libraryFolder)) ? libraryFolder : '0');
        });
        dz.on('success', function(file, response) {
            var res = response;
            if (typeof res === 'string') {try{res=JSON.parse(res);}catch(e){res=null;}}
            if (!res || !res.success || !res.data || !res.data.id) {
                this.emit('error',file,(res && res.message)||'پاسخ بارگذاری نامعتبر است');
                return;
            }
            var m = {id:Number(res.data.id),url:res.data.url,original_filename:file.name};
            if (active && active !== 'category') {
                var st = state(active);
                if (st.items.length < 10 && !st.items.some(function(x){return x.id===m.id;})) st.items.push(m);
                renderEditor(active);
            } else if (active === 'category') {
                $('#categoryMediaId').val(m.id);
                $('#categoryMediaPreview').attr('src',m.url);
            }
            clearTimeout(uploadRefreshTimer);
            uploadRefreshTimer = setTimeout(function () { loadPicker(); loadFolders(); }, 280);
        });
        dz.on('complete', function (file) {
            if (file.status === window.Dropzone.SUCCESS) {
                setTimeout(function () { dz.removeFile(file); }, 3000);
            }
        });
        dz.on('error', function(file, message) {
            if (typeof message === 'object') message = message.message || 'خطا';
            $(file.previewElement).find('.dz-error-message span').text(message);
        });
        return modal;
    }
    var pickerTimer = null;
    function openPicker(name, focusUpload) {
        restoreFocus = document.activeElement;
        managerSnapshot = !name && $('#mediaLibraryPage').length ?
            {page:libraryPage,term:libraryTerm,usage:libraryUsage,folder:libraryFolder} : null;
        active = name;
        selectedInPicker.clear();
        libraryPage = 1; libraryTerm = ''; libraryUsage = 'all';
        libraryFolder = managerSnapshot ? managerSnapshot.folder : 'all';
        ensureModal();
        $('#mediaPickerSearch').val('');
        $('#mediaPickerUsage').val('all');
        $('#mediaPickerFolder').val(libraryFolder);
        loadFolders();
        modal.prop('hidden', false);
        $('#mediaPickerConfirm').toggle(!!name);
        $('#mediaSelectedCount').toggle(!!name);
        loadPicker();
        if (focusUpload) $('#mediaDropArea').trigger('focus'); else $('#mediaPickerSearch').trigger('focus');
    }
    function closePicker() {
        if (!modal) return;
        modal.prop('hidden', true);
        active = null;
        selectedInPicker.clear();
        if (restoreFocus && typeof restoreFocus.focus === 'function') restoreFocus.focus();
        if (managerSnapshot) {
            libraryPage = managerSnapshot.page;
            libraryTerm = managerSnapshot.term;
            libraryUsage = managerSnapshot.usage;
            libraryFolder = managerSnapshot.folder;
            managerSnapshot = null;
        }
        if ($('#mediaLibraryPage').length) {
            $('#mediaManagerSearch').val(libraryTerm);
            $('#mediaManagerUsage').val(libraryUsage);
            $('#mediaManagerFolder').val(libraryFolder);
            loadManager();
        }
    }
    function loadPicker() {
        ensureModal();
        if (pickerRequest && pickerRequest.readyState !== 4) pickerRequest.abort();
        var sequence = ++pickerSequence;
        $('#mediaPickerGrid').html('<p>در حال دریافت تصاویر...</p>');
        pickerRequest = $.getJSON('assets/php/media_api.php', {action:'list',search:libraryTerm,page:libraryPage,usage:libraryUsage,folder:libraryFolder})
            .done(function (res) {
                if (sequence !== pickerSequence) return;
                if (!res || !res.success) { $('#mediaPickerGrid').text(res.message || 'خطا در بارگذاری کتابخانه'); return; }
                var data = res.data || {}, rows = data.items || [], html = '';
                rows.forEach(function (x) {
                    var checked = selectedInPicker.has(Number(x.id));
                    html += '<button type="button" class="media-tile' + (checked ? ' chosen' : '') + '" data-id="' + Number(x.id) + '"'
                        + ' data-name="' + escapeHtml(x.original_filename) + '" aria-pressed="' + (checked ? 'true' : 'false') + '">'
                        + '<img loading="lazy" decoding="async" width="120" height="120" src="' + escapeHtml(x.url) + '" alt=""><span>' + escapeHtml(x.original_filename) + '</span>'
                        + '<small>' + Number(x.products_count || 0) + ' محصول</small></button>';
                });
                $('#mediaPickerGrid').html(html || '<p>تصویری یافت نشد.</p>');
                $('#mediaPageText').text('صفحه ' + data.page + ' از ' + data.pages);
                $('#mediaPrev').prop('disabled', data.page <= 1);
                $('#mediaNext').prop('disabled', data.page >= data.pages);
            }).fail(function (xhr,status) {
                if (status !== 'abort' && sequence === pickerSequence) $('#mediaPickerGrid').text('خطا در ارتباط با سرور.');
            });
    }
    function countSelection() { $('#mediaSelectedCount').text(selectedInPicker.size + ' تصویر انتخاب شده'); }
    $(document).on('click', '#categoryMediaButton', function () {openPicker('category', false);});
    $(document).on('click', '#categoryMediaClear', function () {
        $('#categoryMediaId').val('0'); $('#categoryMediaPreview').attr('src','assets/img/product-placeholder.svg');
    });
    $(document).on('click', '.media-open-library, .media-open-upload', function () {
        var name = $(this).closest('[data-media-editor]').attr('data-media-editor');
        openPicker(name, $(this).hasClass('media-open-upload'));
    });
    $(document).on('click', '.media-picker-close', closePicker);
    $(document).on('keydown', function (e) { if (modal && !modal.prop('hidden') && e.key === 'Escape') { e.preventDefault(); closePicker(); } });
    $(document).on('click', '.media-tile', function () {
        var id = Number($(this).data('id'));
        if (selectedInPicker.has(id)) selectedInPicker.delete(id);
        else selectedInPicker.set(id, {id:id,url:mediaUrl(id),original_filename:$(this).data('name')});
        $(this).toggleClass('chosen', selectedInPicker.has(id)).attr('aria-pressed', selectedInPicker.has(id) ? 'true' : 'false');
        countSelection();
    });
    $(document).on('click', '#mediaPickerConfirm', function () {
        if (!active) return;
        if (active === 'category') {
            var first = selectedInPicker.values().next().value;
            if (first) {
                $('#categoryMediaId').val(first.id);
                $('#categoryMediaPreview').attr('src', first.url);
            }
            closePicker();
            return;
        }
        var st = state(active);
        var incoming = Array.from(selectedInPicker.values()).filter(function (m) {
            return !st.items.some(function (x) {return x.id === m.id;});
        });
        if (st.items.length + incoming.length > 10) {
            alert('برای هر محصول حداکثر ۱۰ تصویر مجاز است. کتابخانه مرکزی محدودیت ۱۰ تصویر ندارد.');
            return;
        }
        incoming.forEach(function (m) {st.items.push(m);});
        renderEditor(active);
        closePicker();
    });
    $(document).on('input', '#mediaPickerSearch', function () {
        clearTimeout(pickerTimer);
        var val = $(this).val();
        pickerTimer = setTimeout(function () { libraryTerm = val; libraryPage = 1; loadPicker(); }, 200);
    });
    $(document).on('change', '#mediaPickerUsage', function () { libraryUsage = $(this).val(); libraryPage = 1; loadPicker(); });
    $(document).on('change', '#mediaPickerFolder', function () { libraryFolder = String($(this).val()); libraryPage = 1; loadPicker(); });
    $(document).on('click', '.media-create-folder', function () {
        var button = $(this);
        $('.media-folder-create-form').remove();
        $('.media-create-folder').show();
        button.hide();
        $('<form class="media-folder-create-form" autocomplete="off">'
            + '<input type="text" class="media-folder-name" maxlength="140" required placeholder="نام پوشه جدید" aria-label="نام پوشه">'
            + '<button type="submit" class="button">ثبت</button>'
            + '<button type="button" class="button-secondary media-folder-cancel">لغو</button>'
            + '<span class="media-folder-error" role="alert"></span></form>')
            .insertAfter(button).find('input').trigger('focus');
    });
    $(document).on('click','.media-folder-cancel',function(){
        $(this).closest('.media-folder-create-form').remove();
        $('.media-create-folder').show();
    });
    $(document).on('submit','.media-folder-create-form',function(e){
        e.preventDefault();
        var form=$(this), name=$.trim(form.find('.media-folder-name').val());
        if(!name)return;
        var save=form.find('button[type=submit]').prop('disabled',true);
        $.post('assets/php/media_api.php',{action:'folder_create',name:name,csrf_token:csrf()},function(res){
            if(!res || !res.success){form.find('.media-folder-error').text((res && res.message)||'ایجاد پوشه ناموفق بود.');return;}
            form.remove();
            $('.media-create-folder').show();
            libraryFolder=String(res.data.id);
            libraryPage=1;
            loadFolders().done(function(){
                $('#mediaPickerFolder,#mediaManagerFolder').val(libraryFolder);
                if($('#mediaPicker').length && !$('#mediaPicker').prop('hidden'))loadPicker();
                if($('#mediaLibraryPage').length)loadManager();
            });
        },'json').fail(function(){form.find('.media-folder-error').text('خطا در ارتباط با سرور.');})
            .always(function(){save.prop('disabled',false);});
    });
    $(document).on('click', '#mediaPrev', function () { libraryPage = Math.max(1, libraryPage-1); loadPicker(); });
    $(document).on('click', '#mediaNext', function () { libraryPage++; loadPicker(); });

    $(document).on('click','.media-picked .media-remove,.media-picked .media-left,.media-picked .media-right',function () {
        var name = $(this).closest('[data-media-editor]').attr('data-media-editor');
        var st = state(name), id = Number($(this).data('id'));
        var idx = st.items.findIndex(function (x) {return x.id===id;});
        if (idx < 0) return;
        if ($(this).hasClass('media-remove')) st.items.splice(idx,1);
        else {
            var to = $(this).hasClass('media-left') ? Math.max(0,idx-1) : Math.min(st.items.length-1,idx+1);
            var item = st.items.splice(idx,1)[0];
            st.items.splice(to,0,item);
        }
        renderEditor(name);
    });
    // A product's image is a gallery, not just its primary thumbnail. Fetch
    // at most the ten linked IDs; full-size originals load only on navigation.
    var galleryOpen = null, galleryRequest = null;
    function closeGallery() {
        if (galleryRequest && galleryRequest.readyState !== 4) galleryRequest.abort();
        galleryRequest = null;
        if (!galleryOpen) return;
        $(document).off('.mediaGallery');
        galleryOpen.root.remove();
        if (galleryOpen.focus && document.body.contains(galleryOpen.focus)) galleryOpen.focus.focus();
        galleryOpen = null;
    }
    function gallerySelect(index) {
        var g = galleryOpen;
        if (!g || !g.items.length) return;
        g.index = (index + g.items.length) % g.items.length;
        var item = g.items[g.index], mid = Number(item.id);
        g.root.find('.media-gallery-count').text((g.index + 1) + ' / ' + g.items.length);
        g.root.find('.media-gallery-name').text(item.original_filename || '');
        g.root.find('.media-gallery-prev,.media-gallery-next').toggle(g.items.length > 1);
        var strip = '';
        g.items.forEach(function(m, n) {
            strip += '<button type="button" data-index="' + n + '" class="media-gallery-thumb'
                + (n === g.index ? ' active' : '') + '" aria-label="تصویر ' + (n + 1)
                + '" aria-pressed="' + (n === g.index ? 'true' : 'false') + '">'
                + '<img loading="lazy" decoding="async" width="56" height="56" src="'
                + escapeHtml(mediaUrl(m.id)) + '" alt=""></button>';
        });
        g.root.find('.media-gallery-strip').html(strip).toggle(g.items.length > 1);
        var image = g.root.find('.media-gallery-image'), progress = g.root.find('.media-lightbox-status');
        image.attr('src', mediaUrl(mid));
        progress.text('در حال دریافت تصویر اصلی…').show();
        var seq = ++g.seq;
        var full = new Image();
        full.onload = function() {
            if (!galleryOpen || galleryOpen !== g || seq !== g.seq) return;
            image.attr('src',full.src);
            progress.hide();
        };
        full.onerror = function() {
            if (!galleryOpen || galleryOpen !== g || seq !== g.seq) return;
            progress.text('تصویر اصلی در دسترس نیست؛ تصویر کوچک نمایش داده می‌شود.');
        };
        full.src = mediaUrl(mid,'original');
    }
    function openGallery(clickedId, items, focus) {
        closeGallery();
        items = (items || []).filter(function(m){return Number(m.id)>0;});
        if (!items.length) items = [{id:clickedId,original_filename:''}];
        var overlay = $('<div class="media-lightbox media-gallery" role="dialog" aria-modal="true" aria-label="گالری تصاویر محصول" tabindex="-1">'
            + '<button type="button" class="media-gallery-close" aria-label="بستن گالری">×</button>'
            + '<div class="media-gallery-stage">'
            + '<button type="button" class="media-gallery-prev" aria-label="تصویر قبلی">❮</button>'
            + '<img class="media-gallery-image" alt="تصویر محصول">'
            + '<button type="button" class="media-gallery-next" aria-label="تصویر بعدی">❯</button></div>'
            + '<span class="media-lightbox-status" role="status"></span>'
            + '<div class="media-gallery-footer"><span class="media-gallery-count"></span>'
            + '<span class="media-gallery-name"></span>'
            + '<div class="media-gallery-strip" role="group" aria-label="تصاویر دیگر"></div></div></div>');
        $(document.body).append(overlay);
        galleryOpen = {root:overlay,items:items,index:0,seq:0,focus:focus};
        overlay.on('click','.media-gallery-close',closeGallery);
        overlay.on('click','.media-gallery-prev',function(){gallerySelect(galleryOpen.index-1);});
        overlay.on('click','.media-gallery-next',function(){gallerySelect(galleryOpen.index+1);});
        overlay.on('click','.media-gallery-thumb',function(){gallerySelect(Number($(this).attr('data-index')));});
        overlay.on('click',function(e){if(e.target===overlay[0])closeGallery();});
        $(document).on('keydown.mediaGallery',function(e){
            if (!galleryOpen) return;
            if (e.key === 'Escape') {e.preventDefault();closeGallery();}
            if (e.key === 'ArrowLeft') {e.preventDefault();gallerySelect(galleryOpen.index+1);}
            if (e.key === 'ArrowRight') {e.preventDefault();gallerySelect(galleryOpen.index-1);}
        });
        var x0 = null;
        overlay.on('touchstart',function(e){
            if (e.originalEvent.touches.length===1) x0=e.originalEvent.touches[0].clientX;
        });
        overlay.on('touchend',function(e){
            if (x0===null || !e.originalEvent.changedTouches.length) return;
            var dx=e.originalEvent.changedTouches[0].clientX-x0;
            x0=null;
            if(Math.abs(dx)>60 && galleryOpen && galleryOpen.items.length>1)
                gallerySelect(galleryOpen.index+(dx>0?1:-1));
        });
        gallerySelect(Math.max(0,items.findIndex(function(m){return Number(m.id)===clickedId;})));
        overlay.find('.media-gallery-close').trigger('focus');
    }
    $(document).on('click','.media-preview,.media-thumb',function() {
        var id = Number($(this).attr('data-id') || 0);
        if (!id) return;
        var source = this, $source = $(source);
        var $owner = $source.closest('[data-media-editor]'),
            ownerName = $owner.attr('data-media-editor'),
            productId = Number($source.attr('data-product-id') || 0),
            defectiveId = Number($source.attr('data-defective-id') || 0);
        if (ownerName && state(ownerName).items.length) {
            openGallery(id,state(ownerName).items.slice(),source);
            return;
        }
        if (!productId && !defectiveId) {
            // A single library/category image has no unambiguous product gallery.
            openGallery(id,[{id:id}],source);
            return;
        }
        // A thumbnail responds immediately even if gallery metadata is slow.
        openGallery(id,[{id:id}],source);
        var mode = defectiveId ? 'defective' : 'product';
        galleryRequest = $.getJSON('assets/php/media_api.php',defectiveId
            ? {action:mode,defective_id:defectiveId}
            : {action:mode,product_id:productId}).done(function(res){
            if (!galleryOpen || galleryOpen.focus!==source || !res || !res.success) return;
            var items=(res.data && res.data.items) || [];
            if (items.length) {
                galleryOpen.items=items;
                gallerySelect(Math.max(0,items.findIndex(function(m){return Number(m.id)===id;})));
            }
        });
    });

    // Full-page library manager shares the same API and picker.
    if ($('#mediaLibraryPage').length) {
        var refresh = function () { loadManager(); };
        loadManager = function () {
            var box = $('#mediaManagerGrid').text('در حال بارگذاری...');
            if (managerRequest && managerRequest.readyState !== 4) managerRequest.abort();
            var seq = ++managerSequence;
            managerRequest = $.getJSON('assets/php/media_api.php', {action:'list',page:libraryPage,search:libraryTerm,usage:libraryUsage,folder:libraryFolder})
                .done(function (res) {
                    if (seq !== managerSequence) return;
                    if (!res || !res.success) {box.text(res.message || 'خطا');return;}
                    var data = res.data || {}, html = '';
                    (data.items || []).forEach(function (m) {
                        var used = Number(m.products_count || 0)+Number(m.categories_count || 0)+Number(m.defective_count || 0);
                        html += '<article class="media-manager-card"><img data-id="' + m.id + '" class="media-thumb" loading="lazy" decoding="async" width="180" height="180" src="' + escapeHtml(m.url) + '" alt="">'
                            + '<strong>' + escapeHtml(m.original_filename) + '</strong>'
                            + '<small>' + m.width + ' × ' + m.height + ' — ' + used + ' مورد استفاده</small>'
                            + '<label class="media-folder-card-label">پوشه <select class="media-manager-move" data-id="' + Number(m.id) + '">' + moveOptions(m.folder_id || 0) + '</select></label>'
                            + '<button type="button" data-id="' + m.id + '" class="media-manager-delete" ' + (used ? 'disabled title="در حال استفاده"' : '') + '>حذف از کتابخانه</button></article>';
                    });
                    box.html(html || '<p>تصویری یافت نشد.</p>');
                    $('#mediaManagerPageText').text('صفحه ' + data.page + ' از ' + data.pages);
                    $('#mediaManagerPrev').prop('disabled', data.page<=1);
                    $('#mediaManagerNext').prop('disabled', data.page>=data.pages);
                }).fail(function (xhr,status) {
                    if (status !== 'abort' && seq === managerSequence) box.text('خطای ارتباط با سرور');
                });
        };
        var managerTimer = null;
        $('#mediaManagerSearch').on('input',function(){
            var value=$(this).val();
            clearTimeout(managerTimer);
            managerTimer=setTimeout(function(){libraryTerm=value;libraryPage=1;refresh();},220);
        });
        $('#mediaManagerUsage').on('change',function(){libraryUsage=$(this).val();libraryPage=1;refresh();});
        $('#mediaManagerFolder').on('change',function(){libraryFolder=String($(this).val());libraryPage=1;refresh();});
        $(document).on('change','.media-manager-move',function(){
            var select=$(this),id=Number(select.data('id')),folder=Number(select.val());
            select.prop('disabled',true);
            $.post('assets/php/media_api.php',{action:'media_move',id:id,folder_id:folder,csrf_token:csrf()},function(res){
                if (!res || !res.success) alert((res && res.message)||'جابه‌جایی تصویر ناموفق بود.');
                loadFolders().done(refresh);
            },'json').fail(function(){alert('خطا در جابه‌جایی تصویر.');refresh();})
                .always(function(){select.prop('disabled',false);});
        });
        $('#mediaManagerPrev').on('click',function(){libraryPage=Math.max(1,libraryPage-1);refresh();});
        $('#mediaManagerNext').on('click',function(){libraryPage++;refresh();});
        $('#mediaManagerUpload').on('click',function(){openPicker(null,true);});
        $(document).on('click','.media-manager-delete',function(){
            if (!confirm('حذف دائمی این تصویر بدون استفاده؟'))return;
            $.post('assets/php/media_api.php',{action:'delete',id:$(this).data('id'),csrf_token:csrf()},function(res){
                if (!res.success)alert(res.message||'حذف ناموفق بود');refresh();
            },'json').fail(function(){alert('خطای ارتباط');});
        });
        loadFolders().done(refresh);
    }
    if ($('#mediaSettingsForm').length) {
        $.getJSON('assets/php/media_settings.php').done(function(res){
            if(!res.success)return;
            var settings=res.data.settings||{};
            $('#mediaSettingsForm [data-media-setting]').each(function(){
                $(this).prop('checked', Number(settings[$(this).data('media-setting')])===1);
            });
        });
        $('#mediaSettingsForm').on('submit',function(e){
            e.preventDefault();
            var payload={csrf_token:csrf()};
            $(this).find('[data-media-setting]').each(function(){
                payload[$(this).data('media-setting')]=$(this).prop('checked')?'1':'0';
            });
            $('#mediaSettingsSave').prop('disabled',true);
            $.post('assets/php/media_settings.php',payload,function(res){
                $('#mediaSettingsMessage').text(res.message|| (res.success?'ذخیره شد':'خطا'));
            },'json').fail(function(){$('#mediaSettingsMessage').text('خطای ارتباط با سرور');})
                .always(function(){$('#mediaSettingsSave').prop('disabled',false);});
        });
    }
})(jQuery);
