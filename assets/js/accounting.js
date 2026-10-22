$(function(){
  var root=$('#accountingApp');if(!root.length)return;
  var kind=String(root.data('kind')), party=Number(root.data('party')), csrf=window.APP_CSRF_TOKEN||'';
  if(!party){root.text('طرف حساب مشخص نشده است.');return;}
  var currency=window.Currency, data=null;
  $('.acCurrency').text(currency.label);
  function esc(x){return $('<div>').text(x==null?'':String(x)).html();}
  function money(x){return currency.formatNumber(x==null?'0':String(x))+' '+currency.label;}
  function base(x){return currency.fromBase(x==null?'0':String(x));}
  function post(params,success){
    params.csrf_token=csrf;$.post('assets/php/accounting_action.php',params).done(function(r){
      if(!r.success){alert(r.message||'ثبت ناموفق بود');return;} alert(r.message||'ثبت شد');refresh();if(success)success();
    }).fail(function(xhr){alert(xhr.responseJSON?.message||'خطا در ثبت اطلاعات');});
  }
  function refresh(){
    $.getJSON('assets/php/accounting_statement.php',{party_kind:kind,party_id:party}).done(function(r){
      if(!r.success){$('#acBalance').text(r.message||'خطا');return;}
      data=r.data;
      var b=data.balance;
      $('#acBalance').html('<strong>مانده حساب: '+money(b.net)+'</strong><br>بدهی: '+money(b.debt)+' | اعتبار: '+money(b.credit)+' | مانده افتتاحیه: '+money(b.opening)+' | جمع فاکتورها: '+money(b.invoiced));
      var inv='',opts='';
      data.invoices.forEach(function(i){
        var link='?page='+(kind==='customer'?'factors':'purchase-invoice')+'&action=edit&id='+encodeURIComponent(i.id);
        inv+='<tr><td><a href="'+link+'">'+esc(i.invoice_number)+'</a></td><td>'+esc(i.invoice_date)+'</td><td>'+money(i.payable_amount)+'</td><td>'+money(i.remaining)+'</td><td>'+(i.needs_review?'نیازمند تطبیق':'ثبت‌شده')+'</td><td>'+(i.needs_review?'<button class="button-secondary small ac-reconcile" data-id="'+i.id+'" data-max="'+esc(base(i.payable_amount))+'">تعیین تسویه تاریخی</button>':'—')+'</td></tr>';
        if(!i.needs_review && Number(i.remaining)>0)opts+='<div class="form-row"><label>'+esc(i.invoice_number)+' | مانده '+money(i.remaining)+'</label><input type="number" min="0" step="1" class="ac-allocation" data-id="'+i.id+'" max="'+esc(base(i.remaining))+'" value="0"></div>';
      });
      $('#acInvoices').html(inv||'<tr><td colspan="6">فاکتوری ثبت نشده است.</td></tr>');
      $('#acAllocationList').html(opts||'فاکتور باز وجود ندارد.');
      var receipts='';
      data.payments.forEach(function(p){
        receipts+='<tr><td>'+esc(p.payment_date)+'</td><td>'+(p.direction==='in'?'دریافت':'پرداخت')+'</td><td>'+money(p.amount)+'</td><td>'+money(p.allocated)+'</td><td>'+money(p.available)+'</td><td>'+esc(p.method)+'</td><td>'+(Number(p.available)>0?'<button class="button-secondary small ac-apply" data-id="'+p.id+'" data-available="'+esc(base(p.available))+'">تخصیص اعتبار</button>':'—')+'</td></tr>';
      });
      $('#acReceipts').html(receipts||'<tr><td colspan="7">تراکنشی ثبت نشده است.</td></tr>');
    }).fail(function(){$('#acBalance').text('خطا در دریافت صورت‌حساب');});
  }
  $('#acDirection').on('change',function(){
    var allocAllowed=kind==='customer'?this.value==='in':this.value==='out';
    $('#acAllocationList').toggle(allocAllowed);
  });
  $('#acSubmit').on('click',function(){
    var amount=$('#acAmount').val(), allocations=[],total=0;
    $('.ac-allocation').each(function(){var v=Number($(this).val()||0);if(v>0){allocations.push({invoice_id:$(this).data('id'),amount:String($(this).val())});total+=v;}});
    if(!amount||Number(amount)<=0||total>Number(amount)){alert('مبلغ یا جمع تخصیص نامعتبر است.');return;}
    var button=$(this).prop('disabled',true);
    var params={action:'receipt',party_kind:kind,party_id:party,direction:$('#acDirection').val(),
      method:$('#acMethod').val(),amount:amount,payment_date:$('#acDate').val(),
      account_label:$('#acAccount').val(),reference_number:$('#acReference').val(),
      note:$('#acNote').val(),request_key:'accounting-'+Date.now()+'-'+Math.random().toString(36).slice(2,14),allocations:JSON.stringify(allocations)};
    params.csrf_token=csrf;
    $.post('assets/php/accounting_action.php',params).done(function(r){if(r.success){$('#acAmount,#acReference').val('');refresh();}else alert(r.message||'خطا');})
      .fail(function(xhr){alert(xhr.responseJSON?.message||'خطا در ثبت تراکنش');}).always(function(){button.prop('disabled',false);});
  });
  $('#acOpeningSubmit').on('click',function(){
    if(!confirm('مانده افتتاحیه فقط یک‌بار ثبت می‌شود. مطمئن هستید؟'))return;
    post({action:'opening',party_kind:kind,party_id:party,amount:$('#acOpening').val(),note:$('#acOpeningNote').val()});
  });
  $(document).on('click','.ac-reconcile',function(){
    var id=$(this).data('id'),maximum=$(this).data('max');
    var amount=prompt('مبلغ واقعی پرداخت‌شده تا پیش از راه‌اندازی دفتر مالی را وارد کنید (حداکثر '+maximum+' '+currency.label+'):', '0');
    if(amount===null)return;
    post({action:'legacy_reconcile',invoice_id:id,paid_amount:amount});
  });
  $(document).on('click','.ac-apply',function(){
    var receiptId=$(this).data('id'),available=Number($(this).data('available'));
    var invoiceId=prompt('شناسه فاکتوری که اعتبار به آن اختصاص می‌یابد را وارد کنید:');
    if(!invoiceId)return;
    var amount=prompt('مبلغ تخصیص ('+currency.label+')، سقف اعتبار آزاد: '+available, String(available));
    if(amount===null)return;
    post({action:'allocate',receipt_id:receiptId,invoice_id:invoiceId,amount:amount});
  });
  refresh();
});