/* Accounting ledger UI. All amounts displayed in the active unit; server persists TOMAN. */
$(function () {
    'use strict';
    var root = $('#ledgerApp');
    if (!root.length) return;
    var csrf = root.data('csrf'), current = null, selection = null, partyRequest = null;
    var pendingPaymentSignature=null, pendingPaymentToken=null;
    var endpoint = 'assets/php/ledger/ledger_action.php';
    function escapeHtml(v) { return $('<span/>').text(v == null ? '' : String(v)).html(); }
    function money(v) { return window.Currency.format(v == null ? '0' : v); }
    function decimal(v) {
        try { return window.Currency.toBase(String(v == null ? '' : v)); } catch (e) { return null; }
    }
    function sameCurrency(a,b) {
        function cents(s) {
            var str=String(s).trim(),negative=str.charAt(0)==='-';
            if(negative)str=str.slice(1);
            var p=str.split('.');
            var value=Number(p[0])*100+Number((p[1]||'').padEnd(2,'0'));
            return negative?-value:value;
        }
        return cents(a)-cents(b);
    }
    function newToken(){
        var bytes=new Uint8Array(24);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes,function(v){return v.toString(16).padStart(2,'0');}).join('');
    }
    function todayISO() {
        var now=new Date(),local=new Date(now.getTime()-now.getTimezoneOffset()*60000);
        return local.toISOString().slice(0,10);
    }
    function postLedger(data,onSuccess) {
        data.csrf_token=csrf;
        $.post(endpoint,data).done(function(res) {
            if (!res.success) {alert(res.message||'ثبت انجام نشد.');return;}
            onSuccess(res.data);
        }).fail(function(xhr){alert((xhr.responseJSON||{}).message||'خطای ارتباط یا ثبت عملیات.');});
    }
    function presentPartyList(rows) {
        var box=$('#ledgerParty').empty().append($('<option/>').val('').text('انتخاب کنید'));
        rows.forEach(function(r){ $('<option/>').val(r.id).text(r.name+' ('+(r.phone||'')+')').appendTo(box);});
        if(selection && String(selection.id)) {
            box.val(String(selection.id));
            if(!box.val()) {
                $('<option/>').val(selection.id).text('طرف حساب انتخاب‌شده').appendTo(box);
                box.val(String(selection.id));
            }
            if(box.val()) {loadAccount(); return;}
        }
    }
    function searchParties() {
        if(partyRequest) partyRequest.abort();
        var q=$('#ledgerPartySearch').val();
        partyRequest=$.getJSON('assets/php/invoice_parties.php', {party_type: $('#ledgerKind').val(),search:q})
            .done(function(res){presentPartyList(res.data||[]);});
    }
    var debounce=null;
    $('#ledgerPartySearch').on('input',function(){clearTimeout(debounce);debounce=setTimeout(searchParties,250);});
    $('#ledgerKind').on('change',function(){selection=null;$('#ledgerAccount').prop('hidden',true); searchParties();});
    $('#ledgerParty').on('change',loadAccount);
    function loadAccount() {
        var id=$('#ledgerParty').val();
        if(!id){$('#ledgerAccount').prop('hidden',true);return;}
        var kind=$('#ledgerKind').val();
        $.getJSON(endpoint, {party_kind:kind,party_id:id,details:1}).done(function(res){
            if(!res.success){alert(res.message||'خطا');return;}
            current=res.data;
            $('#ledgerAccount').prop('hidden',false);
            $('#ledgerPartyName').text((current.party.name||'')+' ('+(current.party.phone||'')+')');
            $('#ledgerTotal').text(money(current.summary.total_invoices));
            $('#ledgerDebt').text(money(current.summary.debt));
            $('#ledgerCredit').text(money(current.summary.credit));
            var statement=current.statement||{entries:[],truncated:false};
            $('#ledgerStatementWarning').prop('hidden',!statement.truncated);
            var ledgerHtml='';
            (statement.entries||[]).forEach(function(ev){
                var positive=sameCurrency(ev.delta,'0')>0,abs=String(ev.delta||'0').replace(/^-/, '');
                var labels={opening:'افتتاحیه',invoice:'فاکتور قطعی',payment:'دریافت / پرداخت',
                    historical:'تسویه تاریخی',sales_return:'برگشت فروش'};
                ledgerHtml+='<tr><td>'+escapeHtml(ev.occurred_on)+'</td><td>'+escapeHtml(labels[ev.event_type]||ev.event_type)+'</td>'
                    +'<td>'+escapeHtml(ev.description)+'</td><td>'+(positive?money(abs):'—')+'</td>'
                    +'<td>'+(!positive?money(abs):'—')+'</td><td>'+money(ev.running_balance)+'</td></tr>';
            });
            $('#ledgerStatement').html(ledgerHtml||'<tr><td colspan="6">گردش مالی وجود ندارد.</td></tr>');

            var rows='';
            current.invoices.forEach(function(inv){
                var locked=inv.legacy_classification==='requires_reconciliation';
                var due=String(inv.due||'0');
                rows+='<tr><td>'+escapeHtml(inv.invoice_number)+(locked?' <strong>نیازمند تعیین مبلغ تاریخی</strong>':'')+'</td>'
                    +'<td>'+money(inv.total)+'</td><td>'+money(inv.paid)+'</td><td>'+money(inv.due)+'</td>'
                    +'<td><input class="ledger-allocate" type="number" min="0" step="0.01" data-invoice-id="'+inv.id+'"'
                    +' data-due="'+escapeHtml(due)+'" data-locked="'+(locked||sameCurrency(due,'0')<=0?'1':'0')+'" '+(locked||sameCurrency(due,'0')<=0?'disabled':'')
                    +' placeholder="0" aria-label="مبلغ تخصیص فاکتور '+escapeHtml(inv.invoice_number)+'"></td></tr>';
            });
            $('#ledgerInvoices').html(rows||'<tr><td colspan="5">فاکتوری وجود ندارد.</td></tr>');
            var pays='';
            (current.payments||[]).forEach(function(p){
                pays+='<tr><td>'+escapeHtml(p.paid_on)+'</td><td>'+escapeHtml((p.direction===($('#ledgerKind').val()==='customer'?'receipt':'disbursement')?'دریافت/پرداخت':'استرداد')+' - '+(({cash:'نقدی',pos:'کارت‌خوان',bank_transfer:'انتقال بانکی'})[p.method]||p.method))
                    +'</td><td>'+money(p.amount)+'</td><td>'+escapeHtml(p.reference_no)+'</td><td>'+escapeHtml(p.note)+(p.voided_at?' (باطل‌شده: '+escapeHtml(p.void_reason||'')+')':'')+'</td>'
                    +'<td>'+(p.voided_at?'ابطال شده':'<button type="button" class="button-danger small ledger-void" data-payment-id="'+escapeHtml(p.id)+'">ابطال ثبت اشتباه</button>')+'</td></tr>';
            });
            $('#ledgerPayments').html(pays||'<tr><td colspan="6">تراکنشی ثبت نشده است.</td></tr>');
            var creditPayments=$('#ledgerCreditPayment').empty().append('<option value="">انتخاب پرداخت دارای مانده آزاد</option>');
            (current.payments||[]).forEach(function(p){
                if(p.voided_at!==null || sameCurrency(p.unapplied||'0','0')<=0)return;
                $('<option/>').val(p.id).text('#'+p.id+' - '+p.paid_on+' - '+money(p.unapplied)).appendTo(creditPayments);
            });
            var creditInvoices=$('#ledgerCreditInvoice').empty().append('<option value="">انتخاب فاکتور</option>');
            var reconciliation=$('#ledgerReconcileInvoice').empty().append('<option value="">انتخاب فاکتور تاریخی</option>');
            current.invoices.forEach(function(inv){
                if(inv.legacy_classification==='requires_reconciliation'){
                    $('<option/>').val(inv.id).text(inv.invoice_number+' ('+money(inv.total)+')').appendTo(reconciliation);
                }else if(sameCurrency(inv.due,'0')>0){
                    $('<option/>').val(inv.id).text(inv.invoice_number+' ('+money(inv.due)+')').appendTo(creditInvoices);
                }
            });
            $('#ledgerOpeningForm button').prop('disabled',!!current.summary.opening_recorded);
            $('#ledgerAmount').val('');$('#ledgerPaymentForm')[0].reset();
            $('#ledgerDate,#ledgerOpeningDate').val(todayISO());
            showAllocation();
        }).fail(function(xhr){alert((xhr.responseJSON||{}).message||'خطا در بارگذاری حساب.');});
    }
    function showAllocation() {
        var total='0.00', invalid=false;
        $('.ledger-allocate').each(function(){
            var raw=String($(this).val()||'').trim();
            if(!raw) return;
            var base=decimal(raw);
            if(base===null||sameCurrency(base,'0')<0||sameCurrency(base,$(this).data('due'))>0){invalid=true;return;}
            total=(Number(total)+Number(base)).toFixed(2);
        });
        var payment=decimal($('#ledgerAmount').val());
        if(payment===null)payment='0';
        $('#ledgerAllocationSummary').text('تخصیص به فاکتورها: '+money(total)+' | اعتبار تخصیص‌نیافته: '+money(Math.max(0,Number(payment)-Number(total)).toFixed(2))+(invalid||sameCurrency(total,payment)>0?' | تخصیص نامعتبر است':''));
        return !invalid&&sameCurrency(total,payment)<=0;
    }
    $('#ledgerDirection').on('change',function(){
        var reverse=$(this).val()==='reverse';
        $('.ledger-allocate').each(function(){
            if(reverse)$(this).val('').prop('disabled',true);
            else $(this).prop('disabled',$(this).attr('data-locked')==='1');
        });
        showAllocation();
    });
    root.on('input','.ledger-allocate,#ledgerAmount',showAllocation);
    $('#ledgerPaymentForm').on('submit',function(e) {
        e.preventDefault();
        if(!current) return;
        var baseAmount=decimal($('#ledgerAmount').val());
        if(baseAmount===null||sameCurrency(baseAmount,'0')<=0||!showAllocation()){alert('مبلغ یا تخصیص معتبر نیست.');return;}
        var allocations=[],error=false;
        $('.ledger-allocate').each(function(){
            var raw=String($(this).val()||'').trim();if(!raw) return;
            var amount=decimal(raw);if(amount===null){error=true;return;}
            if(sameCurrency(amount,'0')>0)allocations.push({invoice_id:Number($(this).data('invoice-id')),amount:amount});
        });
        if(error)return alert('مبلغ نامعتبر است.');
        var refund=$('#ledgerDirection').val()==='reverse';
        if(refund && String($('#ledgerNote').val()||'').trim().length<8)return alert('برای استرداد وجه، توضیح مستند حداقل ۸ کاراکتر لازم است.');
        if(!confirm((refund?'استرداد واقعی':'تراکنش مالی')+' با مبلغ '+money(baseAmount)+' ثبت شود؟'))return;
        var signature=JSON.stringify([$('#ledgerKind').val(),$('#ledgerParty').val(),$('#ledgerDirection').val(),baseAmount,$('#ledgerDate').val(),$('#ledgerMethod').val(),$('#ledgerReference').val(),$('#ledgerNote').val(),allocations]);
        if(signature!==pendingPaymentSignature||!pendingPaymentToken){
            pendingPaymentSignature=signature;pendingPaymentToken=newToken();
        }
        var key=pendingPaymentToken;
        var btn=$('#ledgerSubmit').prop('disabled',true);
        $.post(endpoint, {
            csrf_token:csrf, party_kind:$('#ledgerKind').val(),party_id:$('#ledgerParty').val(),
            amount:$('#ledgerAmount').val(),paid_on:$('#ledgerDate').val(),method:$('#ledgerMethod').val(),
            direction:($('#ledgerKind').val()==='customer' ? (refund?'disbursement':'receipt') : (refund?'receipt':'disbursement')),
            reference_no:$('#ledgerReference').val(),note:$('#ledgerNote').val(),
            allocations:JSON.stringify(allocations),request_token:key
        }).done(function(res){if(!res.success){alert(res.message||'ثبت ناموفق بود');return;}pendingPaymentSignature=null;pendingPaymentToken=null;alert('تراکنش ثبت شد.');loadAccount();})
        .fail(function(xhr){alert((xhr.responseJSON||{}).message||'خطای ثبت تراکنش');})
        .always(function(){btn.prop('disabled',false);});
    });
    root.on('click','.ledger-void',function(){
        if(!current)return;
        var id=String($(this).data('payment-id'));
        var reason=window.prompt('برای ابطال ثبت اشتباه، علت مستند (حداقل ۱۲ کاراکتر) را وارد کنید. این گزینه استرداد واقعی وجه نیست.');
        if(reason===null)return;
        reason=String(reason).trim();
        if(reason.length<12)return alert('توضیح مستند حداقل ۱۲ کاراکتر لازم است.');
        if(!confirm('تراکنش شماره '+id+' به‌عنوان ثبت اشتباه باطل شود؟'))return;
        postLedger({action:'void_payment',party_kind:$('#ledgerKind').val(),party_id:$('#ledgerParty').val(),
            payment_id:id,reason:reason},function(){alert('سند بدون حذف تاریخچه باطل شد.');loadAccount();});
    });
    $('#ledgerCreditForm').on('submit',function(e){
        e.preventDefault();if(!current)return;
        var amount=decimal($('#ledgerCreditAmount').val()),pid=$('#ledgerCreditPayment').val(),iid=$('#ledgerCreditInvoice').val();
        if(!pid||!iid||amount===null||sameCurrency(amount,'0')<=0)return alert('مبلغ یا مقصد تخصیص نامعتبر است.');
        if(!confirm('اعتبار قبلی بدون تراکنش نقدی جدید به فاکتور تخصیص داده شود؟'))return;
        postLedger({action:'apply_credit',party_kind:$('#ledgerKind').val(),party_id:$('#ledgerParty').val(),
            payment_id:pid,invoice_id:iid,amount:$('#ledgerCreditAmount').val(),request_token:newToken()},function(){alert('اعتبار اعمال شد.');loadAccount();});
    });
    $('#ledgerOpeningForm').on('submit',function(e){
        e.preventDefault();if(!current)return;
        var raw=$('#ledgerOpeningAmount').val(),amount=decimal(raw);
        if(amount===null)return alert('مانده افتتاحیه نامعتبر است.');
        if(!confirm('مانده افتتاحیه قابل ویرایش نیست. ثبت شود؟'))return;
        postLedger({action:'opening',party_kind:$('#ledgerKind').val(),party_id:$('#ledgerParty').val(),
            signed_amount:raw,effective_on:$('#ledgerOpeningDate').val(),note:$('#ledgerOpeningNote').val()},function(){alert('مانده افتتاحیه ثبت شد.');loadAccount();});
    });
    $('#ledgerReconcileForm').on('submit',function(e){
        e.preventDefault();if(!current)return;
        var iid=$('#ledgerReconcileInvoice').val(),amount=decimal($('#ledgerReconcileAmount').val());
        if(!iid||amount===null||sameCurrency(amount,'0')<0)return alert('مبلغ تاریخی نامعتبر است.');
        if(!confirm('این اصلاح یک‌بار مصرف است و دریافت نقدی ایجاد نمی‌کند. ثبت شود؟'))return;
        postLedger({action:'reconcile_partial',invoice_id:iid,paid_amount:$('#ledgerReconcileAmount').val(),
            explanation:$('#ledgerReconcileExplanation').val()},function(){alert('سابقه تاریخی اصلاح شد.');loadAccount();});
    });
    var params=new URLSearchParams(location.search),kind=params.get('kind'),id=params.get('party_id');
    if(kind==='supplier'||kind==='customer')$('#ledgerKind').val(kind);
    if(id&&/^\d+$/.test(id))selection={id:id};
    searchParties();
});
