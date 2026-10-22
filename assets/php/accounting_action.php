<?php
/** Accounting actions: manual receipts, allocations, opening balances and legacy reconciliation. */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/accounting_ledger.php';
require_csrf_or_fail();
header('Content-Type: application/json; charset=utf-8');
try {
    $action=(string)($_POST['action']??'');
    if ($action==='receipt') {
        $allocations=json_decode((string)($_POST['allocations']??'[]'),true);
        if(!is_array($allocations))throw new InvalidArgumentException('تخصیص‌ها نامعتبرند.');
        $record=[
          'party_kind'=>$_POST['party_kind']??'', 'party_id'=>$_POST['party_id']??0,
          'direction'=>$_POST['direction']??'',
          'amount'=>parseMoneyInput((string)($_POST['amount']??'')),
          'method'=>$_POST['method']??'',
          'payment_date'=>$_POST['payment_date']??date('Y-m-d'),
          'account_label'=>$_POST['account_label']??'',
          'reference_number'=>$_POST['reference_number']??'',
          'note'=>$_POST['note']??'',
          'request_key'=>$_POST['request_key']??'',
          'source_proforma_id'=>$_POST['source_proforma_id']??0
        ];
        foreach ($allocations as &$allocation) {
            if (!is_array($allocation)) throw new InvalidArgumentException('تخصیص نامعتبر است.');
            $allocation['amount']=parseMoneyInput((string)($allocation['amount']??''));
        }unset($allocation);
        $record['allocations']=$allocations;
        $r=ledger_post($mysqli,$record);
        respond_json(true,'دریافت/پرداخت ثبت شد.',$r);
    }
    if ($action==='allocate') {
        $value=parseMoneyInput((string)($_POST['amount']??''));
        ledger_allocate_existing($mysqli,(int)($_POST['receipt_id']??0),(int)($_POST['invoice_id']??0),$value);
        respond_json(true,'اعتبار به فاکتور تخصیص یافت.');
    }
    if ($action==='opening') {
        $amount=parseMoneyInput((string)($_POST['amount']??'0'));
        ledger_set_opening($mysqli,(string)($_POST['party_kind']??''),(int)($_POST['party_id']??0),$amount,(string)($_POST['note']??''));
        respond_json(true,'مانده افتتاحیه ثبت شد.');
    }
    if ($action==='legacy_reconcile') {
        $invoiceId=(int)($_POST['invoice_id']??0);
        $paid=parseMoneyInput((string)($_POST['paid_amount']??''));
        $mysqli->begin_transaction();
        try {
            $s=ledger_invoice_balance($mysqli,$invoiceId,true);
            if(!$s['needs_review'])throw new InvalidArgumentException('این فاکتور نیاز به تعیین مانده تاریخی ندارد.');
            $value=ledger_money($paid,true);
            if(money_cmp($value,$s['invoice']['payable_amount'])>0)throw new InvalidArgumentException('مبلغ از کل فاکتور بیشتر است.');
            $st=$mysqli->prepare('UPDATE accounting_legacy_settlements SET amount=?, needs_review=0, resolved_at=NOW() WHERE invoice_id=? AND needs_review=1');
            $st->bind_param('si',$value,$invoiceId);$st->execute();$st->close();
            ledger_update_invoice_status($mysqli,$invoiceId);
            $mysqli->commit();
            respond_json(true,'مانده تاریخی تعیین شد.');
        }catch(Throwable $e){$mysqli->rollback();throw $e;}
    }
    throw new InvalidArgumentException('عملیات نامعتبر است.');
} catch (InvalidArgumentException $e) {
    respond_error($e->getMessage(),422);
} catch (Throwable $e) {
    error_log('Accounting action: '.$e->getMessage());
    respond_error('خطا در ثبت عملیات حسابداری.',500);
}
