/* Offline accounting receipts/payments. No gateway changes. */
(function ($) {
    'use strict';
    function esc(x) { return $('<div>').text(x == null ? '' : String(x)).html(); }
    function money(x) { return window.Currency.formatNumber(String(x == null ? 0 : x)) + ' ' + window.Currency.label; }
    function show(x) { return window.Currency.fromBase(String(x == null ? 0 : x)); }
    function newToken() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() :
        'r' + Date.now().toString(36) + Math.random().toString(36).slice(2); }
    function today() { var d = new Date(); return d.getFullYear() + '-' +
        ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
    function api(action, data, done) {
        data = $.extend({ action: action, csrf_token: window.APP_CSRF_TOKEN }, data || {});
        return $.ajax({ url: 'assets/php/accounting_action.php', type: 'POST', dataType: 'json', data: data })
            .done(function (r) { if (!r.success) { alert(r.message || 'عملیات ناموفق بود.'); return; } if (done) done(r.data || {}); })
            .fail(function (xhr) { alert((xhr.responseJSON && xhr.responseJSON.message) || 'خطا در انجام عملیات مالی.'); });
    }
    function get(action, data) { return $.getJSON('assets/php/accounting_action.php', $.extend({ action: action }, data || {})); }
    function close() { $('#accountingDialog').remove(); $('body').removeClass('accounting-modal-open'); }
    function dialog(title, html, submit) {
        close();
        var modal = $('<div id="accountingDialog" class="accounting-dialog" role="dialog" aria-modal="true"></div>');
        modal.html('<div class="accounting-backdrop"></div><div class="accounting-dialog-card">' +
            '<div class="page-actions"><h3>' + esc(title) + '</h3><button type="button" class="button-secondary acct-cancel">بستن</button></div>' +
            '<form id="acctModalForm">' + html +
            '<div class="form-actions"><button type="button" class="button-secondary acct-cancel">انصراف</button>' +
            '<button type="submit" class="button" id="acctSubmit">ثبت قطعی</button></div></form></div>');
        $('body').append(modal).addClass('accounting-modal-open');
        modal.find('.acct-cancel,.accounting-backdrop').on('click', close);
        modal.find('#acctModalForm').on('submit', function (ev) {
            ev.preventDefault();
            var btn = $('#acctSubmit').prop('disabled', true);
            Promise.resolve(submit()).then(function (ok) { if (ok !== false) close(); })
                .catch(function () { /* API already reports error */ })
                .finally(function () { btn.prop('disabled', false); });
        });
        modal.find('input:visible').first().trigger('focus');
    }
    function row(label, id, type, value, extra) {
        return '<div class="form-row"><label for="' + esc(id) + '">' + esc(label) + '</label>' +
            '<input id="' + esc(id) + '" type="' + esc(type) + '" value="' + esc(value || '') + '"' + (extra || '') + '></div>';
    }
    function methodRow() {
        return '<div class="form-row"><label>روش دریافت/پرداخت</label><select id="acctMethod">' +
            '<option value="pos">کارت‌خوان</option><option value="cash">نقدی</option>' +
            '<option value="bank_transfer">انتقال بانکی</option></select></div>';
    }
    function postPayment(kind, partyId, invoiceId, proformaId, opts) {
        opts = opts || {};
        var normal = kind === 'customer' ? 'in' : 'out';
        var flow = opts.refund ? (normal === 'in' ? 'out' : 'in') : normal;
        var token = newToken();
        var title = opts.refund ? 'ثبت استرداد / بازپرداخت' : (kind === 'customer' ? 'ثبت دریافت از مشتری' : 'ثبت پرداخت به تأمین‌کننده');
        var form = '<div class="form-grid">' +
            row('مبلغ (' + window.Currency.label + ')', 'acctAmount', 'number', '', ' min="0.01" step="0.01" required') +
            row('تاریخ', 'acctDate', 'date', today(), ' required') +
            methodRow() + row('نام حساب/صندوق', 'acctAccount', 'text', '', ' maxlength="120"') +
            row('شماره پیگیری', 'acctReference', 'text', '', ' maxlength="120"') +
            row('توضیحات', 'acctNote', 'text', '', ' maxlength="500"') + '</div>';
        if (!opts.refund && !proformaId) {
            form += '<label class="form-row"><input type="checkbox" id="acctAuto" checked> تخصیص خودکار: ابتدا فاکتور فعلی، سپس بدهی‌های قدیمی‌تر</label>' +
                '<label class="form-row"><input type="checkbox" id="acctManual"> انتخاب دستی فاکتورهای قابل تسویه</label>' +
                '<div id="acctManualRows" style="display:none"></div>';
        }
        if (proformaId) form += '<p class="setting-note">این مبلغ پیش‌پرداخت ثبت می‌شود و تا زمان تبدیل پیش‌فاکتور، بدهی قطعی ایجاد نمی‌کند.</p>';
        form += '<p class="setting-note">پرداخت اضافی به صورت اعتبار طرف حساب نگهداری خواهد شد.</p>';
        dialog(title, form, function () {
            var val=$('#acctAmount').val();
            if(!val || Number(val)<=0) { alert('مبلغ پرداخت را وارد کنید.'); return false; }
            var allocations=[];
            if ($('#acctManual').is(':checked')) {
                $('#acctManualRows .acct-alloc-amount').each(function () {
                    if (Number($(this).val())>0)
                        allocations.push({ invoice_id: Number($(this).data('invoice')), amount: $(this).val() });
                });
            }
            var data = {
                party_kind: kind, party_id: partyId, flow: flow, invoice_id: invoiceId || '',
                proforma_id: proformaId || '', amount: val, method: $('#acctMethod').val(),
                account_name: $('#acctAccount').val(), reference_number: $('#acctReference').val(),
                note: $('#acctNote').val(), payment_date: $('#acctDate').val(),
                client_token: token, auto_allocate: $('#acctAuto').is(':checked') ? '1' : '0',
                allocations: JSON.stringify(allocations)
            };
            return new Promise(function (resolve) {
                api('post', data, function () { if (opts.onSuccess) opts.onSuccess(); resolve(true); })
                    .fail(function () { resolve(false); });
            });
        });
        if (!opts.refund && !proformaId) {
            $('#acctManual').on('change', function () {
                var on=$(this).is(':checked');
                $('#acctAuto').prop('checked',!on).prop('disabled',on);
                $('#acctManualRows').toggle(on);
                if (on && !$('#acctManualRows').data('loaded')) {
                    $('#acctManualRows').html('<p>در حال بارگذاری فاکتورهای باز...</p>');
                    get('party',{party_kind:kind,party_id:partyId}).done(function(res) {
                        var rows=(res.data && res.data.open)||[];
                        $('#acctManualRows').data('loaded',true).html(rows.length ?
                            '<div class="table-wrapper"><table class="action-table"><thead><tr><th>فاکتور</th><th>مانده</th><th>مبلغ تخصیص ('+esc(window.Currency.label)+')</th></tr></thead><tbody>'+
                            rows.map(function(r){
                                return '<tr><td>'+esc(r.invoice_number)+'</td><td>'+esc(money(r.remaining))+'</td>'+
                                    '<td><input type="number" class="acct-alloc-amount" data-invoice="'+Number(r.invoice_id)+'" min="0" step="0.01" max="'+esc(show(r.remaining))+'" value="0"></td></tr>';
                            }).join('')+'</tbody></table></div>' : '<p>فاکتور تسویه‌نشده‌ای وجود ندارد؛ مبلغ به اعتبار تبدیل می‌شود.</p>');
                    }).fail(function() { $('#acctManualRows').html('دریافت فاکتورهای باز با خطا مواجه شد.'); });
                }
            });
        }
    }
    function addOpening(kind, partyId, updated) {
        dialog('ثبت مانده افتتاحیه یا اصلاحیه', '<p class="setting-note">بدهی مثبت است؛ اعتبار به نفع طرف حساب منفی است. اصلاحات با سند جدید ثبت می‌شوند.</p>'+
            '<div class="form-grid">'+row('مبلغ','acctAmount','number','',' min="0.01" step="0.01" required')+
            '<div class="form-row"><label>نوع مانده</label><select id="acctSign"><option value="debt">بدهی</option><option value="credit">اعتبار</option></select></div>'+
            row('تاریخ','acctDate','date',today(),' required')+
            row('شرح و مستند افتتاحیه','acctNote','text','',' required maxlength="500"')+'</div>',function() {
            return new Promise(function(resolve){
                api('opening',{party_kind:kind,party_id:partyId,amount:$('#acctAmount').val(),
                    sign:$('#acctSign').val(),entry_date:$('#acctDate').val(),note:$('#acctNote').val()},
                    function(){updated();resolve(true);}).fail(function(){resolve(false);});
            });
        });
    }
    function confirmLegacy(id,invoiceNumber,total,updated) {
        dialog('تعیین پرداخت جزئی تاریخی '+invoiceNumber,
            '<p class="setting-note">مبلغ واقعی پرداخت‌شده در گذشته را ثبت کنید. این سند، گردش نقدی جدید ایجاد نمی‌کند. حداکثر: '+esc(money(total))+'</p>'+
            row('مبلغ پرداخت‌شده تاریخی','acctAmount','number','',' min="0" step="0.01" max="'+esc(show(total))+'" required'),function(){
            return new Promise(function(resolve){
                api('legacy',{invoice_id:id,amount:$('#acctAmount').val()},function(){updated();resolve(true);})
                    .fail(function(){resolve(false);});
            });
        });
    }
    function loadStatement() {
        var root=$('#accountStatement'); if(!root.length)return;
        var kind=root.data('kind'),partyId=Number(root.data('partyId'));
        $('#acctStatementRows').html('<tr><td colspan="6">در حال بارگذاری...</td></tr>');
        get('party',{party_kind:kind,party_id:partyId}).done(function(res){
            if(!res.success)return;
            var d=res.data||{},p=d.party||{},s=d.summary||{},events=d.events||[];
            var name=kind==='customer'?((p.first_name||'')+' '+(p.last_name||'')):
                (p.company_name||((p.first_name||'')+' '+(p.last_name||'')));
            $('#acctPartyName').text(name);
            $('#acctInvoiced').text(money(s.invoiced));
            $('#acctDebt').text(money(s.debt));
            $('#acctCredit').text(money(s.credit));
            $('#acctStatementRows').html(events.length?events.map(function(ev){
                var delta=String(ev.delta || '0');
                var negative=delta.charAt(0)==='-';
                var zero=/^-?0+(?:\\.0+)?$/.test(delta);
                var cancel=ev.receipt_id && ev.status==='posted' ?
                    '<button type="button" class="button-secondary small acct-reverse" data-receipt="'+Number(ev.receipt_id)+'">ابطال سند</button>' : '';
                return '<tr><td>'+esc(ev.date)+'</td><td>'+esc(ev.description)+'</td><td>'+
                    (!negative&&!zero?esc(money(ev.delta)):'—')+'</td><td>'+(negative&&!zero?esc(money(delta.slice(1))):'—')+'</td><td>'+
                    esc(money(ev.running))+'</td><td>'+cancel+'</td></tr>';
            }).join(''):'<tr><td colspan="6">گردشی ثبت نشده است.</td></tr>');
            var pending=d.pending_legacy||[];
            $('#acctLegacyWarning').toggle(!!pending.length).html(pending.length?
                '<strong>فاکتورهای با پرداخت جزئی قدیمی که نیازمند بررسی هستند:</strong> '+
                pending.map(function(v){return '<button type="button" class="button-secondary small acct-legacy" data-id="'+Number(v.invoice_id)+'" data-total="'+esc(v.payable_amount)+'" data-number="'+esc(v.invoice_number)+'">'+esc(v.invoice_number)+'</button>';}).join(' '):'');
        }).fail(function(){$('#acctStatementRows').html('<tr><td colspan="6">خطا در دریافت صورت‌حساب</td></tr>');});
    }
    window.AccountingUI = {
        openPayment:postPayment,
        openOpening:addOpening,
        refreshInvoice:function(invoiceId) {
            get('invoice',{invoice_id:invoiceId}).done(function(res){
                var b=res.data && res.data.balance;
                if(!b)return;
                $('#invPaidValue').text(money(b.paid));
                $('#invRemainingValue').text(money(b.remaining));
                $('#invAdvanceValue').text(money(b.advance));
                $('#invPaymentStatusText').text(({paid:'پرداخت شده',unpaid:'پرداخت نشده',partial:'پرداخت جزئی',proforma:'پیش‌فاکتور'})[b.status]||b.status);
                var table=$('#invAccountingHistory');
                if(table.length) {
                    var history=res.data.history||[];
                    table.html(history.map(function(x){
                        return '<tr><td>'+esc(x.payment_date)+'</td><td>'+esc(x.method)+'</td><td>'+esc(money(x.amount))+
                            '</td><td>'+esc(money(x.allocated))+'</td><td>'+esc(x.status)+'</td></tr>';
                    }).join('')||'<tr><td colspan="5">پرداختی ثبت نشده است.</td></tr>');
                }
            });
        }
    };
    $(function(){
        var root=$('#accountStatement');if(!root.length)return;
        var kind=root.data('kind'),partyId=Number(root.data('partyId'));
        loadStatement();
        $(document).on('click','#acctRefresh',loadStatement);
        $(document).on('click','#acctNewPayment',function(){postPayment(kind,partyId,null,null,{onSuccess:loadStatement});});
        $(document).on('click','#acctRefund',function(){postPayment(kind,partyId,null,null,{refund:true,onSuccess:loadStatement});});
        $(document).on('click','#acctOpening',function(){addOpening(kind,partyId,loadStatement);});
        $(document).on('click','.acct-legacy',function(){
            confirmLegacy(Number($(this).data('id')),$(this).data('number'),$(this).data('total'),loadStatement);
        });
        $(document).on('click','.acct-reverse',function(){
            var id=Number($(this).data('receipt'));if(!confirm('این تراکنش با ثبت سابقه ابطال می‌شود. ادامه؟'))return;
            var reason=prompt('علت ابطال تراکنش (الزامی):');
            if(!reason||!reason.trim())return;
            api('reverse',{receipt_id:id,note:reason.trim()},loadStatement);
        });
    });
})(jQuery);
