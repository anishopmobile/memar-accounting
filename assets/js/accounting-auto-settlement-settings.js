/* Customer-scoped automatic settlement settings. No financial writes here:
 * invoice_save.php is the server-side transaction authority.
 */
$(function () {
  'use strict';
  var panel=$('#auto-settlement');
  if (!panel.length) return;
  var csrf=String(window.APP_CSRF_TOKEN||'');
  var selectedId=null,selectedName='';
  var searchTimer=null,requestSeq=0;

  function render() {
    $('#autoSettlementSelected').text(selectedId ? selectedName+' (شناسه '+selectedId+')' : 'هیچ مشتری انتخاب نشده است.');
    $('#autoSettlementRemove').toggle(!!selectedId);
  }
  function notice(text) { $('#autoSettlementMessage').text(text||''); }
  function errorOf(xhr) { return xhr.responseJSON && xhr.responseJSON.message || 'خطا در ارتباط با سرور.'; }
  function load() {
    $.getJSON('assets/php/accounting_auto_settlement_settings.php').done(function(r) {
      if (!r.success) { notice(r.message||'خطا در دریافت تنظیمات.'); return; }
      var v=r.data||{};
      selectedId=v.customer_id ? Number(v.customer_id) : null;
      selectedName=String(v.customer_name||'');
      $('#autoSettlementEnabled').prop('checked',v.enabled===true);
      render();
    }).fail(function(xhr){notice(errorOf(xhr));});
  }
  function closeModal() {
    $('#autoSettlementCustomerModal').removeClass('open');
    if (!$('.modal.open').length) $('body').removeClass('modal-open');
  }
  function search() {
    var seq=++requestSeq;
    var q=String($('#autoSettlementSearch').val()||'');
    $('#autoSettlementCustomerRows').empty().append($('<tr>').append($('<td>',{'colspan':4}).text('در حال جستجو...')));
    $.getJSON('assets/php/invoice_parties.php',{party_type:'customer',search:q})
      .done(function(r) {
        if(seq!==requestSeq)return;
        var rows=$('#autoSettlementCustomerRows').empty();
        if(!r.success || !Array.isArray(r.data) || !r.data.length) {
          rows.append($('<tr>').append($('<td>',{'colspan':4}).text('مشتری یافت نشد.')));
          return;
        }
        r.data.forEach(function(c) {
          var id=Number(c.id);
          if(!Number.isSafeInteger(id)||id<=0)return;
          var name=String(c.name||'').trim()||('مشتری '+id);
          var tr=$('<tr>');
          tr.append($('<td>').text(name),$('<td>').text(c.phone||'—'),
            $('<td>').text(c.national_code||'—'),
            $('<td>').append($('<button>',{'type':'button','class':'button-secondary small auto-settlement-pick'})
              .attr('data-id',id).text('انتخاب')));
          tr.find('.auto-settlement-pick').data('customer-name',name);
          rows.append(tr);
        });
      }).fail(function(xhr) {
        if(seq!==requestSeq)return;
        $('#autoSettlementCustomerRows').empty().append($('<tr>')
          .append($('<td>',{'colspan':4}).text(errorOf(xhr))));
      });
  }
  $('#autoSettlementChoose').on('click',function() {
    $('#autoSettlementCustomerModal').addClass('open');
    $('body').addClass('modal-open');
    $('#autoSettlementSearch').val('');
    search();
    $('#autoSettlementSearch').trigger('focus');
  });
  $('#autoSettlementCustomerModal').on('click','[data-auto-settlement-close]',closeModal);
  $(document).on('keydown',function(e) {
    if(e.key==='Escape' && $('#autoSettlementCustomerModal').hasClass('open'))closeModal();
  });
  $('#autoSettlementCustomerRows').on('click','.auto-settlement-pick',function() {
    selectedId=Number($(this).attr('data-id'));
    selectedName=String($(this).data('customer-name')||'');
    render();notice('مشتری انتخاب شد. برای اعمال تغییرات، تنظیمات را ذخیره کنید.');
    closeModal();
  });
  $('#autoSettlementSearch').on('input',function() {
    clearTimeout(searchTimer);searchTimer=setTimeout(search,230);
  });
  $('#autoSettlementRemove').on('click',function() {
    selectedId=null;selectedName='';render();
    notice('مشتری حذف شد. برای اعمال تغییرات، تنظیمات را ذخیره کنید.');
  });
  $('#autoSettlementSave').on('click',function() {
    var enabled=$('#autoSettlementEnabled').is(':checked');
    if(enabled&&!selectedId){notice('ابتدا مشتری عمومی را انتخاب کنید.');return;}
    var btn=$(this).prop('disabled',true);
    notice('در حال ذخیره...');
    $.post('assets/php/accounting_auto_settlement_settings.php',{
      csrf_token:csrf,enabled:enabled?'1':'0',customer_id:selectedId||''
    }).done(function(r) {
      notice(r.success?'تنظیمات با موفقیت ذخیره شد.':(r.message||'ذخیره انجام نشد.'));
      if(r.success)load();
    }).fail(function(xhr){notice(errorOf(xhr));})
      .always(function(){btn.prop('disabled',false);});
  });
  load();
});
