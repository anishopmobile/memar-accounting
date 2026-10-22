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
    $unreconciled = $inv['legacy_paid_amount'] === null;
    $stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM account_payment_allocations WHERE invoice_id=?');
    $stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result();
    $paid=money_add($inv['legacy_paid_amount'] ?? '0',($r?$r->fetch_assoc()['s']:'0'));
    $stmt->close();
    return ['invoice'=>$inv,'paid'=>$paid,'remaining'=>money_sub($inv['payable_amount'],$paid),'unreconciled'=>$unreconciled];
}
function acct_update_status(mysqli $db,int $id): void {
    $v=acct_invoice_outstanding($db,$id,true);
    if ($v['unreconciled']) throw new InvalidArgumentException('ابتدا وضعیت پرداخت تاریخی فاکتور را تطبیق دهید.');
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
            if ($v['unreconciled']) throw new InvalidArgumentException('ابتدا مبلغ پرداخت تاریخی این فاکتور را مشخص کنید.');
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
    return ['party_kind'=>$kind,'party_id'=>$partyId,'opening_balance'=>$opening,'has_opening_balance'=>$row!==null,'invoices'=>$invoices,'payments'=>$payments,'unallocated_credit'=>$unallocated,'return_credit'=>$returnCredits,'balance'=>$balance,'unreconciled_invoices'=>$uncertain,'balance_final'=>$uncertain===0];
}

/** Apply existing unallocated credit to one final invoice. */
function acct_apply_credit(mysqli $db, int $paymentId, int $invoiceId, $rawAmount): array {
    $amount=acct_decimal($rawAmount,true);
    $db->begin_transaction();
    try {
        $stmt=$db->prepare('SELECT party_kind,party_id,amount FROM account_payments WHERE id=?');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$r=$stmt->get_result();$p=$r?$r->fetch_assoc():null;$stmt->close();
        if (!$p) throw new InvalidArgumentException('پرداخت یافت نشد.');
        acct_party_lock($db,$p['party_kind'],(int)$p['party_id']);
        $stmt=$db->prepare('SELECT * FROM account_payments WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$p=$stmt->get_result()->fetch_assoc();$stmt->close();
        $v=acct_invoice_outstanding($db,$invoiceId,true);
        if ($v['unreconciled']) throw new InvalidArgumentException('فاکتور تاریخی باید ابتدا تطبیق داده شود.');
        $inv=$v['invoice'];$kind=$p['party_kind'];
        if (($kind==='customer' && ($inv['type']!=='sales_invoice'||(int)$inv['customer_id']!==(int)$p['party_id']))
            || ($kind==='supplier' && ($inv['type']!=='purchase_invoice'||(int)$inv['supplier_id']!==(int)$p['party_id'])))
            throw new InvalidArgumentException('طرف حساب فاکتور و پرداخت متفاوت است.');
        $stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM account_payment_allocations WHERE payment_id=?');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$used=$stmt->get_result()->fetch_assoc()['s'];$stmt->close();
        if (money_cmp($amount,money_sub($p['amount'],$used))>0 || money_cmp($amount,$v['remaining'])>0)
            throw new InvalidArgumentException('مبلغ از اعتبار آزاد یا مانده فاکتور بیشتر است.');
        $stmt=$db->prepare('INSERT INTO account_payment_allocations(payment_id,invoice_id,amount) VALUES(?,?,?) ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)');
        $stmt->bind_param('iis',$paymentId,$invoiceId,$amount);$stmt->execute();$stmt->close();
        acct_update_status($db,$invoiceId);
        $db->commit();
        return ['invoice_id'=>$invoiceId,'payment_id'=>$paymentId];
    } catch (Throwable $e) {$db->rollback();throw $e;}
}

/**
 * Efficient one-query balances for listings and exports, avoiding N+1 invoice
 * reads and row multiplication between payments and invoices.
 * An unknown legacy partial settlement is explicitly flagged, not guessed.
 */
function acct_party_balances(mysqli $db,string $kind): array {
    acct_party_kind($kind);
    $party=$kind==='customer'?'customers':'suppliers';
    $col=$kind==='customer'?'customer_id':'supplier_id';
    $typ=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $ret=$kind==='customer'
      ? "LEFT JOIN (SELECT customer_id AS party_id,SUM(payable_amount) total FROM sales_returns GROUP BY customer_id) rr ON rr.party_id=p.id"
      : '';
    $returnExpr=$kind==='customer'?"COALESCE(rr.total,0)":'0';
    $sql="SELECT p.id AS party_id,
        COALESCE(iv.gross,0) AS gross,
        COALESCE(iv.unknown_count,0) AS unknown_count,
        (COALESCE(iv.gross,0)-COALESCE(iv.legacy,0)-COALESCE(al.settled,0)
          +COALESCE(ob.amount,0)-COALESCE(py.free,0)-{$returnExpr}) AS balance
        FROM {$party} p
        LEFT JOIN (
          SELECT {$col} party_id,SUM(payable_amount) gross,
                 SUM(COALESCE(legacy_paid_amount,0)) legacy,
                 SUM(legacy_paid_amount IS NULL) unknown_count
          FROM invoices WHERE type='{$typ}' GROUP BY {$col}
        ) iv ON iv.party_id=p.id
        LEFT JOIN (
          SELECT i.{$col} party_id,SUM(a.amount) settled
          FROM account_payment_allocations a
          JOIN invoices i ON i.id=a.invoice_id
          WHERE i.type='{$typ}' GROUP BY i.{$col}
        ) al ON al.party_id=p.id
        LEFT JOIN (
          SELECT x.party_id,SUM(x.amount-COALESCE(y.used,0)) free
          FROM account_payments x
          LEFT JOIN (SELECT payment_id,SUM(amount) used FROM account_payment_allocations GROUP BY payment_id) y ON y.payment_id=x.id
          WHERE x.party_kind='{$kind}'
          GROUP BY x.party_id
        ) py ON py.party_id=p.id
        LEFT JOIN account_opening_balances ob ON ob.party_kind='{$kind}' AND ob.party_id=p.id
        {$ret}";
    $res=$db->query($sql);
    $map=[];
    while ($res && ($row=$res->fetch_assoc())) {
        $map[(int)$row['party_id']]=[
            'balance'=>$row['balance'],
            'gross'=>$row['gross'],
            'unreconciled'=>(int)$row['unknown_count']>0
        ];
    }
    if($res) $res->free();
    return $map;
}
