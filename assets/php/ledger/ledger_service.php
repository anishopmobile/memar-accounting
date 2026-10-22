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
    $s=$db->prepare('SELECT COALESCE((SELECT SUM(a.amount) FROM ledger_allocations a JOIN ledger_payments p ON p.id=a.payment_id WHERE a.invoice_id=? AND p.voided_at IS NULL),0) + COALESCE((SELECT SUM(a.amount) FROM ledger_credit_allocations a JOIN ledger_payments p ON p.id=a.payment_id WHERE a.invoice_id=? AND p.voided_at IS NULL),0) AS allocated');
    $s->bind_param('ii',$invoiceId,$invoiceId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    $s=$db->prepare('SELECT recognized_paid,classification FROM ledger_legacy_invoices WHERE invoice_id=?');
    $s->bind_param('i',$invoiceId);$s->execute();$legacy=$s->get_result()->fetch_assoc();$s->close();
    $base=$legacy ? $legacy['recognized_paid'] : '0';
    $returned='0';
    if($inv['type']==='sales_invoice') {
        $s=$db->prepare('SELECT COALESCE(SUM(payable_amount),0) AS returned FROM sales_returns WHERE invoice_id=?');
        $s->bind_param('i',$invoiceId);$s->execute();$returned=$s->get_result()->fetch_assoc()['returned'];$s->close();
    }
    $paid=money_add($base,$row['allocated']);
    $effectiveTotal=money_sub($inv['payable_amount'],$returned);
    $due=money_sub($effectiveTotal,$paid);
    return ['invoice'=>$inv,'party_kind'=>$kind,'party_id'=>$pid,'paid'=>$paid,'due'=>$due,
        'total'=>money_round($inv['payable_amount']),'returned'=>money_round($returned),'effective_total'=>$effectiveTotal,'legacy_classification'=>$legacy['classification']??null,
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
    $s=$db->prepare('SELECT COALESCE(SUM(CASE WHEN direction=? THEN amount ELSE -amount END),0) v FROM ledger_payments WHERE party_kind=? AND party_id=? AND voided_at IS NULL');
    $dir=$kind==='customer'?'receipt':'disbursement';$s->bind_param('ssi',$dir,$kind,$id);
    $s->execute();$payments=$s->get_result()->fetch_assoc()['v'];$s->close();
    $s=$db->prepare('SELECT signed_amount FROM ledger_openings WHERE party_kind=? AND party_id=?');
    $s->bind_param('si',$kind,$id);$s->execute();$opening=$s->get_result()->fetch_assoc()['signed_amount']??'0';$s->close();
    $returned='0';
    if($kind==='customer') {
        $s=$db->prepare('SELECT COALESCE(SUM(payable_amount),0) AS returned FROM sales_returns WHERE customer_id=?');
        $s->bind_param('i',$id);$s->execute();$returned=$s->get_result()->fetch_assoc()['returned'];$s->close();
    }
    $balance=money_sub(money_add($invoices,$opening),money_add(money_add($legacy,$payments),$returned));
    return ['total_invoices'=>money_round($invoices),'sales_returns'=>money_round($returned),'paid_historically'=>money_round($legacy),
        'payments'=>money_round($payments),'opening'=>money_round($opening),
        'balance'=>$balance,'debt'=>money_cmp($balance,'0')>0?$balance:'0.00',
        'credit'=>money_cmp($balance,'0')<0?money_abs($balance):'0.00'];
}
/**
 * Allocate exactly the requested amounts to matching final invoices.
 * No automatic allocation; explicit allocations are persisted and independently auditable.
 */
function ledger_record_payment(mysqli $db,array $in,bool $insideExistingTransaction=false): array {
    if(!$insideExistingTransaction) ledger_initialize_legacy($db);
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
    ksort($mapped,SORT_NUMERIC);
    $expectedDirection=$kind==='customer'?'receipt':'disbursement';
    $direction=(string)($in['direction']??$expectedDirection);
    if(!in_array($direction,['receipt','disbursement'],true))throw new InvalidArgumentException('جهت تراکنش معتبر نیست.');
    $note=trim((string)($in['note']??''));$note=substr($note,0,3000);
    if($direction!==$expectedDirection && ($mapped || strlen($note)<8))
        throw new InvalidArgumentException('استرداد وجه باید بدون تخصیص به فاکتور و با توضیح مستند ثبت شود.');
    $ref=trim((string)($in['reference_no']??''));$ref=substr($ref,0,120);
    $requestHash=hash('sha256',json_encode([$kind,$pid,$direction,$amount,$method,$date,$ref,$note,$mapped],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $table=$kind==='customer'?'customers':'suppliers';
    if(!$insideExistingTransaction) $db->begin_transaction();
    try{
        $s=$db->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE");$s->bind_param('i',$pid);$s->execute();$found=$s->get_result()->fetch_assoc();$s->close();
        if(!$found)throw new DomainException('طرف حساب وجود ندارد.');
        $s=$db->prepare('SELECT id,payload_hash FROM ledger_payments WHERE request_token=?');
        $s->bind_param('s',$token);$s->execute();$old=$s->get_result()->fetch_assoc();$s->close();
        if($old) {
            if(!hash_equals((string)$old['payload_hash'],$requestHash))throw new DomainException('شناسه درخواست قبلاً برای پرداخت دیگری استفاده شده است.');
            if(!$insideExistingTransaction) $db->commit();return ['payment_id'=>(int)$old['id'],'duplicate'=>true];
        }
        if($direction!==$expectedDirection){
            $credit=ledger_party_summary($db,$kind,(int)$pid)['credit'];
            if(money_cmp($amount,$credit)>0)throw new DomainException('مبلغ استرداد از بستانکاری واقعی طرف حساب بیشتر است.');
        }
        foreach($mapped as $invId=>$value){
            $bal=ledger_invoice_balance($db,(int)$invId,true);
            if($bal['party_kind']!==$kind||$bal['party_id']!==(int)$pid)throw new DomainException('فاکتور متعلق به این طرف حساب نیست.');
            if($bal['legacy_classification']==='requires_reconciliation')throw new DomainException('ابتدا پرداخت جزئی تاریخی فاکتور تعیین تکلیف شود.');
            if(money_cmp($value,$bal['due'])>0)throw new DomainException('مبلغ تخصیص از مانده فاکتور بیشتر است.');
        }
        $s=$db->prepare('INSERT INTO ledger_payments(party_kind,party_id,direction,amount,method,paid_on,reference_no,note,request_token,payload_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $s->bind_param('sissssssss',$kind,$pid,$direction,$amount,$method,$date,$ref,$note,$token,$requestHash);$s->execute();
        $paymentId=(int)$db->insert_id;$s->close();
        foreach($mapped as $invId=>$value){
            $s=$db->prepare('INSERT INTO ledger_allocations(payment_id,invoice_id,amount) VALUES(?,?,?)');
            $s->bind_param('iis',$paymentId,$invId,$value);$s->execute();$s->close();
        }
        // Compatibility cache: legacy screens still display invoices.payment_status.
        // The ledger calculation remains authoritative.
        foreach($mapped as $invId=>$value){
            $balance=ledger_invoice_balance($db,(int)$invId,true);
            $derived=$balance['status'];
            $s=$db->prepare('UPDATE invoices SET payment_status=? WHERE id=?');
            $s->bind_param('si',$derived,$invId);$s->execute();$s->close();
        }
        if(!$insideExistingTransaction) $db->commit();return ['payment_id'=>$paymentId,'duplicate'=>false,'unallocated'=>$direction===$expectedDirection?money_sub($amount,$sum):'0.00'];
    } catch(Throwable $e){if(!$insideExistingTransaction)$db->rollback();throw $e;}
}

/**
 * One-time historic paid-amount correction; no cash movement is fabricated.
 * Restricts adjustment to legacy partial invoices and preserves an audit reason.
 */
function ledger_reconcile_partial(mysqli $db,int $invoiceId,$paidRaw,string $explanation): array {
    ledger_initialize_legacy($db);
    if($invoiceId<1)throw new InvalidArgumentException('شناسه نامعتبر است.');
    $paid=ledger_decimal($paidRaw);
    $explanation=trim($explanation);
    if(strlen($explanation)<8 || strlen($explanation)>2000)throw new InvalidArgumentException('توضیح مستند اصلاح الزامی است.');
    $db->begin_transaction();
    try{
        $s=$db->prepare('SELECT type,payable_amount FROM invoices WHERE id=? FOR UPDATE');
        $s->bind_param('i',$invoiceId);$s->execute();$invoice=$s->get_result()->fetch_assoc();$s->close();
        if(!$invoice || !in_array($invoice['type'],['sales_invoice','purchase_invoice'],true))
            throw new DomainException('فاکتور قطعی یافت نشد.');
        if(money_cmp($paid,$invoice['payable_amount'])>0)throw new DomainException('مبلغ پرداختی تاریخی بیشتر از مبلغ فاکتور است.');
        $s=$db->prepare("UPDATE ledger_legacy_invoices SET recognized_paid=?,classification='historical_paid',reconciled_at=NOW() WHERE invoice_id=? AND classification='requires_reconciliation'");
        $s->bind_param('si',$paid,$invoiceId);$s->execute();$changed=$s->affected_rows;$s->close();
        if($changed!==1)throw new DomainException('این فاکتور نیازمند اصلاح نیست یا قبلاً اصلاح شده است.');
        $s=$db->prepare('INSERT INTO ledger_reconciliations(invoice_id,recognized_paid,explanation) VALUES(?,?,?)');
        $s->bind_param('iss',$invoiceId,$paid,$explanation);$s->execute();$s->close();
        $db->commit();
        return ['invoice_id'=>$invoiceId,'recognized_paid'=>$paid];
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/** Party detail for payment allocation UI. Includes no gateway transactions. */
function ledger_party_details(mysqli $db,string $kind,int $id): array {
    ledger_initialize_legacy($db);
    $summary=ledger_party_summary($db,$kind,$id);
    $partyTable=$kind==='customer'?'customers':'suppliers';
    $nameExpr=$kind==='customer' ? "CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))" : "COALESCE(NULLIF(company_name,''),CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))";
    $pstmt=$db->prepare("SELECT $nameExpr AS name,phone FROM $partyTable WHERE id=?");
    $pstmt->bind_param('i',$id);$pstmt->execute();$party=$pstmt->get_result()->fetch_assoc();$pstmt->close();
    if(!$party)throw new DomainException('طرف حساب یافت نشد.');
    $fk=$kind==='customer'?'customer_id':'supplier_id';
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $s=$db->prepare("SELECT id,invoice_number,invoice_date,payable_amount FROM invoices WHERE type=? AND $fk=? ORDER BY invoice_date DESC,id DESC LIMIT 150");
    $s->bind_param('si',$type,$id);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $invoices=[];
    foreach($rows as $row) {
        $v=ledger_invoice_balance($db,(int)$row['id']);
        $invoices[]=['id'=>(int)$row['id'],'invoice_number'=>$row['invoice_number'],'invoice_date'=>$row['invoice_date'],
           'total'=>$v['total'],'returned'=>$v['returned'],'effective_total'=>$v['effective_total'],'paid'=>$v['paid'],'due'=>$v['due'],'status'=>$v['status'],
           'legacy_classification'=>$v['legacy_classification']];
    }
    $s=$db->prepare('SELECT p.id,p.direction,p.amount,p.method,p.paid_on,p.reference_no,p.note,p.voided_at, CASE WHEN p.direction=? THEN p.amount-COALESCE((SELECT SUM(a.amount) FROM ledger_allocations a WHERE a.payment_id=p.id),0)-COALESCE((SELECT SUM(a.amount) FROM ledger_credit_allocations a WHERE a.payment_id=p.id),0) ELSE 0 END AS unapplied FROM ledger_payments p WHERE p.party_kind=? AND p.party_id=? ORDER BY p.id DESC LIMIT 150');
    $expected=$kind==='customer'?'receipt':'disbursement';
    $s->bind_param('ssi',$expected,$kind,$id);$s->execute();$payments=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    $statement=ledger_party_statement($db,$kind,$id,$summary['balance']);
    return ['party'=>$party,'summary'=>$summary,'invoices'=>$invoices,'payments'=>$payments,'statement'=>$statement];
}

/**
 * Consume an earlier unapplied receipt/disbursement without moving money again.
 * Immutable allocation row + unique token; never creates fake receipts.
 */
function ledger_apply_credit(mysqli $db,string $kind,int $partyId,int $paymentId,int $invoiceId,$amountRaw,string $token): array {
    ledger_initialize_legacy($db);
    if(!in_array($kind,['customer','supplier'],true)||$partyId<1||$paymentId<1||$invoiceId<1
      ||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$token))throw new InvalidArgumentException('درخواست تخصیص اعتبار نامعتبر است.');
    $amount=ledger_decimal($amountRaw);
    if(money_cmp($amount,'0')<=0)throw new InvalidArgumentException('مبلغ باید مثبت باشد.');
    $db->begin_transaction();
    try{
        $partyTable=$kind==='customer'?'customers':'suppliers';
        $s=$db->prepare("SELECT id FROM $partyTable WHERE id=? FOR UPDATE");$s->bind_param('i',$partyId);$s->execute();$party=$s->get_result()->fetch_assoc();$s->close();
        if(!$party)throw new DomainException('طرف حساب یافت نشد.');
        $s=$db->prepare('SELECT payment_id,invoice_id,amount FROM ledger_credit_allocations WHERE request_token=?');
        $s->bind_param('s',$token);$s->execute();$old=$s->get_result()->fetch_assoc();$s->close();
        if($old){
            if((int)$old['payment_id']!==$paymentId||(int)$old['invoice_id']!==$invoiceId||money_cmp($old['amount'],$amount)!==0)
                throw new DomainException('شناسه تخصیص با درخواست قبلی مغایرت دارد.');
            $db->commit();return ['duplicate'=>true];
        }
        $s=$db->prepare('SELECT party_kind,party_id,direction,amount,voided_at FROM ledger_payments WHERE id=? FOR UPDATE');
        $s->bind_param('i',$paymentId);$s->execute();$payment=$s->get_result()->fetch_assoc();$s->close();
        if(!$payment||$payment['voided_at']!==null||$payment['direction']!==($kind==='customer'?'receipt':'disbursement')||$payment['party_kind']!==$kind||(int)$payment['party_id']!==$partyId)
            throw new DomainException('تراکنش متعلق به طرف حساب نیست یا باطل شده است.');
        $s=$db->prepare('SELECT COALESCE((SELECT SUM(amount) FROM ledger_allocations WHERE payment_id=?),0)+COALESCE((SELECT SUM(amount) FROM ledger_credit_allocations WHERE payment_id=?),0) allocated');
        $s->bind_param('ii',$paymentId,$paymentId);$s->execute();$used=$s->get_result()->fetch_assoc()['allocated'];$s->close();
        if(money_cmp($amount,money_sub($payment['amount'],$used))>0)throw new DomainException('اعتبار پرداخت کافی نیست.');
        $balance=ledger_invoice_balance($db,$invoiceId,true);
        if($balance['party_kind']!==$kind||$balance['party_id']!==$partyId)
            throw new DomainException('فاکتور متعلق به این طرف حساب نیست.');
        if($balance['legacy_classification']==='requires_reconciliation')
            throw new DomainException('مبلغ پرداخت جزئی تاریخی ابتدا باید تعیین شود.');
        if(money_cmp($amount,$balance['due'])>0)throw new DomainException('تخصیص بیشتر از مانده فاکتور است.');
        $s=$db->prepare('INSERT INTO ledger_credit_allocations(payment_id,invoice_id,amount,request_token) VALUES(?,?,?,?)');
        $s->bind_param('iiss',$paymentId,$invoiceId,$amount,$token);$s->execute();$s->close();
        $balance=ledger_invoice_balance($db,$invoiceId,true);
        $s=$db->prepare('UPDATE invoices SET payment_status=? WHERE id=?');
        $s->bind_param('si',$balance['status'],$invoiceId);$s->execute();$s->close();
        $db->commit();return ['duplicate'=>false,'invoice_status'=>$balance['status'],'invoice_due'=>$balance['due']];
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/** Opening balance: one immutable snapshot per party, signed decimal. */
function ledger_record_opening(mysqli $db,string $kind,int $partyId,string $signedAmount,string $effectiveOn,string $note): array {
    ledger_initialize_legacy($db);
    if(!in_array($kind,['customer','supplier'],true)||$partyId<1||!preg_match('/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D',$signedAmount)
        ||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$effectiveOn)
        ||!checkdate((int)substr($effectiveOn,5,2),(int)substr($effectiveOn,8,2),(int)substr($effectiveOn,0,4)))
        throw new InvalidArgumentException('مانده افتتاحیه یا تاریخ نامعتبر است.');
    $note=trim($note);if(strlen($note)<8||strlen($note)>3000)throw new InvalidArgumentException('دلیل افتتاح حساب باید ثبت شود.');
    $table=$kind==='customer'?'customers':'suppliers';
    $db->begin_transaction();
    try {
        $s=$db->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE");$s->bind_param('i',$partyId);$s->execute();$party=$s->get_result()->fetch_assoc();$s->close();
        if(!$party)throw new DomainException('طرف حساب یافت نشد.');
        $s=$db->prepare('SELECT id FROM ledger_openings WHERE party_kind=? AND party_id=?');
        $s->bind_param('si',$kind,$partyId);$s->execute();$existing=$s->get_result()->fetch_assoc();$s->close();
        if($existing)throw new DomainException('مانده افتتاحیه قبلاً ثبت شده و قابل بازنویسی نیست.');
        $s=$db->prepare('INSERT INTO ledger_openings(party_kind,party_id,signed_amount,effective_on,note) VALUES(?,?,?,?,?)');
        $s->bind_param('sisss',$kind,$partyId,$signedAmount,$effectiveOn,$note);$s->execute();$id=(int)$db->insert_id;$s->close();
        $db->commit();return ['opening_id'=>$id];
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/** Derived status cache for legacy invoice lists. Must be called within caller's DB transaction if one is open. */
function ledger_sync_invoice_status(mysqli $db,int $invoiceId): array {
    $balance=ledger_invoice_balance($db,$invoiceId,true);
    $s=$db->prepare('UPDATE invoices SET payment_status=? WHERE id=?');
    $s->bind_param('si',$balance['status'],$invoiceId);$s->execute();$s->close();
    return $balance;
}

/** True if invoice has actual allocations or non-zero/uncertain legacy financial settlement. */
function ledger_invoice_has_financial_events(mysqli $db,int $invoiceId): bool {
    $s=$db->prepare("SELECT
       EXISTS(SELECT 1 FROM ledger_allocations WHERE invoice_id=?) a,
       EXISTS(SELECT 1 FROM ledger_credit_allocations WHERE invoice_id=?) c,
       EXISTS(SELECT 1 FROM ledger_legacy_invoices WHERE invoice_id=?) l,
       EXISTS(SELECT 1 FROM ledger_proforma_deposits WHERE proforma_invoice_id=?) d");
    $s->bind_param('iiii',$invoiceId,$invoiceId,$invoiceId,$invoiceId);$s->execute();
    $r=$s->get_result()->fetch_assoc();$s->close();
    return (int)$r['a']===1||(int)$r['c']===1||(int)$r['l']===1||(int)$r['d']===1;
}

/**
 * Promote a proforma deposit to final invoice settlement, once, within
 * convertInvoice's existing transaction. Never create a new cash movement.
 */
function ledger_apply_proforma_deposits(mysqli $db,int $invoiceId): void {
    $balance=ledger_invoice_balance($db,$invoiceId,true);
    if($balance['legacy_classification']==='requires_reconciliation')
        throw new DomainException('مغایرت تاریخی حل نشده است.');
    $s=$db->prepare('SELECT p.id,p.amount,p.voided_at FROM ledger_payments p JOIN ledger_proforma_deposits d ON d.payment_id=p.id WHERE d.proforma_invoice_id=? ORDER BY p.id FOR UPDATE');
    $s->bind_param('i',$invoiceId);$s->execute();$payments=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    foreach($payments as $p) {
        if($p['voided_at']!==null)continue;
        $balance=ledger_invoice_balance($db,$invoiceId,true);
        if(money_cmp($balance['due'],'0')<=0)break;
        $pid=(int)$p['id'];
        $s=$db->prepare('SELECT COALESCE((SELECT SUM(amount) FROM ledger_allocations WHERE payment_id=?),0)+COALESCE((SELECT SUM(amount) FROM ledger_credit_allocations WHERE payment_id=?),0) used');
        $s->bind_param('ii',$pid,$pid);$s->execute();$used=$s->get_result()->fetch_assoc()['used'];$s->close();
        $available=money_sub($p['amount'],$used);
        if(money_cmp($available,'0')<=0)continue;
        $allocate=money_cmp($available,$balance['due'])<=0?$available:$balance['due'];
        $token='proforma_'.$invoiceId.'_'.$pid;
        $s=$db->prepare('INSERT INTO ledger_credit_allocations(payment_id,invoice_id,amount,request_token) VALUES(?,?,?,?)');
        $s->bind_param('iiss',$pid,$invoiceId,$allocate,$token);$s->execute();$s->close();
    }
    ledger_sync_invoice_status($db,$invoiceId);
}

/**
 * Chronological statement with running balance. If history exceeds 500 events,
 * use the final true balance as an anchor and clearly flag the abbreviated view.
 */
function ledger_party_statement(mysqli $db,string $kind,int $id,?string $endingBalance=null): array {
    if(!in_array($kind,['customer','supplier'],true)||$id<1)throw new InvalidArgumentException('طرف حساب نامعتبر است.');
    $fk=$kind==='customer'?'customer_id':'supplier_id';
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $direction=$kind==='customer'?'receipt':'disbursement';
    $id=(int)$id;
    $events=[
        "SELECT i.invoice_date occurred_on, i.id record_id,10 event_rank,'invoice' event_type,
                CONCAT('فاکتور ',i.invoice_number) description,i.payable_amount delta
          FROM invoices i WHERE i.type='$type' AND i.$fk=$id",
        "SELECT p.paid_on occurred_on,p.id record_id,30 event_rank,'payment' event_type,
                CONCAT(IF(p.direction='$direction','دریافت / پرداخت شماره ','استرداد وجه شماره '),p.id,' - ',p.method) description,
                IF(p.direction='$direction',-p.amount,p.amount) delta
          FROM ledger_payments p WHERE p.party_kind='$kind' AND p.direction='$direction' AND p.party_id=$id AND p.voided_at IS NULL",
        "SELECT o.effective_on occurred_on,o.id record_id,0 event_rank,'opening' event_type,
                'مانده افتتاحیه (غیرنقدی)' description,o.signed_amount delta
          FROM ledger_openings o WHERE o.party_kind='$kind' AND o.party_id=$id",
        "SELECT i.invoice_date occurred_on,l.invoice_id record_id,20 event_rank,'historical' event_type,
                CONCAT('تسویه تاریخی سند ',i.invoice_number,' (تاریخ واقعی دریافت/پرداخت نامشخص)') description,-l.recognized_paid delta
          FROM ledger_legacy_invoices l JOIN invoices i ON i.id=l.invoice_id
          WHERE i.type='$type' AND i.$fk=$id AND l.recognized_paid > 0"
    ];
    if($kind==='customer') $events[]="SELECT r.return_date occurred_on,r.id record_id,25 event_rank,'sales_return' event_type,
                CONCAT('برگشت فروش ',r.return_number) description,-r.payable_amount delta
          FROM sales_returns r WHERE r.customer_id=$id";
    $sql='SELECT * FROM ('.implode(' UNION ALL ',$events).') ledger_events ORDER BY occurred_on DESC,event_rank DESC,record_id DESC LIMIT 501';
    $q=$db->query($sql);if(!$q)throw new RuntimeException('Failed to load account statement.');
    $rows=$q->fetch_all(MYSQLI_ASSOC);$q->free();
    $truncated=count($rows)>500;
    if($truncated)$rows=array_slice($rows,0,500);
    $ending=$endingBalance ?? ledger_party_summary($db,$kind,$id)['balance'];
    $balance=$ending;
    foreach($rows as &$row){
        $row['running_balance']=$balance;
        $balance=money_sub($balance,$row['delta']);
    }
    unset($row);
    return ['entries'=>array_reverse($rows),'truncated'=>$truncated,'balance_prior_to_first_event'=>$balance];
}

/**
 * Void a bookkeeping error without deleting the audit trail. Real refunds
 * require separate reverse-direction payments and must not use this operation.
 */
function ledger_void_payment(mysqli $db,string $kind,int $partyId,int $paymentId,string $reason): array {
    ledger_initialize_legacy($db);
    if(!in_array($kind,['customer','supplier'],true)||$partyId<1||$paymentId<1)
        throw new InvalidArgumentException('شناسه تراکنش نامعتبر است.');
    $reason=trim($reason);
    if(strlen($reason)<12||strlen($reason)>2000)throw new InvalidArgumentException('دلیل اصلاح سند حداقل ۱۲ کاراکتر است.');
    $db->begin_transaction();
    try {
        $table=$kind==='customer'?'customers':'suppliers';
        $s=$db->prepare("SELECT id FROM $table WHERE id=? FOR UPDATE");$s->bind_param('i',$partyId);$s->execute();$party=$s->get_result()->fetch_assoc();$s->close();
        if(!$party)throw new DomainException('طرف حساب یافت نشد.');
        $s=$db->prepare('SELECT party_kind,party_id,direction,amount,voided_at FROM ledger_payments WHERE id=? FOR UPDATE');
        $s->bind_param('i',$paymentId);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
        if(!$p||$p['party_kind']!==$kind||(int)$p['party_id']!==$partyId)
            throw new DomainException('این تراکنش متعلق به طرف حساب نیست.');
        if($p['voided_at']!==null)throw new DomainException('تراکنش قبلاً ابطال شده است.');
        // Any later credit applications must be dealt with before the source receipt is voided.
        $s=$db->prepare('SELECT COUNT(*) n FROM ledger_credit_allocations WHERE payment_id=?');
        $s->bind_param('i',$paymentId);$s->execute();$used=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
        if($used>0)throw new DomainException('تراکنش دارای تخصیص اعتبار ثانویه است و ابطال مستقیم مجاز نیست.');
        $s=$db->prepare('SELECT invoice_id FROM ledger_allocations WHERE payment_id=? ORDER BY invoice_id');
        $s->bind_param('i',$paymentId);$s->execute();$ids=array_map('intval',array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'invoice_id'));$s->close();
        // Reverse-direction cash movements may already have consumed this credit; disallow
        // creating a positive debt by voiding an older credit.
        $expected=$kind==='customer'?'receipt':'disbursement';
        if($p['direction']===$expected) {
            $account=ledger_party_summary($db,$kind,$partyId);
            if(money_cmp($account['balance'],'0')<0 &&
               money_cmp($account['credit'],$p['amount'])<0)
                throw new DomainException('این مبلغ ممکن است قبلاً مسترد شده باشد؛ نیازمند سند اصلاحی جداگانه است.');
        }
        $s=$db->prepare('UPDATE ledger_payments SET voided_at=NOW(),void_reason=? WHERE id=? AND voided_at IS NULL');
        $s->bind_param('si',$reason,$paymentId);$s->execute();$affected=$s->affected_rows;$s->close();
        if($affected!==1)throw new DomainException('ابطال تراکنش همزمان انجام شده است.');
        foreach($ids as $id)ledger_sync_invoice_status($db,$id);
        $db->commit();
        return ['voided_payment_id'=>$paymentId,'affected_invoices'=>$ids];
    } catch(Throwable $e){$db->rollback();throw $e;}
}
