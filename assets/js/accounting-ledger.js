/* Accounting ledger UI. All amounts displayed in the active unit; server persists TOMAN. */
$(function () {
    'use strict';
    var root = $('#ledgerApp');
    if (!root.length) return;
    var csrf = root.data('csrf'), current = null, selection = null, partyRequest = null;
    var endpoint = 'assets/php/ledger/ledger_action.php';
    function escapeHtml(v) { return $('<span/>').text(v == null ? '' : String(v)).html(); }
    function money(v) { return window.Currency.format(v == null ? '0' : v); }
    function decimal(v) {
        try { return window.Currency.toBase(String(v == null ? '' : v)); } catch (e) { return null; }
    }
    function sameCurrency(a,b) {
        function cents(s){ var p=String(s).split('.');return Number(p[0])*100+Number((p[1]||'').padEnd(2,'0')); }
        return cents(a)-cents(b);
    }
    function presentPartyList(rows) {
        var box=$('#ledgerParty').empty().append($('<option/>').val('').text('انتخاب کنید'));
        rows.forEach(function(r){ $('<option/>').val(r.id).text(r.name+' ('+(r.phone||'')+')').appendTo(box);});
        if(selection && String(selection.id)) {
            box.val(String(selection.id));
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
            $('#ledgerPartyName').text($('#ledgerParty option:selected').text());
            $('#ledgerTotal').text(money(current.summary.total_invoices));
            $('#ledgerDebt').text(money(current.summary.debt));
            $('#ledgerCredit').text(money(current.summary.credit));
            var rows='';
            current.invoices.forEach(function(inv){
                var locked=inv.legacy_classification==='requires_reconciliation';
                var due=String(inv.due||'0');
                rows+='<tr><td>'+escapeHtml(inv.invoice_number)+(locked?' <strong>نیازمند تعیین مبلغ تاریخی</strong>':'')+'</td>'
                    +'<td>'+money(inv.total)+'</td><td>'+money(inv.paid)+'</td><td>'+money(inv.due)+'</td>'
                    +'<td><input class="ledger-allocate" type="number" min="0" step="0.01" data-invoice-id="'+inv.id+'"'
                    +' data-due="'+escapeHtml(due)+'" '+(locked||sameCurrency(due,'0')<=0?'disabled':'')
                    +' placeholder="0" aria-label="مبلغ تخصیص فاکتور '+escapeHtml(inv.invoice_number)+'"></td></tr>';
            });
            $('#ledgerInvoices').html(rows||'<tr><td colspan="5">فاکتوری وجود ندارد.</td></tr>');
            var pays='';
            (current.payments||[]).forEach(function(p){
                pays+='<tr><td>'+escapeHtml(p.paid_on)+'</td><td>'+escapeHtml(({cash:'نقدی',pos:'کارت‌خوان',bank_transfer:'انتقال بانکی'})[p.method]||p.method)
                    +'</td><td>'+money(p.amount)+'</td><td>'+escapeHtml(p.reference_no)+'</td><td>'+escapeHtml(p.note)+'</td></tr>';
            });
            $('#ledgerPayments').html(pays||'<tr><td colspan="5">تراکنشی ثبت نشده است.</td></tr>');
            $('#ledgerAmount').val('');$('#ledgerPaymentForm')[0].reset();
            $('#ledgerDate').val(new Date().toLocaleDateString('en-CA'));
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
        if(!confirm('تراکنش مالی با مبلغ '+money(baseAmount)+' ثبت شود؟'))return;
        var key=String(Date.now())+'_'+Math.random().toString(36).slice(2)+'_ledger';
        var btn=$('#ledgerSubmit').prop('disabled',true);
        $.post(endpoint, {
            csrf_token:csrf, party_kind:$('#ledgerKind').val(),party_id:$('#ledgerParty').val(),
            amount:$('#ledgerAmount').val(),paid_on:$('#ledgerDate').val(),method:$('#ledgerMethod').val(),
            reference_no:$('#ledgerReference').val(),note:$('#ledgerNote').val(),
            allocations:JSON.stringify(allocations),request_token:key
        }).done(function(res){if(!res.success){alert(res.message||'ثبت ناموفق بود');return;}alert('تراکنش ثبت شد.');loadAccount();})
        .fail(function(xhr){alert((xhr.responseJSON||{}).message||'خطای ثبت تراکنش');})
        .always(function(){btn.prop('disabled',false);});
    });
    var params=new URLSearchParams(location.search),kind=params.get('kind'),id=params.get('party_id');
    if(kind==='supplier'||kind==='customer')$('#ledgerKind').val(kind);
    if(id&&/^\d+$/.test(id))selection={id:id};
    searchParties();
});
