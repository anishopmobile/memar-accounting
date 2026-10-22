<?php
/** Reconciliation and allocation core. Only final invoices create A/R or A/P. */
declare(strict_types=1);
require_once __DIR__.'/ledger_migration.php';
require_once dirname(__DIR__).'/invoice_common.php';

function ledger_decimal($raw): string {
    $raw = trim((string)$raw);
    if (!preg_match('/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $raw)) {
        throw new InvalidArgumentException('مبلغ نامعتبر است.');
    }
    return money_round($raw);
}
function ledger_party_type(array $inv): array {
    $sales = $inv['type'] === 'sales_invoice';
    if (!$sales && $inv['type'] !== 'purchase_invoice') {
        throw new DomainException('پیش‌فاکتور بدهی قطعی ایجاد نمی‌کند.');
    }
    return [$sales ? 'customer':'supplier', (int)($sales?$inv['customer_id']:$inv['supplier_id']), $sales?'receipt':'disbursement'];
}
function ledger_invoice_balance(mysqli $db, int $invoiceId, bool $lock=false): array {
    $sql='SELECT * FROM invoices WHERE id=?'.($lock?' FOR UPDATE':'');
    $s=$db->prepare($sql); $s->bind_param('i',$invoiceId);$s->execute();
    $inv=$s->get_result()->fetch_assoc();$s->close();
    if (!$inv) throw new DomainException('فاکتور یافت نشد.');
    [$kind,$pid] = ledger_party_type($inv);
    $s=$db->prepare('SELECT COALESCE(SUM(a.amount),0) AS allocated FROM ledger_allocations a JOIN ledger_payments p ON p.id=a.payment_id WHERE a.invoice_id=? AND p.voided_at IS NULL');
    $s->bind_param('i',$invoiceId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    $s=$db->prepare('SELECT recognized_paid,classification FROM ledger_legacy_invoices WHERE invoice_id=?');
    $s->bind_param('i',$invoiceId);$s->execute();$legacy=$s->get_result()->fetch_assoc();$s->close();
    $base=$legacy ? $legacy['recognized_paid'] : '0';
    $paid=money_add($base,$row['allocated']);
    $due=money_sub($inv['payable_amount'],$paid);
    return ['invoice'=>$inv,'party_kind'=>$kind,'party_id'=>$pid,'paid'=>$paid,'due'=>$due,
        'total'=>money_round($inv['payable_amount']),'legacy_classification'=>$legacy['classification']??null,
        'status'=>money_cmp($due,'0')<=0?'paid':(money_cmp($paid,'0')<=0?'unpaid':'partial')];
}
/** Non-voided unapplied cash is a credit, NOT revenue and NOT a negative invoice. */
function ledger_party_summary(mysqli $db,string $kind,int $id): array {
    if (!in_array($kind,['customer','supplier'],true)||$id<1) throw new InvalidArgumentException('طرف حساب نامعتبر است.');
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $s=$db->prepare("SELECT COALESCE(SUM(payable_amount),0) v FROM invoices WHERE type=? AND ".($kind==='customer'?'customer_id':'supplier_id')."=?");
    $s->bind_param('si',$type,$id);$s->execute();$invoices=$s->get_result()->fetch_assoc()['v'];$s->close();
    $s=$db->prepare('SELECT COALESCE(SUM(recognized_paid),0) v FROM ledger_legacy_invoices l JOIN invoices i ON i.id=l.invoice_id WHERE i.type=? AND '.($kind==='customer'?'i.customer_id':'i.supplier_id').'=?');
    $s->bind_param('si',$type,$id);$s->execute();$legacy=$s->get_result()->fetch_assoc()['v'];$s->close();
    $s=$db->prepare('SELECT COALESCE(SUM(amount),0) v FROM ledger_payments WHERE party_kind=? AND party_id=? AND voided_at IS NULL AND direction=?');
    $dir=$kind==='customer'?'receipt':'disbursement';$s->bind_param('sis',$kind,$id,$dir);
    $s->execute();$payments=$s->get_result()->fetch_assoc()['v'];$s->close();
    $s=$db->prepare('SELECT signed_amount FROM ledger_openings WHERE party_kind=? AND party_id=?');
    $s->bind_param('si',$kind,$id);$s->execute();$opening=$s->get_result()->fetch_assoc()['signed_amount']??'0';$s->close();
    $balance=money_sub(money_add($invoices,$opening),money_add($legacy,$payments));
    return ['total_invoices'=>money_round($invoices),'paid_historically'=>money_round($legacy),
        'payments'=>money_round($payments),'opening'=>money_round($opening),
        'balance'=>$balance,'debt'=>money_cmp($balance,'0')>0?$balance:'0.00',
        'credit'=>money_cmp($balance,'0')<0?money_abs($balance):'0.00'];
}
/**
 * Allocate exactly the requested amounts to matching final invoices.
 * No automatic allocation; explicit allocations are persisted and independently auditable.
 */
function ledger_record_payment(mysqli $db,array $in): array {
    ledger_initialize_legacy($db);
    $kind=(string)($in['party_kind']??'');
    $pid=filter_var($in['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $method=(string)($in['method']??'');
    $date=(string)($in['paid_on']??'');
    $token=(string)($in['request_token']??'');
    if (!in_array($kind,['customer','supplier'],true)||!$pid||!in_array($method,['cash','pos','bank_transfer'],true)
      ||strlen($token)<16||strlen($token)>64||!preg_match('/^[a-zA-Z0-9_-]+$/D',$token)
      ||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
        throw new InvalidArgumentException('مشخصات پرداخت نامعتبر است.');
    }
    $amount=ledger_decimal($in['amount']??'');
    if(money_cmp($amount,'0')<=0) throw new InvalidArgumentException('مبلغ باید مثبت باشد.');
    $alloc=$in['allocations']??[];
    if(!is_array($alloc)||count($alloc)>100) throw new InvalidArgumentException('تخصیص نامعتبر است.');
    $mapped=[];$sum='0';
    foreach($alloc as $a) {
        if(!is_array($a))throw new InvalidArgumentException('تخصیص نامعتبر است.');
        $id=filter_var($a['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id||isset($mapped[$id]))throw new InvalidArgumentException('فاکتور تکراری یا نامعتبر است.');
        $v=ledger_decimal($a['amount']??'');
        if(money_cmp($v,'0')<=0)throw new InvalidArgumentException('مبلغ تخصیص باید مثبت باشد.');
        $mapped[$id]=$v;$sum=money_add($sum,$v);
    }
    if(money_cmp($sum,$amount)>0)throw new InvalidArgumentException('تخصیص بیشتر از مبلغ پرداخت است.');
    $table=$kind==='customer'?'customers':'suppliers';
    $db->begin_transaction();
    try{
        $s=$db->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE");$s->bind_param('i',$pid);$s->execute();$found=$s->get_result()->fetch_assoc();$s->close();
        if(!$found)throw new DomainException('طرف حساب وجود ندارد.');
        $s=$db->prepare('SELECT id,party_kind,party_id,direction,amount FROM ledger_payments WHERE request_token=?');
        $s->bind_param('s',$token);$s->execute();$old=$s->get_result()->fetch_assoc();$s->close();
        $direction=$kind==='customer'?'receipt':'disbursement';
        if($old) {
            if($old['party_kind']!==$kind||(int)$old['party_id']!==$pid||$old['direction']!==$direction||money_cmp($old['amount'],$amount)!==0)throw new DomainException('شناسه درخواست قبلاً برای پرداخت دیگری استفاده شده است.');
            $db->commit();return ['payment_id'=>(int)$old['id'],'duplicate'=>true];
        }
        ksort($mapped,SORT_NUMERIC);
        foreach($mapped as $invId=>$value){
            $bal=ledger_invoice_balance($db,(int)$invId,true);
            if($bal['party_kind']!==$kind||$bal['party_id']!==(int)$pid)throw new DomainException('فاکتور متعلق به این طرف حساب نیست.');
            if($bal['legacy_classification']==='requires_reconciliation')throw new DomainException('ابتدا پرداخت جزئی تاریخی فاکتور تعیین تکلیف شود.');
            if(money_cmp($value,$bal['due'])>0)throw new DomainException('مبلغ تخصیص از مانده فاکتور بیشتر است.');
        }
        $note=trim((string)($in['note']??''));$note=substr($note,0,3000);
        $ref=trim((string)($in['reference_no']??''));$ref=substr($ref,0,120);
        $s=$db->prepare('INSERT INTO ledger_payments(party_kind,party_id,direction,amount,method,paid_on,reference_no,note,request_token) VALUES(?,?,?,?,?,?,?,?,?)');
        $s->bind_param('sisssssss',$kind,$pid,$direction,$amount,$method,$date,$ref,$note,$token);$s->execute();
        $paymentId=(int)$db->insert_id;$s->close();
        foreach($mapped as $invId=>$value){
            $s=$db->prepare('INSERT INTO ledger_allocations(payment_id,invoice_id,amount) VALUES(?,?,?)');
            $s->bind_param('iis',$paymentId,$invId,$value);$s->execute();$s->close();
        }
        $db->commit();return ['payment_id'=>$paymentId,'duplicate'=>false,'unallocated'=>money_sub($amount,$sum)];
    } catch(Throwable $e){$db->rollback();throw $e;}
}
