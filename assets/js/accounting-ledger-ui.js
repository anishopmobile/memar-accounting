/* Accounting entry and statement UI for sales and purchase invoices. */
(function($) {
'use strict';
$(function() {
  var isInvoicePage = $('#invoiceMode').length>0;
  var isPartyPage = $('body').is('#customers,#suppliers');
  if (!isInvoicePage && !isPartyPage) return;
  var kind=(isInvoicePage ? $('#invoiceMode').val()==='sales' : $('body').is('#customers'))?'customer':'supplier';
  var csrf=window.APP_CSRF_TOKEN;
  var partyId=0, selectedInvoice=0, sourceProformaId=0, statement=null, posting=false, paymentToken='', cashAccounts=[], autoAllocation=true, cleanPaymentRow='';
  var html='<div class="modal" id="accountingModal"><div class="modal-backdrop" data-close="accountingModal"></div>'
    +'<div class="modal-content" style="max-width:900px;max-height:90vh;overflow:auto">'
    +'<div class="modal-header"><h2>دریافت‌ها، پرداخت‌ها و صورت‌حساب</h2><button type="button" class="modal-close" id="acctClose">×</button></div>'
    +'<div id="acctContent">در حال دریافت اطلاعات...</div></div></div>';
  $('body').append(html);
  var $modal=$('#accountingModal');
  function safe(v){return $('<div>').text(v==null?'':String(v)).html();}
  function fmt(v){return window.Currency.format(v);}
  function formatLedgerDate(date){
    if(!/^\d{4}-\d{2}-\d{2}$/.test(String(date)))return 'افتتاحیه';
    try{return new Intl.DateTimeFormat('fa-IR-u-ca-persian',{year:'numeric',month:'2-digit',day:'2-digit',timeZone:'Asia/Tehran'}).format(new Date(date+'T12:00:00Z'));}
    catch(e){return safe(date);}
  }
  function input(v){return window.Currency.fromBase(v);}
  function raw(v){return window.Currency.toBase(v);}
  function zero(v){return Number(v||0)<=0;}
  function show(){ paymentToken='acct_'+Date.now()+'_'+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2); $modal.addClass('open');$('body').addClass('modal-open');}
  function close(){$modal.removeClass('open');$('body').removeClass('modal-open');}
  $('#acctClose').on('click',close);
  $modal.find('.modal-backdrop').on('click',close);
  function fail(err) {alert(err.responseJSON&&err.responseJSON.message?err.responseJSON.message:'خطا در دریافت یا ثبت اطلاعات مالی.');}
  function load(){
    $('#acctContent').text('در حال دریافت اطلاعات...');
    $.getJSON('assets/php/accounting_ledger_action.php',{action:'statement',party_kind:kind,party_id:partyId})
    .done(function(r){statement=r.data||{};render();}).fail(fail);
  }
  function render(){
    var s=statement, b=Number(s.balance), out='';
    out+='<p><strong>مانده حساب: '+(s.balance_final?fmt(Math.abs(b))+(b>=0?' بدهکار':' بستانکار'):'نیازمند تعیین وضعیت فاکتورهای قدیمی')+'</strong></p>';
    if(!s.has_opening_balance)out+='<button type="button" class="button-secondary small" id="acctOpening">ثبت مانده افتتاحیه</button>';
    if(s.balance_final&&b<0)out+='<button type="button" class="button-secondary small" id="acctRefund">استرداد اعتبار طرف حساب</button>';
    out+='<p>اعتبار تخصیص‌نیافته: '+fmt(s.unallocated_credit||0)+' | اعتبار برگشت از فروش: '+fmt(s.return_credit||0)+'</p>';
    out+='<h3>فاکتورها و مانده‌ها</h3><div class="table-wrapper"><table class="action-table"><thead><tr><th>فاکتور</th><th>تاریخ</th><th>مبلغ</th><th>تسویه</th><th>مانده</th><th>تخصیص پرداخت جدید</th></tr></thead><tbody>';
    (s.invoices||[]).forEach(function(i){
      out+='<tr><td>'+safe(i.number)+'</td><td>'+safe(i.date)+'</td><td>'+fmt(i.total)+'</td><td>'+fmt(i.paid)+'</td><td>'
      +(i.unreconciled?'<button type="button" class="button-secondary small acctReconcile" data-id="'+i.id+'" data-total="'+safe(i.total)+'">تطبیق پرداخت تاریخی</button>':fmt(i.remaining))
      +'</td><td>'+(!i.unreconciled&&!zero(i.remaining)?'<input class="acctAlloc" type="number" min="0" step="0.01" data-id="'+i.id+'" data-max="'+safe(i.remaining)+'" value="0" style="width:150px">':'—')+'</td></tr>';
    });
    out+='</tbody></table></div>';

    out+='<h3>ثبت دریافت / پرداخت ترکیبی</h3><div id="acctPaymentRows">'
      +'<div class="acctPaymentRow form-grid">'
      +'<div class="form-row"><label>مبلغ ('+safe(window.Currency.label)+')</label><input class="acctPartAmount" type="number" min="0" step="0.01"></div>'
      +'<div class="form-row"><label>روش دریافت/پرداخت</label><select class="acctMethod"><option value="pos">کارت‌خوان</option><option value="cash">نقدی</option><option value="bank_transfer">انتقال بانکی</option></select></div>'
      +'<div class="form-row"><label>حساب مقصد/مبدأ</label><select class="acctCashAccount"></select></div>'
      +'<div class="form-row"><button class="button-secondary small acctRemovePart" type="button">حذف این روش</button></div>'
      +'</div></div><button type="button" class="button-secondary" id="acctAddPayment">+ افزودن روش پرداخت</button>'
      +'<button type="button" class="button-secondary" id="acctCreateCashAccount">+ حساب بانکی / صندوق جدید</button>'
      +'<div class="form-grid">'
      +'<div class="form-row"><label>تاریخ میلادی ثبت مالی</label><input id="acctDate" type="date"></div>'
      +'<div class="form-row"><label>شماره پیگیری (اختیاری)</label><input id="acctRef" maxlength="120"></div>'
      +'<div class="form-row"><label>توضیحات</label><input id="acctNote" maxlength="2000"></div></div>'
      +'<p id="acctAllocationTotal"></p><p class="setting-note">هر روش پرداخت یک سند مجزا دارد، ولی همه روش‌ها و تخصیص‌ها با یک عملیات اتمیک ثبت می‌شوند؛ مبلغ مازاد اعتبار طرف حساب خواهد بود.</p>'
      +'<button id="acctSubmit" type="button" class="button">ثبت دریافت/پرداخت</button>';
    out+='<h3>سوابق پرداخت‌ها</h3><div class="table-wrapper"><table class="action-table"><thead><tr><th>تاریخ</th><th>روش</th><th>کل</th><th>تخصیص</th><th>اعتبار آزاد</th><th>عملیات</th></tr></thead><tbody>';
    (s.payments||[]).forEach(function(p){
      out+='<tr><td>'+safe(p.date)+'</td><td>'+safe(p.method)+'</td><td>'+fmt(p.amount)+'</td><td>'+fmt(p.allocated)+'</td><td>'+fmt(p.unallocated)+'</td>'
        +'<td>'+(!p.is_reversal&&!p.is_reversed&&!zero(p.unallocated)&&p.direction===(kind==='customer'?'in':'out')?'<button type="button" class="button-secondary small acctUseCredit" data-id="'+p.id+'" data-available="'+safe(p.unallocated)+'">تخصیص اعتبار</button>':'')+' '+(!p.is_reversal&&!p.is_reversed?'<button type="button" class="button-secondary small acctReverse" data-id="'+p.id+'">ثبت سند اصلاحی</button>':'ابطال‌شده')+'</td></tr>';
    });
    out+='</tbody></table></div>';
    if(kind==='customer' && (s.returns||[]).length) {
      out+='<h3>اعتبارهای برگشت از فروش</h3><div class="table-wrapper"><table class="action-table"><thead><tr><th>سند برگشت</th><th>مبلغ</th><th>مصرف‌شده</th><th>اعتبار باقی‌مانده</th><th></th></tr></thead><tbody>';
      (s.returns||[]).forEach(function(q) {
        out+='<tr><td>'+safe(q.number)+'</td><td>'+fmt(q.amount)+'</td><td>'+fmt(q.allocated)+'</td><td>'+fmt(q.available)+'</td><td>'+
        (!zero(q.available)?'<button type="button" class="button-secondary small acctApplyReturn" data-id="'+q.id+'" data-available="'+safe(q.available)+'">تخصیص اعتبار به فاکتور</button>':'—')+'</td></tr>';
      });
      out+='</tbody></table></div>';
    }
    out+='<h3>دفتر گردش مالی (صورت‌حساب)</h3>'
      +'<p><a class="button-secondary" id="acctExport" href="assets/php/accounting_statement_export.php?party_kind='+encodeURIComponent(kind)+'&party_id='+Number(partyId)+'" target="_blank" rel="noopener">خروجی اکسل صورت‌حساب</a></p>'
      +'<div class="table-wrapper"><table class="action-table"><thead><tr><th>تاریخ شمسی</th><th>شرح</th><th>افزایش بدهی</th><th>کاهش بدهی</th><th>مانده پس از سند</th></tr></thead><tbody>';
    (s.movements||[]).forEach(function(m){
      out+='<tr><td>'+safe(formatLedgerDate(m.date))+'</td><td>'+safe(m.description)+'</td>'
        +'<td>'+(!zero(m.debit)?fmt(m.debit):'—')+'</td><td>'+(!zero(m.credit)?fmt(m.credit):'—')+'</td>'
        +'<td>'+(s.balance_final?fmt(m.balance):'نیازمند تطبیق')+'</td></tr>';
    });
    out+='</tbody></table></div>';
    $('#acctContent').html(out);
    // Capture unenhanced HTML before the HCG select observer decorates it.
    cleanPaymentRow=$('#acctPaymentRows .acctPaymentRow').first().prop('outerHTML');
    loadCashAccounts();updateTotals();
  }
  function loadCashAccounts(){
    $.getJSON('assets/php/accounting_ledger_action.php',{action:'accounts'}).done(function(r){cashAccounts=(r.data&&r.data.accounts)||[];
      if(!$('#acctDate').val())$('#acctDate').val((r.data&&r.data.today)||'');
      renderCashAccounts();}).fail(fail);
  }

  function renderCashAccounts(){
    $('#acctPaymentRows .acctPaymentRow').each(function(){
      var method=$('.acctMethod',this).val(),$select=$('.acctCashAccount',this);
      var old=$select.val(), options='';
      cashAccounts.forEach(function(a){
        if(a.account_type===method)options+='<option value="'+Number(a.id)+'">'+safe(a.name)+'</option>';
      });
      $select.html(options);
      if(old&&$select.find('option[value="'+old+'"]').length)$select.val(old);
    });
    updateTotals();
  }
  $(document).on('change','.acctMethod',renderCashAccounts);
  $(document).on('click','#acctCreateCashAccount',function(){
    var method=$('#acctPaymentRows .acctMethod').first().val();
    var name=prompt('نام حساب جدید:');if(name===null)return;
    $.post('assets/php/accounting_ledger_action.php',
      {action:'create_account',csrf_token:csrf,name:name,account_type:method}).done(loadCashAccounts).fail(fail);
  });
  $(document).on('click','#acctAddPayment',function(){
    if($('#acctPaymentRows .acctPaymentRow').length>=12){alert('حداکثر ۱۲ روش پرداخت مجاز است.');return;}
    var $part=$(cleanPaymentRow);
    $part.find('.acctPartAmount').val('');
    $part.find('.acctMethod').val('cash');
    $part.find('.acctCashAccount').empty();
    $('#acctPaymentRows').append($part);
    renderCashAccounts();
  });
  $(document).on('click','.acctRemovePart',function(){
    if($('#acctPaymentRows .acctPaymentRow').length<=1){alert('حداقل یک روش پرداخت لازم است.');return;}
    $(this).closest('.acctPaymentRow').remove();updateTotals();
  });
  function paymentTotal(){
    var sum=0;
    $('#acctPaymentRows .acctPartAmount').each(function(){
      var rawValue=raw($(this).val()||'0');
      if(Number(rawValue)<0)throw Error('invalid');
      sum+=Number(rawValue);
    });
    return sum;
  }
  function updateTotals(){
    var sum=0, invalid=false, total=0;
    $('#acctContent .acctAlloc').each(function(){
      try{
        var value=raw($(this).val()||'0'),maximum=Number($(this).data('max'));
        if(Number(value)<0||Number(value)>maximum)invalid=true;
        sum+=Number(value);
      }catch(e){invalid=true;}
    });
    try{
      total=paymentTotal();
      $('#acctPaymentRows .acctPaymentRow').each(function(){
        var amount=Number(raw($('.acctPartAmount',this).val()||'0'));
        if(amount<=0||!$('.acctCashAccount',this).val()) invalid=true;
      });
    }catch(e){invalid=true;}
    if(total<sum)invalid=true;
    $('#acctAllocationTotal').text('مجموع دریافتی/پرداختی: '+fmt(total.toFixed(2))+
      ' | تخصیص به فاکتورها: '+fmt(sum.toFixed(2))+
      ' | اعتبار آزاد جدید: '+(total>=sum?fmt((total-sum).toFixed(2)):'نامعتبر')+
      (invalid?' | مقادیر نامعتبر هستند.':''));
    $('#acctSubmit').prop('disabled',invalid||posting||total<=0);
  }
  $(document).on('input','#acctPaymentRows .acctPartAmount',function(){
    if(selectedInvoice&&autoAllocation){
      var $target=$('#acctContent .acctAlloc[data-id="'+selectedInvoice+'"]');
      if($target.length){
        try{
          var entered=paymentTotal();
          var available=Number($target.data('max'));
          $target.val(input(Math.min(Math.max(entered,0),available).toFixed(2)));
        }catch(e){}
      }
    }
    updateTotals();
  });
  $(document).on('input','#acctContent .acctAlloc',function(){autoAllocation=false;updateTotals();});
  $(document).on('click','#acctSubmit',function(){
    if(posting)return;
    var allocations=[],parts=[],sum=0,total=0;
    try{
      $('#acctContent .acctAlloc').each(function(){
        var v=raw($(this).val()||'0');
        if(Number(v)>0){allocations.push({invoice_id:$(this).data('id'),amount:$(this).val()});sum+=Number(v);}
      });
      $('#acctPaymentRows .acctPaymentRow').each(function(){
        var v=raw($('.acctPartAmount',this).val());
        if(Number(v)<=0)throw Error('invalid');
        var method=$('.acctMethod',this).val(),account=$('.acctCashAccount',this).val();
        if(!account)throw Error('account missing');
        parts.push({amount:$('.acctPartAmount',this).val(),method:method,cash_account_id:account});
        total+=Number(v);
      });
      if(total<=0||sum>total+0.00001)throw Error('invalid');
    }catch(e){alert('مبالغ یا حساب پرداخت نامعتبر هستند.');return;}
    posting=true;updateTotals();
    $.post('assets/php/accounting_ledger_action.php',{
      action:'payment_batch',csrf_token:csrf,party_kind:kind,party_id:partyId,
      source_proforma_id:sourceProformaId,payment_date:$('#acctDate').val(),
      reference_no:$('#acctRef').val(),note:$('#acctNote').val(),
      allocations:JSON.stringify(allocations),payments:JSON.stringify(parts),client_token:paymentToken
    }).done(function(){
      posting=false;selectedInvoice=0;sourceProformaId=0;
      paymentToken='acct_'+Date.now()+'_'+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2);
      load();if(typeof window.refreshInvoiceAccountingList==='function')window.refreshInvoiceAccountingList();
    }).fail(function(e){posting=false;updateTotals();fail(e);});
  });
  $(document).on('click','.acctReconcile',function(){
    var id=$(this).data('id'),value=prompt('مبلغ واقعی پرداخت‌شده قبلی را وارد کنید ('+window.Currency.label+'). از حدس زدن خودداری کنید.','0');
    if(value===null)return;
    var reason=prompt('مستند تعیین مبلغ پرداخت‌شده تاریخی را وارد کنید (حداقل ۵ نویسه):','بر اساس رسید پرداخت یا سوابق حسابداری');
    if(reason===null)return;
    $.post('assets/php/accounting_ledger_action.php',{action:'reconcile_legacy',csrf_token:csrf,invoice_id:id,historical_paid:value,reason:reason})
      .done(load).fail(fail);
  });
  $(document).on('click','.acctApplyReturn',function(){
    var $button=$(this),token=$button.data('requestToken');
    if(!token){token='credit_'+Date.now()+'_'+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2);$button.data('requestToken',token);}
    var returnId=Number($button.data('id')),available=String($button.data('available'));
    var targets=(statement.invoices||[]).filter(function(i){return !i.unreconciled&&!zero(i.remaining);});
    if(!targets.length){alert('فاکتور باز برای تخصیص اعتبار برگشت وجود ندارد.');return;}
    var invoiceId=prompt('شناسه فاکتور مقصد: '+targets.map(function(i){return i.id+' ('+i.number+')';}).join('،'),String(targets[0].id));
    if(invoiceId===null)return;
    if(!targets.some(function(i){return String(i.id)===invoiceId})){alert('فاکتور مقصد معتبر نیست.');return;}
    var amount=prompt('مبلغ اعتبار برگشت برای تخصیص ('+window.Currency.label+')',input(available));
    if(amount===null)return;
    $.post('assets/php/accounting_ledger_action.php',{action:'apply_return_credit',csrf_token:csrf,return_id:returnId,invoice_id:invoiceId,amount:amount,client_token:token})
      .done(load).fail(fail);
  });
  $(document).on('click','.acctUseCredit',function(){
    var $button=$(this),token=$button.data('requestToken');
    if(!token){token='credit_'+Date.now()+'_'+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2);$button.data('requestToken',token);}
    var paymentId=$button.data('id'),available=$button.data('available');
    var targets=(statement.invoices||[]).filter(function(i){return !i.unreconciled&&!zero(i.remaining);});
    if(!targets.length){alert('فاکتور تسویه‌نشده‌ای وجود ندارد.');return;}
    var invoiceId=prompt('شناسه فاکتور مقصد: '+targets.map(function(i){return i.id+' ('+i.number+')';}).join('،'),String(targets[0].id));
    if(invoiceId===null)return;
    if(!targets.some(function(i){return String(i.id)===invoiceId})){alert('فاکتور انتخاب‌شده معتبر نیست.');return;}
    var amount=prompt('مبلغ تخصیص از اعتبار آزاد ('+window.Currency.label+')',input(available));
    if(amount===null)return;
    $.post('assets/php/accounting_ledger_action.php',{action:'apply_credit',csrf_token:csrf,payment_id:paymentId,invoice_id:invoiceId,amount:amount,client_token:token})
      .done(load).fail(fail);
  });
  if(isPartyPage){
    $('.customer-row,.supplier-row').each(function(){
      var id=$(this).data('id');
      if(!id)return;
      var $actions=$(this).children('td').last();
      $('<button type="button" class="button-secondary small acctPartyOpen">صورت حساب / دریافت و پرداخت</button>').data('id',id).appendTo($actions);
    });
    $(document).on('click','.acctPartyOpen',function(){partyId=Number($(this).data('id'));selectedInvoice=0;sourceProformaId=0;autoAllocation=false;show();load();});
  }
  $(document).on('click','#acctRefund',function(){
    var amount=prompt('مبلغ استرداد از اعتبار ('+window.Currency.label+')',input(String(Math.abs(Number(statement.balance)))));
    if(amount===null)return;
    var reason=prompt('دلیل استرداد را ثبت کنید (حداقل ۵ نویسه)','استرداد اعتبار به طرف حساب');
    if(reason===null)return;
    var method=prompt('روش استرداد: cash / pos / bank_transfer','bank_transfer');
    if(method===null)return;
    $.post('assets/php/accounting_ledger_action.php',{action:'refund',csrf_token:csrf,party_kind:kind,party_id:partyId,
       amount:amount,method:method,note:reason,client_token:'refund_'+Date.now()+'_'+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)})
       .done(load).fail(fail);
  });
  $(document).on('click','.acctReverse',function(){
    var id=$(this).data('id');
    var reason=prompt('دلیل اصلاح سند شماره '+id+' (حداقل ۵ نویسه):');
    if(reason===null)return;
    if(!confirm('با این عملیات یک سند معکوس ثبت می‌شود و تخصیص‌های قبلی آزاد می‌شوند. تأیید می‌کنید؟'))return;
    $.post('assets/php/accounting_ledger_action.php',{action:'reverse',csrf_token:csrf,payment_id:id,reason:reason,
       client_token:'reverse_'+Date.now()+'_'+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)})
       .done(load).fail(fail);
  });
  $(document).on('click','#acctOpening',function(){
    var entered=prompt('مانده افتتاحیه طرف حساب را وارد کنید. مقدار مثبت: بدهی؛ مقدار منفی: بستانکاری. واحد: '+window.Currency.label,'0');
    if(entered===null)return;
    var note=prompt('توضیح و مستند مانده افتتاحیه','مانده انتقالی از قبل از استقرار سیستم');
    if(note===null)return;
    $.post('assets/php/accounting_ledger_action.php',{action:'opening_balance',csrf_token:csrf,party_kind:kind,party_id:partyId,amount:entered,note:note}).done(load).fail(fail);
  });
  window.AccountingLedger={
    openInvoice:function(i){
      if(!i || !i.id)return;
      partyId=Number(kind==='customer'?i.customer_id:i.supplier_id);
      sourceProformaId=/proforma/.test(i.type)?Number(i.id):0;selectedInvoice=sourceProformaId?0:Number(i.id);autoAllocation=selectedInvoice>0;show();load();
    }
  };
  $(document).on('click','.invoice-accounting',function(){
    var id=$(this).data('id');
    $.getJSON('assets/php/invoice_get.php',{id:id}).done(function(r){
      var inv=r.data&&r.data.invoice?r.data.invoice:r.data;
      if(inv)window.AccountingLedger.openInvoice(inv);
    }).fail(fail);
  });
});
})(jQuery);
