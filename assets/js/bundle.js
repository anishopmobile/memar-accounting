/* ============================================================
 * bundle.js — ماژول بسته‌بندی کالا (Product Bundling) — Frontend
 * سازگار با ساختار فعلی پروژه (jQuery + AJAX + JSON)
 *
 * فقط در صفحه «کالاها و خدمات» فعال می‌شود (وجود #bundleModal).
 * تمام محاسبات فقط برای پیش‌نمایش است؛ منبع حقیقت سمت سرور است.
 * ========================================================== */
$(function () {
    if ($('#bundleModal').length === 0) {
        return; // فقط صفحه کالاها و خدمات
    }

    var csrf = String($('#bundleCsrfToken').val() || '');
    var comps = {};          // pid => { id, code, barcode, name, unit, sale_price, purchase_price, stock, qty }
    var searchTimer = null;

    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function splitNum(n) {
        var parts = String(n).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }
    function unformat(v) { return String(v == null ? '' : v).replace(/[^0-9.]/g, ''); }
    function toNum(v) { var n = parseFloat(unformat(v)); return isNaN(n) ? 0 : n; }
    function formatPriceInput(v) {
        var n = unformat(v);
        if (n === '') { return ''; }
        return splitNum(n);
    }
    function stockLabel(v) {
        var s = String(v == null ? '0' : v);
        return s.replace(/\.?0+$/, '') || '0';
    }

    /* ---------------- محاسبات پیش‌نمایش (سرور منبع حقیقت است) ---------------- */
    function calcPurchase() {
        var total = 0;
        $.each(comps, function (pid, c) {
            total += toNum(c.purchase_price) * (parseInt(c.qty, 10) || 0);
        });
        return total;
    }
    function calcSale() {
        var total = 0;
        $.each(comps, function (pid, c) {
            total += toNum(c.sale_price) * (parseInt(c.qty, 10) || 0);
        });
        return total;
    }
    function calcAvailability() {
        var min = null;
        var any = false;
        $.each(comps, function (pid, c) {
            any = true;
            var qty = parseInt(c.qty, 10) || 0;
            var stock = toNum(c.stock);
            if (qty <= 0 || stock <= 0) { min = (min === null) ? 0 : Math.min(min, 0); return; }
            var n = Math.floor(stock / qty);
            min = (min === null) ? n : Math.min(min, n);
        });
        if (!any) { return 0; }
        return min === null ? 0 : min;
    }

    function recalcBundle() {
        var purchase = calcPurchase();
        var sale = calcSale();
        var avail = calcAvailability();

        $('#bundlePurchaseCalc').text('مجموع اجزا: ' + splitNum(purchase) + ' ریال');
        $('#bundleSaleCalc').text('مجموع اجزا: ' + splitNum(sale) + ' ریال');
        $('#bundleStock').val(avail);

        // اگر کاربر قیمت نهایی را دستی تغییر نداده باشد، مقدار محاسبه‌شده پیشنهاد می‌شود
        if (!$('#bundlePurchasePrice').data('touched')) {
            $('#bundlePurchasePrice').val(purchase > 0 ? formatPriceInput(String(purchase)) : '');
        }
        if (!$('#bundleSalePrice').data('touched')) {
            $('#bundleSalePrice').val(sale > 0 ? formatPriceInput(String(sale)) : '');
        }
    }

    function renderComponents() {
        var $tbody = $('#bundleComponentsTable tbody');
        var pids = Object.keys(comps);
        if (pids.length === 0) {
            $tbody.html('<tr class="empty-state-row"><td colspan="8" class="empty-state">کالایی افزوده نشده است. از دکمه «افزودن کالا» استفاده کنید.</td></tr>');
            recalcBundle();
            return;
        }
        var html = '';
        pids.forEach(function (pid) {
            var c = comps[pid];
            var maxQty = Math.max(1, Math.floor(toNum(c.stock)));
            var qty = parseInt(c.qty, 10) || 1;
            html += '<tr data-pid="' + escAttr(pid) + '">'
                + '<td>' + esc(c.code) + '</td>'
                + '<td>' + esc(c.barcode) + '</td>'
                + '<td>' + esc(c.name) + '</td>'
                + '<td>' + esc(c.unit) + '</td>'
                + '<td>' + splitNum(c.sale_price) + '</td>'
                + '<td>' + stockLabel(c.stock) + '</td>'
                + '<td><span class="bc-qty-wrap">'
                + '<button type="button" class="bc-qty-btn bc-minus" data-pid="' + escAttr(pid) + '"' + (qty <= 1 ? ' disabled' : '') + '>−</button>'
                + '<input type="text" class="bundle-qty bc-qty" data-pid="' + escAttr(pid) + '" value="' + qty + '" readonly>'
                + '<button type="button" class="bc-qty-btn bc-plus" data-pid="' + escAttr(pid) + '"' + (qty >= maxQty ? ' disabled' : '') + '>+</button>'
                + '</span></td>'
                + '<td><button type="button" class="button-secondary small bc-remove" data-pid="' + escAttr(pid) + '">حذف</button></td>'
                + '</tr>';
        });
        $tbody.html(html);
        recalcBundle();
        renderProductModalQty();
    }

    function renderProductModalQty() {
        $('#bundleProductTable tbody tr[data-pid]').each(function () {
            var pid = String($(this).attr('data-pid'));
            var c = comps[pid];
            $('.bp-qty', this).text(c ? (parseInt(c.qty, 10) || 0) : 0);
        });
    }

    /* ---------------- باز/بسته کردن مودال بسته ---------------- */
    function openBundleModal() {
        $('#bundleModal').addClass('open');
        $('body').addClass('modal-open');
    }
    function closeBundleModal() {
        $('#bundleModal').removeClass('open');
        $('#bundleProductModal').removeClass('open');
        $('body').removeClass('modal-open');
        resetBundleForm();
    }
    function resetBundleForm() {
        $('#bundleForm')[0].reset();
        $('#bundleId').val('');
        $('#bundleCode').val('');
        $('#bundleBarcode').val('');
        $('#bundleName').val('');
        $('#bundleMinStock').val('0');
        $('#bundleDescription').val('');
        $('#bundlePurchasePrice').val('').removeData('touched');
        $('#bundleSalePrice').val('').removeData('touched');
        $('#bundleComponentsJson').val('');
        var activeCategory = $('#productCategoryFilter').val() || '';
        $('#bundleCategory').val(activeCategory);
        comps = {};
        $('#bundleModalTitle').text('بسته‌بندی کالا');
        $('#saveBundleButton').text('ذخیره');
        renderComponents();
        $('#bundleStock').val('0');
        $('#bundlePurchaseCalc').text('');
        $('#bundleSaleCalc').text('');
    }
    function loadNextBundleCode() {
        $.getJSON('assets/php/bundle_action.php', { action: 'next_code' }).done(function (res) {
            if (res && res.success && res.data) {
                $('#bundleCode').val(res.data.code);
            }
        });
    }
    function openNewBundle() {
        resetBundleForm();
        loadNextBundleCode();
        openBundleModal();
        $('#bundleName').focus();
    }
    function loadBundle(id) {
        $.getJSON('assets/php/bundle_get.php?id=' + encodeURIComponent(id)).done(function (res) {
            if (!res || !res.success || !res.data) {
                alert((res && res.message) || 'خطا در دریافت اطلاعات بسته.');
                return;
            }
            var data = res.data;
            var p = data.product || {};
            resetBundleForm();
            $('#bundleId').val(p.id || '');
            $('#bundleCode').val(p.code || '');
            $('#bundleBarcode').val(p.barcode || '');
            $('#bundleName').val(p.name || '');
            $('#bundleCategory').val(p.category_id || '');
            $('#bundleMinStock').val(stockLabel(p.min_stock || '0'));
            $('#bundleDescription').val(p.description || '');
            $('#bundlePurchasePrice').val(formatPriceInput(p.purchase_price)).data('touched', 1);
            $('#bundleSalePrice').val(formatPriceInput(p.sale_price)).data('touched', 1);
            (data.components || []).forEach(function (c) {
                comps[String(c.component_product_id)] = {
                    id: String(c.component_product_id),
                    code: c.code || '', barcode: c.barcode || '', name: c.name || '', unit: c.unit || '',
                    sale_price: c.sale_price || '0', purchase_price: c.purchase_price || '0',
                    stock: c.stock || '0', qty: parseInt(c.quantity, 10) || 1
                };
            });
            $('#bundleModalTitle').text('ویرایش بسته');
            $('#saveBundleButton').text('تایید ویرایش');
            renderComponents();
            openBundleModal();
        }).fail(function () { alert('خطا در ارتباط با سرور.'); });
    }

    /* ---------------- مودال انتخاب کالای جزء ---------------- */
    function openBundleProductModal() {
        $('#bundleProductModal').addClass('open');
        $('#bundleProductSearch').val('');
        loadBundleProducts('');
        $('#bundleProductSearch').focus();
    }
    function closeBundleProductModal() {
        $('#bundleProductModal').removeClass('open');
    }
    function loadBundleProducts(q) {
        var url = 'assets/php/bundle_products.php?search=' + encodeURIComponent(q || '');
        var bid = $('#bundleId').val();
        if (bid) { url += '&exclude_id=' + encodeURIComponent(bid); }
        $('#bundleProductTable tbody').html('<tr><td colspan="8" class="empty-state">در حال بارگذاری...</td></tr>');
        $.getJSON(url).done(function (res) {
            var rows = (res && res.data) ? res.data : [];
            if (!rows.length) {
                $('#bundleProductTable tbody').html('<tr><td colspan="8" class="empty-state">کالایی یافت نشد.</td></tr>');
                return;
            }
            var html = '';
            rows.forEach(function (p) {
                var stock = Math.floor(toNum(p.stock));
                var existing = comps[String(p.id)];
                var cur = existing ? (parseInt(existing.qty, 10) || 0) : 0;
                var full = stock > 0 && cur >= stock;
                html += '<tr data-pid="' + escAttr(p.id) + '">'
                    + '<td>' + esc(p.code) + '</td>'
                    + '<td>' + esc(p.barcode) + '</td>'
                    + '<td>' + esc(p.name) + '</td>'
                    + '<td>' + esc(p.unit) + '</td>'
                    + '<td>' + splitNum(p.sale_price) + '</td>'
                    + '<td>' + stockLabel(p.stock) + '</td>'
                    + '<td><span class="bp-qty">' + cur + '</span></td>'
                    + '<td><button type="button" class="button-secondary small bundle-product-pick"'
                    + ' data-id="' + escAttr(p.id) + '" data-code="' + escAttr(p.code) + '" data-barcode="' + escAttr(p.barcode || '') + '"'
                    + ' data-name="' + escAttr(p.name) + '" data-unit="' + escAttr(p.unit) + '"'
                    + ' data-sale-price="' + escAttr(p.sale_price) + '" data-purchase-price="' + escAttr(p.purchase_price) + '"'
                    + ' data-stock="' + escAttr(p.stock) + '"' + (full ? ' disabled' : '')
                    + '>افزودن</button></td>'
                    + '</tr>';
            });
            $('#bundleProductTable tbody').html(html);
        }).fail(function () {
            $('#bundleProductTable tbody').html('<tr><td colspan="8" class="empty-state">خطا در جستجوی کالا.</td></tr>');
        });
    }

    /* ---------------- رویدادها ---------------- */
    $(document).on('click', '#openBundleModal', function (e) { e.preventDefault(); openNewBundle(); });
    $(document).on('click', '#closeBundleModal, #cancelBundleModal', function (e) { e.preventDefault(); closeBundleModal(); });
    $(document).on('click', '#bundleModal .modal-backdrop', function () { closeBundleProductModal(); closeBundleModal(); });
    $(document).on('click', '#bundleAddProducts', function (e) { e.preventDefault(); openBundleProductModal(); });
    $(document).on('click', '#closeBundleProductModal, #cancelBundleProductModal', function (e) { e.preventDefault(); closeBundleProductModal(); });
    $(document).on('click', '#bundleProductModal .modal-backdrop', function () { closeBundleProductModal(); });

    $(document).on('click', '.edit-bundle', function (e) {
        e.preventDefault();
        var pid = $(this).closest('tr.product-row').data('id');
        if (pid) { loadBundle(pid); }
    });

    $(document).on('input', '#bundleProductSearch', function () {
        var q = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { loadBundleProducts(q); }, 150);
    });
    $(document).on('keydown', '#bundleProductSearch', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimer);
            loadBundleProducts($(this).val());
        }
    });

    $(document).on('click', '.bundle-product-pick', function () {
        var d = $(this).data();
        var pid = String(d.id);
        var stock = Math.floor(toNum(d.stock));
        if (stock <= 0) { return; }
        var existing = comps[pid];
        if (existing) {
            if ((parseInt(existing.qty, 10) || 0) >= stock) { return; }
            existing.qty = (parseInt(existing.qty, 10) || 0) + 1;
        } else {
            comps[pid] = {
                id: pid, code: d.code || '', barcode: d.barcode || '', name: d.name || '', unit: d.unit || '',
                sale_price: d.salePrice, purchase_price: d.purchasePrice, stock: d.stock, qty: 1
            };
        }
        renderComponents();
        var $row = $('#bundleProductTable tbody tr[data-pid="' + pid + '"]');
        $row.find('.bp-qty').text(comps[pid].qty);
        if (comps[pid].qty >= stock) { $row.find('.bundle-product-pick').prop('disabled', true); }
    });

    $(document).on('click', '.bc-plus', function () {
        var pid = String($(this).data('pid'));
        var c = comps[pid];
        if (!c) { return; }
        var max = Math.max(1, Math.floor(toNum(c.stock)));
        c.qty = Math.min(max, (parseInt(c.qty, 10) || 1) + 1);
        renderComponents();
    });
    $(document).on('click', '.bc-minus', function () {
        var pid = String($(this).data('pid'));
        var c = comps[pid];
        if (!c) { return; }
        c.qty = Math.max(1, (parseInt(c.qty, 10) || 1) - 1);
        renderComponents();
    });
    $(document).on('click', '.bc-remove', function () {
        var pid = String($(this).data('pid'));
        delete comps[pid];
        renderComponents();
        $('#bundleProductTable tbody tr[data-pid="' + pid + '"] .bundle-product-pick').prop('disabled', false);
    });

    $(document).on('focus', '#bundlePurchasePrice, #bundleSalePrice', function () {
        $(this).data('touched', 1);
    });
    $(document).on('input', '#bundlePurchasePrice, #bundleSalePrice', function () {
        $(this).data('touched', 1);
        var after = formatPriceInput($(this).val());
        $(this).val(after);
    });

    /* ---------------- ذخیره بسته ---------------- */
    $('#bundleForm').on('submit', function (e) {
        e.preventDefault();

        var name = String($('#bundleName').val() || '').trim();
        if (name === '') {
            alert('نام بسته نمی‌تواند خالی باشد.');
            return;
        }
        var pids = Object.keys(comps);
        if (pids.length === 0) {
            alert('حداقل یک کالا باید به بسته افزوده شود.');
            return;
        }

        var payload = [];
        pids.forEach(function (pid) {
            payload.push({ product_id: pid, quantity: parseInt(comps[pid].qty, 10) || 1 });
        });
        $('#bundleComponentsJson').val(JSON.stringify(payload));

        // ارسال قیمت‌ها بدون جداکننده هزارگان (منبع حقیقت سرور است)
        $('#bundlePurchasePrice').val(unformat($('#bundlePurchasePrice').val()));
        $('#bundleSalePrice').val(unformat($('#bundleSalePrice').val()));

        var $btn = $('#saveBundleButton');
        $btn.prop('disabled', true);
        $.post('assets/php/bundle_action.php', $('#bundleForm').serialize() + '&action=save', function (res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                closeBundleModal();
                // تازه‌سازی جدول کالاها با هندلر موجود main.js
                var $search = $('#productSearch');
                if ($search.length) { $search.trigger('input'); }
                if (res.message) { alert(res.message); }
            } else {
                alert((res && res.message) || 'خطا در ذخیره بسته.');
            }
        }, 'json').fail(function () {
            $btn.prop('disabled', false);
            alert('خطا در ارتباط با سرور.');
        });
    });
});




