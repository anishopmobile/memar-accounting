<?php
/** Accounting settlement engine. All monetary amounts are database TOMAN decimals. */
declare(strict_types=1);
require_once __DIR__ . '/invoice_common.php';

function acct_decimal($raw, bool $positive = false): string {
    $s = trim((string)$raw);
    if (!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/D', $s)) {
        throw new InvalidArgumentException('مبلغ نامعتبر است.');
    }
    $n = money_round($s);
    if ($positive ? money_cmp($n, '0') <= 0 : money_cmp($n, '0') < 0) {
        throw new InvalidArgumentException('مبلغ باید مثبت باشد.');
    }
    return $n;
}
function acct_party_kind(string $kind): string {
    if (!in_array($kind, ['customer','supplier'], true)) throw new InvalidArgumentException('نوع طرف حساب نامعتبر است.');
    return $kind;
}
function acct_party_lock(mysqli $db, string $kind, int $id): void {
    $table = $kind === 'customer' ? 'customers' : 'suppliers';
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE id=? FOR UPDATE");
    $stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result(); $stmt->close();
    if (!$r || !$r->fetch_assoc()) throw new InvalidArgumentException('طرف حساب یافت نشد.');
}
function acct_invoice_outstanding(mysqli $db, int $id, bool $lock = false): array {
    $stmt=$db->prepare('SELECT * FROM invoices WHERE id=?'.($lock?' FOR UPDATE':''));
    $stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result();
    $inv=$r?$r->fetch_assoc():null;$stmt->close();
    if (!$inv || !in_array($inv['type'],['sales_invoice','purchase_invoice'],true))
        throw new InvalidArgumentException('فقط فاکتور قطعی قابل تسویه است.');
    if ($inv['legacy_paid_amount'] === null)
        throw new InvalidArgumentException('مبلغ پرداختی تاریخی این فاکتور جزئی هنوز تعیین تکلیف نشده است.');
    $stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM account_payment_allocations WHERE invoice_id=?');
    $stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result();
    $paid=money_add($inv['legacy_paid_amount'],($r?$r->fetch_assoc()['s']:'0'));
    $stmt->close();
    return ['invoice'=>$inv,'paid'=>$paid,'remaining'=>money_sub($inv['payable_amount'],$paid)];
}
function acct_update_status(mysqli $db,int $id): void {
    $v=acct_invoice_outstanding($db,$id,true);
    $status=money_cmp($v['remaining'],'0') <= 0?'paid':(money_cmp($v['paid'],'0')>0?'partial':'unpaid');
    $stmt=$db->prepare('UPDATE invoices SET payment_status=? WHERE id=?');
    $stmt->bind_param('si',$status,$id);$stmt->execute();$stmt->close();
}
/** One payment may settle multiple invoices; any unallocated amount is a party credit. */
function acct_record_payment(mysqli $db,array $input): array {
    $kind=acct_party_kind((string)($input['party_kind']??''));
    $id=(int)($input['party_id']??0);
    $expectedDirection=$kind==='customer'?'in':'out';
    $direction=(string)($input['direction']??$expectedDirection);
    if ($direction!==$expectedDirection) throw new InvalidArgumentException('جهت پرداخت برای تسویه نامعتبر است.');
    $amount=acct_decimal($input['amount']??'',true);
    $method=(string)($input['method']??'');
    if (!in_array($method,['cash','pos','bank_transfer'],true)) throw new InvalidArgumentException('روش پرداخت نامعتبر است.');
    $date=(string)($input['payment_date']??date('Y-m-d'));
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$dt || $dt->format('Y-m-d')!==$date) throw new InvalidArgumentException('تاریخ پرداخت نامعتبر است.');
    $token=(string)($input['client_token']??'');
    if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$token)) throw new InvalidArgumentException('شناسه یکتای پرداخت نامعتبر است.');
    $ref=trim((string)($input['reference_no']??''));
    $note=trim((string)($input['note']??''));
    if (mb_strlen($ref)>120 || mb_strlen($note)>2000) throw new InvalidArgumentException('توضیحات پرداخت بیش از حد مجاز است.');
    $items=$input['allocations']??[];
    if (!is_array($items) || count($items)>100) throw new InvalidArgumentException('فهرست تخصیص نامعتبر است.');
    $alloc=[];$sum='0';
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['invoice_id'])) throw new InvalidArgumentException('تخصیص نامعتبر است.');
        $invoiceId=filter_var($item['invoice_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$invoiceId || isset($alloc[$invoiceId])) throw new InvalidArgumentException('شناسه تکراری یا نامعتبر فاکتور.');
        $value=acct_decimal($item['amount']??'',true);
        $alloc[$invoiceId]=$value;
        $sum=money_add($sum,$value);
    }
    if (money_cmp($sum,$amount)>0) throw new InvalidArgumentException('مجموع تخصیص‌ها از مبلغ پرداخت بیشتر است.');
    $db->begin_transaction();
    try {
        acct_party_lock($db,$kind,$id); // consistent lock order: party then invoice.
        $stmt=$db->prepare('SELECT id FROM account_payments WHERE client_token=?');
        $stmt->bind_param('s',$token);$stmt->execute();$res=$stmt->get_result();$existing=$res?$res->fetch_assoc():null;$stmt->close();
        if ($existing) { $db->commit();return ['payment_id'=>(int)$existing['id'],'duplicate'=>true]; }
        ksort($alloc,SORT_NUMERIC);
        foreach ($alloc as $invoiceId=>$value) {
            $v=acct_invoice_outstanding($db,(int)$invoiceId,true);
            $inv=$v['invoice'];
            if (($kind==='customer' && ($inv['type']!=='sales_invoice' || (int)$inv['customer_id']!==$id))
                || ($kind==='supplier' && ($inv['type']!=='purchase_invoice' || (int)$inv['supplier_id']!==$id)))
                throw new InvalidArgumentException('فاکتور متعلق به این طرف حساب نیست.');
            if (money_cmp($value,$v['remaining'])>0) throw new InvalidArgumentException('تخصیص از مانده فاکتور بیشتر است.');
        }
        $stmt=$db->prepare('INSERT INTO account_payments(party_kind,party_id,direction,amount,method,payment_date,reference_no,note,client_token) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('sisssssss',$kind,$id,$direction,$amount,$method,$date,$ref,$note,$token);
        $stmt->execute();$paymentId=(int)$stmt->insert_id;$stmt->close();
        foreach ($alloc as $invoiceId=>$value) {
            $stmt=$db->prepare('INSERT INTO account_payment_allocations(payment_id,invoice_id,amount) VALUES(?,?,?)');
            $stmt->bind_param('iis',$paymentId,$invoiceId,$value);$stmt->execute();$stmt->close();
            acct_update_status($db,(int)$invoiceId);
        }
        $db->commit();
        return ['payment_id'=>$paymentId,'duplicate'=>false,'credit'=>money_sub($amount,$sum)];
    } catch (Throwable $e) { $db->rollback();throw $e; }
}
/** Signed party balance: positive means customer owes us / we owe supplier. */
function acct_party_statement(mysqli $db,string $kind,int $partyId): array {
    acct_party_kind($kind);
    $table=$kind==='customer'?'customers':'suppliers';
    $stmt=$db->prepare("SELECT id FROM {$table} WHERE id=?");
    $stmt->bind_param('i',$partyId);$stmt->execute();$ok=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$ok) throw new InvalidArgumentException('طرف حساب یافت نشد.');
    $col=$kind==='customer'?'customer_id':'supplier_id';
    $typ=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $stmt=$db->prepare("SELECT id,invoice_number,invoice_date,payable_amount,legacy_paid_amount FROM invoices WHERE {$col}=? AND type=? ORDER BY invoice_date,id");
    $stmt->bind_param('is',$partyId,$typ);$stmt->execute();$res=$stmt->get_result();
    $invoices=[];$total='0';$uncertain=0;
    while ($row=$res->fetch_assoc()) {
        $v=acct_invoice_outstanding($db,(int)$row['id']);
        $invoices[]=['id'=>(int)$row['id'],'number'=>$row['invoice_number'],'date'=>$row['invoice_date'],'total'=>$row['payable_amount'],'paid'=>$v['paid'],'remaining'=>$v['remaining'],'unreconciled'=>$row['legacy_paid_amount']===null];
        if ($row['legacy_paid_amount']===null) $uncertain++;
        else $total=money_add($total,$v['remaining']);
    }
    $stmt->close();
    $stmt=$db->prepare('SELECT * FROM account_payments WHERE party_kind=? AND party_id=? ORDER BY payment_date,id');
    $stmt->bind_param('si',$kind,$partyId);$stmt->execute();$res=$stmt->get_result();$payments=[];$unallocated='0';
    while ($p=$res->fetch_assoc()) {
        $stmt2=$db->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM account_payment_allocations WHERE payment_id=?');
        $pid=(int)$p['id'];$stmt2->bind_param('i',$pid);$stmt2->execute();$q=$stmt2->get_result()->fetch_assoc();$stmt2->close();
        $left=money_sub($p['amount'],$q['s']);
        $unallocated=money_add($unallocated,$left);
        $payments[]=['id'=>$pid,'date'=>$p['payment_date'],'amount'=>$p['amount'],'method'=>$p['method'],'allocated'=>$q['s'],'unallocated'=>$left,'reference_no'=>$p['reference_no'],'note'=>$p['note']];
    }
    $stmt->close();
    $stmt=$db->prepare('SELECT amount FROM account_opening_balances WHERE party_kind=? AND party_id=?');
    $stmt->bind_param('si',$kind,$partyId);$stmt->execute();$res=$stmt->get_result();$row=$res?$res->fetch_assoc():null;$stmt->close();
    $opening=$row['amount']??'0';
    $returnCredits='0';
    if ($kind==='customer') {
        $stmt=$db->prepare('SELECT COALESCE(SUM(payable_amount),0) AS s FROM sales_returns WHERE customer_id=?');
        $stmt->bind_param('i',$partyId);$stmt->execute();$returnCredits=$stmt->get_result()->fetch_assoc()['s'];$stmt->close();
    }
    $balance=money_sub(money_add($total,$opening),money_add($unallocated,$returnCredits));
    return ['party_kind'=>$kind,'party_id'=>$partyId,'opening_balance'=>$opening,'invoices'=>$invoices,'payments'=>$payments,'unallocated_credit'=>$unallocated,'return_credit'=>$returnCredits,'balance'=>$balance,'unreconciled_invoices'=>$uncertain,'balance_final'=>$uncertain===0];
}
