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
function acct_resolve_cash_account(mysqli $db, string $method, $provided = null): int {
    $id=0;
    if($provided!==null && $provided!=='' && $provided!==0 && $provided!=='0') {
        $id=filter_var($provided,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new InvalidArgumentException('شناسه حساب بانکی / صندوق نامعتبر است.');
    }
    if ($id>0) {
        $stmt=$db->prepare('SELECT id FROM accounting_cash_accounts WHERE id=? AND account_type=? AND is_active=1');
        $stmt->bind_param('is',$id,$method);
    } else {
        $stmt=$db->prepare('SELECT id FROM accounting_cash_accounts WHERE account_type=? AND is_active=1 ORDER BY id LIMIT 1');
        $stmt->bind_param('s',$method);
    }
    $stmt->execute();$res=$stmt->get_result();$row=$res?$res->fetch_assoc():null;$stmt->close();
    if (!$row) throw new InvalidArgumentException('حساب فعال مطابق روش پرداخت یافت نشد.');
    return (int)$row['id'];
}
function acct_invoice_outstanding(mysqli $db, int $id, bool $lock = false): array {
    $stmt=$db->prepare('SELECT * FROM invoices WHERE id=?'.($lock?' FOR UPDATE':''));
    $stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result();
    $inv=$r?$r->fetch_assoc():null;$stmt->close();
    if (!$inv || !in_array($inv['type'],['sales_invoice','purchase_invoice'],true))
        throw new InvalidArgumentException('فقط فاکتور قطعی قابل تسویه است.');
    $unreconciled = $inv['legacy_paid_amount'] === null;
    $stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM account_payment_allocations WHERE reversed_at IS NULL AND invoice_id=?');
    $stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result();
    $paid=money_add($inv['legacy_paid_amount'] ?? '0',($r?$r->fetch_assoc()['s']:'0'));
    $stmt->close();
    if ($inv['type']==='sales_invoice') {
        $stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM account_return_allocations WHERE invoice_id=?');
        $stmt->bind_param('i',$id);$stmt->execute();$res=$stmt->get_result();
        $paid=money_add($paid,$res?$res->fetch_assoc()['s']:'0');$stmt->close();
    }
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
function acct_record_payment(mysqli $db,array $input,bool $insideTransaction=false): array {
    $kind=acct_party_kind((string)($input['party_kind']??''));
    $id=filter_var($input['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)throw new InvalidArgumentException('شناسه طرف حساب نامعتبر است.');
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
    if (!$insideTransaction) $db->begin_transaction();
    try {
        $cashAccountId=acct_resolve_cash_account($db,$method,$input['cash_account_id']??null);
        acct_party_lock($db,$kind,$id); // consistent lock order: party then invoice.
        $sourceValue=$input['source_proforma_id']??0;
        $sourceId=($sourceValue===0 || $sourceValue==='0' || $sourceValue===null || $sourceValue==='') ? 0
            : filter_var($sourceValue,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($sourceId===false)throw new InvalidArgumentException('شناسه پیش‌فاکتور نامعتبر است.');
        ksort($alloc,SORT_NUMERIC);
        $requestHash=hash('sha256',json_encode([$kind,$id,$direction,$amount,$method,$date,$ref,$note,$cashAccountId,$sourceId,$alloc],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $stmt=$db->prepare('SELECT id,request_hash FROM account_payments WHERE client_token=?');
        $stmt->bind_param('s',$token);$stmt->execute();$res=$stmt->get_result();$existing=$res?$res->fetch_assoc():null;$stmt->close();
        if ($existing) {
            if (!hash_equals((string)($existing['request_hash']??''),$requestHash))
                throw new InvalidArgumentException('شناسه تکراری برای پرداخت متفاوت استفاده شده است.');
            if (!$insideTransaction) $db->commit();return ['payment_id'=>(int)$existing['id'],'duplicate'=>true];
        }
        if ($sourceId>0) {
            $stmt=$db->prepare('SELECT type,customer_id,supplier_id FROM invoices WHERE id=? FOR UPDATE');
            $stmt->bind_param('i',$sourceId);$stmt->execute();$res=$stmt->get_result();$source=$res?$res->fetch_assoc():null;$stmt->close();
            if (!$source || ($kind==='customer' && ($source['type']!=='sales_proforma'||(int)$source['customer_id']!==$id))
                || ($kind==='supplier' && ($source['type']!=='purchase_proforma'||(int)$source['supplier_id']!==$id)))
                throw new InvalidArgumentException('پیش‌فاکتور مبنای پیش‌پرداخت نامعتبر است.');
        }
        foreach ($alloc as $invoiceId=>$value) {
            $v=acct_invoice_outstanding($db,(int)$invoiceId,true);
            if ($v['unreconciled']) throw new InvalidArgumentException('ابتدا مبلغ پرداخت تاریخی این فاکتور را مشخص کنید.');
            $inv=$v['invoice'];
            if (($kind==='customer' && ($inv['type']!=='sales_invoice' || (int)$inv['customer_id']!==$id))
                || ($kind==='supplier' && ($inv['type']!=='purchase_invoice' || (int)$inv['supplier_id']!==$id)))
                throw new InvalidArgumentException('فاکتور متعلق به این طرف حساب نیست.');
            if (money_cmp($value,$v['remaining'])>0) throw new InvalidArgumentException('تخصیص از مانده فاکتور بیشتر است.');
        }
        $batchToken=$input['batch_token']??null;
        $stmt=$db->prepare('INSERT INTO account_payments(party_kind,party_id,direction,amount,method,payment_date,reference_no,note,client_token,cash_account_id,source_proforma_id,request_hash,batch_token,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $sourceDbId = $sourceId ?: null;
        $createdBy=function_exists('auth_user_id')?auth_user_id():null;
        $stmt->bind_param('sisssssssiissi',$kind,$id,$direction,$amount,$method,$date,$ref,$note,$token,$cashAccountId,$sourceDbId,$requestHash,$batchToken,$createdBy);
        $stmt->execute();$paymentId=(int)$stmt->insert_id;$stmt->close();
        foreach ($alloc as $invoiceId=>$value) {
            $stmt=$db->prepare('INSERT INTO account_payment_allocations(payment_id,invoice_id,amount) VALUES(?,?,?)');
            $stmt->bind_param('iis',$paymentId,$invoiceId,$value);$stmt->execute();$stmt->close();
            acct_update_status($db,(int)$invoiceId);
        }
        if (!$insideTransaction) $db->commit();
        return ['payment_id'=>$paymentId,'duplicate'=>false,'credit'=>money_sub($amount,$sum)];
    } catch (Throwable $e) { if (!$insideTransaction) $db->rollback(); throw $e; }
}
/**
 * Reservation of a refundable credit source, excluding refunded cash whose
 * refund has itself been reversed. A refund must reserve actual backing credit
 * before cash leaves the shop; accounting balance already includes its cash
 * movement so these reservations are for capacity only (no second debit).
 */
function acct_refund_reserved(mysqli $db,string $kind,int $partyId,string $sourceKind,int $sourceId): string {
    $stmt=$db->prepare("SELECT COALESCE(SUM(rs.amount),0) amount
        FROM account_refund_sources rs
        JOIN account_payments refund ON refund.id=rs.refund_payment_id
        WHERE rs.source_kind=? AND rs.source_id=? AND refund.party_kind=? AND refund.party_id=?
          AND NOT EXISTS(SELECT 1 FROM account_payments rev WHERE rev.reversed_payment_id=refund.id)");
    $stmt->bind_param('sisi',$sourceKind,$sourceId,$kind,$partyId);
    $stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    return $row['amount']??'0';
}
/**
 * Older refunds recorded without source reservations must not silently free
 * their original money for reuse. Reversal of the legacy refund is safe;
 * otherwise operator-assisted reconciliation is required before allocation.
 */
function acct_has_unmapped_refunds(mysqli $db,string $kind,int $partyId): bool {
    $opposite=$kind==='customer'?'out':'in';
    $stmt=$db->prepare("SELECT p.id FROM account_payments p
        WHERE p.party_kind=? AND p.party_id=? AND p.direction=?
          AND p.reversed_payment_id IS NULL
          AND NOT EXISTS(SELECT 1 FROM account_refund_sources rs WHERE rs.refund_payment_id=p.id)
          AND NOT EXISTS(SELECT 1 FROM account_payments rev WHERE rev.reversed_payment_id=p.id)
        LIMIT 1");
    $stmt->bind_param('sis',$kind,$partyId,$opposite);
    $stmt->execute();$unmapped=$stmt->get_result()->fetch_assoc();$stmt->close();
    return (bool)$unmapped;
}
function acct_assert_refunds_allocatable(mysqli $db,string $kind,int $partyId): void {
    if(acct_has_unmapped_refunds($db,$kind,$partyId))
        throw new InvalidArgumentException('استرداد تاریخی بدون تعیین منبع اعتبار وجود دارد؛ پیش از تخصیص مجدد یا استرداد تازه، این سند باید بررسی یا اصلاح شود.');
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
    $returnAllocated=$kind==='customer'
      ? '(SELECT COALESCE(SUM(r.amount),0) FROM account_return_allocations r WHERE r.invoice_id=i.id)'
      : '0';
    $stmt=$db->prepare("SELECT i.id,i.invoice_number,i.invoice_date,i.payable_amount,i.legacy_paid_amount,
        (SELECT COALESCE(SUM(a.amount),0) FROM account_payment_allocations a
         WHERE a.invoice_id=i.id AND a.reversed_at IS NULL) AS payment_applied,
        {$returnAllocated} AS return_applied
        FROM invoices i WHERE i.{$col}=? AND i.type=? ORDER BY i.invoice_date,i.id");
    $stmt->bind_param('is',$partyId,$typ);$stmt->execute();$res=$stmt->get_result();
    $invoices=[];$total='0';$uncertain=0;
    while ($row=$res->fetch_assoc()) {
        $paid=money_add(money_add($row['legacy_paid_amount']??'0',$row['payment_applied']),$row['return_applied']);
        $remaining=money_sub($row['payable_amount'],$paid);
        $invoices[]=['id'=>(int)$row['id'],'number'=>$row['invoice_number'],'date'=>$row['invoice_date'],'total'=>$row['payable_amount'],'historical_paid'=>$row['legacy_paid_amount'],'paid'=>$paid,'remaining'=>$remaining,'unreconciled'=>$row['legacy_paid_amount']===null];
        if ($row['legacy_paid_amount']===null) $uncertain++;
        else $total=money_add($total,$remaining);
    }
    $stmt->close();
    $stmt=$db->prepare("SELECT p.*,ca.name AS cash_account_name,
        TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS operator_name,
        EXISTS(SELECT 1 FROM account_payments rev WHERE rev.reversed_payment_id=p.id) AS is_reversed,
        (SELECT COALESCE(SUM(a.amount),0) FROM account_payment_allocations a
         WHERE a.payment_id=p.id AND a.reversed_at IS NULL) AS used_allocation,
        (SELECT COALESCE(SUM(rs.amount),0) FROM account_refund_sources rs
         JOIN account_payments refund ON refund.id=rs.refund_payment_id
         WHERE rs.source_kind='payment' AND rs.source_id=p.id
           AND NOT EXISTS(SELECT 1 FROM account_payments rev WHERE rev.reversed_payment_id=refund.id)) AS refund_reserved
        FROM account_payments p
        LEFT JOIN accounting_cash_accounts ca ON ca.id=p.cash_account_id
        LEFT JOIN users u ON u.id=p.created_by
        WHERE p.party_kind=? AND p.party_id=? ORDER BY p.payment_date,p.id");
    $stmt->bind_param('si',$kind,$partyId);$stmt->execute();$res=$stmt->get_result();$payments=[];$unallocated='0';$availablePaymentCredit='0';
    while ($p=$res->fetch_assoc()) {
        $pid=(int)$p['id'];
        $applied=$p['used_allocation'];
        $left=money_sub($p['amount'],$applied);
        $preferredDirection=$kind==='customer'?'in':'out';
        // Financial balance uses cash movements exactly once. A source
        // reservation only limits reuse; it is NOT another cash outflow.
        $unallocated=$p['direction']===$preferredDirection ? money_add($unallocated,$left) : money_sub($unallocated,$left);
        $allocatable='0';
        if($p['direction']===$preferredDirection && $p['reversed_payment_id']===null && !(bool)$p['is_reversed']) {
            $allocatable=money_sub($left,$p['refund_reserved']);
            if(money_cmp($allocatable,'0')<0)throw new LogicException('Refund reservations exceed unallocated receipt.');
            $availablePaymentCredit=money_add($availablePaymentCredit,$allocatable);
        }
        $payments[]=['id'=>$pid,'date'=>$p['payment_date'],'amount'=>$p['amount'],'direction'=>$p['direction'],'method'=>$p['method'],'cash_account_name'=>$p['cash_account_name'],'operator_name'=>$p['operator_name'],'allocated'=>$applied,
        'refunded'=>$p['refund_reserved'],'unallocated'=>$allocatable,'reference_no'=>$p['reference_no'],'note'=>$p['note'],
        'source_proforma_id'=>$p['source_proforma_id'],'is_reversed'=>(bool)$p['is_reversed'],'is_reversal'=>$p['reversed_payment_id']!==null];
    }
    $stmt->close();
    $stmt=$db->prepare('SELECT amount FROM account_opening_balances WHERE party_kind=? AND party_id=?');
    $stmt->bind_param('si',$kind,$partyId);$stmt->execute();$res=$stmt->get_result();$row=$res?$res->fetch_assoc():null;$stmt->close();
    $opening=$row['amount']??'0';
    $hasOpening=$row!==null;
    $returnCredits='0';$availableReturnCredit='0';$returns=[];
    if ($kind==='customer') {
        $stmt=$db->prepare("SELECT r.id,r.return_number,r.return_date,r.payable_amount,
          COALESCE((SELECT SUM(amount) FROM account_return_allocations WHERE return_id=r.id),0) used,
          COALESCE((SELECT SUM(rs.amount) FROM account_refund_sources rs
            JOIN account_payments refund ON refund.id=rs.refund_payment_id
            WHERE rs.source_kind='return' AND rs.source_id=r.id
              AND NOT EXISTS(SELECT 1 FROM account_payments rev WHERE rev.reversed_payment_id=refund.id)),0) refunded
          FROM sales_returns r WHERE r.customer_id=? ORDER BY r.return_date,r.id");
        $stmt->bind_param('i',$partyId);$stmt->execute();$res=$stmt->get_result();
        while($res && ($row=$res->fetch_assoc())) {
            $freeBeforeRefund=money_sub($row['payable_amount'],$row['used']);
            $available=money_sub($freeBeforeRefund,$row['refunded']);
            if(money_cmp($available,'0')<0)throw new LogicException('Refund reservations exceed unallocated return credit.');
            $returns[]=['id'=>(int)$row['id'],'number'=>$row['return_number'],
                 'date'=>$row['return_date'],'amount'=>$row['payable_amount'],
                 'allocated'=>$row['used'],'refunded'=>$row['refunded'],'available'=>$available];
            $returnCredits=money_add($returnCredits,$freeBeforeRefund);
            $availableReturnCredit=money_add($availableReturnCredit,$available);
        }
        $stmt->close();
    }
    $balance=money_sub(money_add($total,$opening),money_add($unallocated,$returnCredits));
    $openingAvailable='0';
    if(money_cmp($opening,'0')<0) {
        $openingAvailable=money_sub(money_abs($opening),acct_refund_reserved($db,$kind,$partyId,'opening',$partyId));
        if(money_cmp($openingAvailable,'0')<0)throw new LogicException('Refund reservations exceed opening credit.');
    }
    $statement=['party_kind'=>$kind,'party_id'=>$partyId,'opening_balance'=>$opening,'has_opening_balance'=>$hasOpening,
      'invoices'=>$invoices,'payments'=>$payments,'unallocated_credit'=>$unallocated,'available_payment_credit'=>$availablePaymentCredit,
      'return_credit'=>$returnCredits,'available_return_credit'=>$availableReturnCredit,'available_opening_credit'=>$openingAvailable,
      'returns'=>$returns,'balance'=>$balance,'unreconciled_invoices'=>$uncertain,'balance_final'=>$uncertain===0,
      'has_unmapped_refunds'=>acct_has_unmapped_refunds($db,$kind,$partyId)];
    $statement['movements']=acct_party_timeline($statement);
    return $statement;
}

/** Apply existing unallocated credit to one final invoice. */
function acct_apply_credit(mysqli $db, int $paymentId, int $invoiceId, $rawAmount, ?string $token=null): array {
    $amount=acct_decimal($rawAmount,true);
    $token=$token??bin2hex(random_bytes(20));
    if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$token))throw new InvalidArgumentException('شناسه تخصیص نامعتبر است.');
    $db->begin_transaction();
    try {
        $stmt=$db->prepare('SELECT party_kind,party_id,amount FROM account_payments WHERE id=?');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$r=$stmt->get_result();$p=$r?$r->fetch_assoc():null;$stmt->close();
        if (!$p) throw new InvalidArgumentException('پرداخت یافت نشد.');
        acct_party_lock($db,$p['party_kind'],(int)$p['party_id']);
        $stmt=$db->prepare('SELECT payment_id,invoice_id,amount FROM account_payment_allocations WHERE client_token=? LIMIT 1');
        $stmt->bind_param('s',$token);$stmt->execute();$rr=$stmt->get_result();$repeat=$rr?$rr->fetch_assoc():null;$stmt->close();
        if($repeat) {
            if((int)$repeat['payment_id']!==$paymentId || (int)$repeat['invoice_id']!==$invoiceId ||
                 money_cmp($repeat['amount'],$amount)!==0)
                throw new InvalidArgumentException('شناسه تخصیص برای سند متفاوت استفاده شده است.');
            $db->commit();return ['invoice_id'=>$invoiceId,'payment_id'=>$paymentId,'duplicate'=>true];
        }
        $stmt=$db->prepare('SELECT * FROM account_payments WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$p=$stmt->get_result()->fetch_assoc();$stmt->close();
        $validDirection=$p['party_kind']==='customer'?'in':'out';
        if ($p['direction']!==$validDirection || $p['reversed_payment_id']!==null)
            throw new InvalidArgumentException('سند مالی برگشتی یا استرداد اعتبار قابل تخصیص نیست.');
        $stmt=$db->prepare('SELECT 1 FROM account_payments WHERE reversed_payment_id=? LIMIT 1');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$res=$stmt->get_result();
        $wasReversed=$res && $res->fetch_assoc();$stmt->close();
        if ($wasReversed) throw new InvalidArgumentException('پرداخت باطل‌شده قابل تخصیص نیست.');
        $v=acct_invoice_outstanding($db,$invoiceId,true);
        if ($v['unreconciled']) throw new InvalidArgumentException('فاکتور تاریخی باید ابتدا تطبیق داده شود.');
        $inv=$v['invoice'];$kind=$p['party_kind'];
        if (($kind==='customer' && ($inv['type']!=='sales_invoice'||(int)$inv['customer_id']!==(int)$p['party_id']))
            || ($kind==='supplier' && ($inv['type']!=='purchase_invoice'||(int)$inv['supplier_id']!==(int)$p['party_id'])))
            throw new InvalidArgumentException('طرف حساب فاکتور و پرداخت متفاوت است.');
        acct_assert_refunds_allocatable($db,$kind,(int)$p['party_id']);
        $stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM account_payment_allocations WHERE reversed_at IS NULL AND payment_id=?');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$used=$stmt->get_result()->fetch_assoc()['s'];$stmt->close();
        $reserved=acct_refund_reserved($db,$kind,(int)$p['party_id'],'payment',$paymentId);
        if (money_cmp($amount,money_sub(money_sub($p['amount'],$used),$reserved))>0 || money_cmp($amount,$v['remaining'])>0)
            throw new InvalidArgumentException('مبلغ از اعتبار آزاد یا مانده فاکتور بیشتر است.');
        $stmt=$db->prepare('INSERT INTO account_payment_allocations(payment_id,invoice_id,amount,client_token) VALUES(?,?,?,?)');
        $stmt->bind_param('iiss',$paymentId,$invoiceId,$amount,$token);$stmt->execute();$stmt->close();
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
    $preferredDirection=$kind==='customer'?'in':'out';
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
          WHERE i.type='{$typ}' AND a.reversed_at IS NULL GROUP BY i.{$col}
        ) al ON al.party_id=p.id
        LEFT JOIN (
          SELECT x.party_id,SUM(CASE WHEN x.direction='{$preferredDirection}' THEN x.amount-COALESCE(y.used,0) ELSE -x.amount END) free
          FROM account_payments x
          LEFT JOIN (SELECT payment_id,SUM(amount) used FROM account_payment_allocations WHERE reversed_at IS NULL GROUP BY payment_id) y ON y.payment_id=x.id
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

/** Refund an existing customer/supplier credit; never a new invoice settlement. */
function acct_record_refund(mysqli $db,array $input): array {
    $kind=acct_party_kind((string)($input['party_kind']??''));
    $partyId=filter_var($input['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$partyId)throw new InvalidArgumentException('شناسه طرف حساب نامعتبر است.');
    $amount=acct_decimal($input['amount']??'',true);
    $method=(string)($input['method']??'');
    if (!in_array($method,['cash','pos','bank_transfer'],true)) throw new InvalidArgumentException('روش پرداخت نامعتبر است.');
    $token=(string)($input['client_token']??'');
    if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$token)) throw new InvalidArgumentException('شناسه ثبت تکراری نامعتبر است.');
    $date=(string)($input['payment_date']??date('Y-m-d'));
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$dt || $dt->format('Y-m-d')!==$date) throw new InvalidArgumentException('تاریخ نامعتبر است.');
    $note=trim((string)($input['note']??''));
    if (mb_strlen($note)>2000) throw new InvalidArgumentException('توضیح بیش از حد مجاز است.');
    $direction=$kind==='customer'?'out':'in';
    $db->begin_transaction();
    try {
        $cashAccountId=acct_resolve_cash_account($db,$method,$input['cash_account_id']??null);
        acct_party_lock($db,$kind,$partyId);
        $requestHash=hash('sha256',json_encode([$kind,$partyId,$direction,$amount,$method,$date,$note,$cashAccountId],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $stmt=$db->prepare('SELECT id,request_hash FROM account_payments WHERE client_token=?');
        $stmt->bind_param('s',$token);$stmt->execute();$r=$stmt->get_result();$old=$r?$r->fetch_assoc():null;$stmt->close();
        if ($old) {
            if (!hash_equals((string)($old['request_hash']??''),$requestHash))
                throw new InvalidArgumentException('شناسه تکراری برای سند مالی متفاوت استفاده شده است.');
            $db->commit();return ['payment_id'=>(int)$old['id'],'duplicate'=>true];
        }
        acct_assert_refunds_allocatable($db,$kind,(int)$partyId);
        $statement=acct_party_statement($db,$kind,(int)$partyId);
        if (!$statement['balance_final'] || money_cmp($statement['balance'],'0')>=0
            || money_cmp($amount,money_abs($statement['balance']))>0)
            throw new InvalidArgumentException('استرداد فقط تا سقف اعتبار قطعی طرف حساب مجاز است.');
        $sources=[];$leftToReserve=$amount;
        // Reserve actual, currently unallocated sources in deterministic order.
        // Do not consume money belonging to an already settled invoice.
        foreach($statement['payments'] as $p) {
            if(money_cmp($leftToReserve,'0')<=0)break;
            if($p['direction']!==($kind==='customer'?'in':'out') || $p['is_reversal'] || $p['is_reversed'])continue;
            $available=(string)$p['unallocated'];
            if(money_cmp($available,'0')<=0)continue;
            $take=money_cmp($available,$leftToReserve)>0?$leftToReserve:$available;
            $sources[]=['payment',(int)$p['id'],$take];
            $leftToReserve=money_sub($leftToReserve,$take);
        }
        foreach($statement['returns'] as $ret) {
            if(money_cmp($leftToReserve,'0')<=0)break;
            $available=(string)$ret['available'];
            if(money_cmp($available,'0')<=0)continue;
            $take=money_cmp($available,$leftToReserve)>0?$leftToReserve:$available;
            $sources[]=['return',(int)$ret['id'],$take];
            $leftToReserve=money_sub($leftToReserve,$take);
        }
        if(money_cmp($leftToReserve,'0')>0 && money_cmp($statement['available_opening_credit'],'0')>0) {
            $available=$statement['available_opening_credit'];
            $take=money_cmp($available,$leftToReserve)>0?$leftToReserve:$available;
            $sources[]=['opening',(int)$partyId,$take];
            $leftToReserve=money_sub($leftToReserve,$take);
        }
        if(money_cmp($leftToReserve,'0')>0)
            throw new InvalidArgumentException('منابع اعتبار آزاد برای استرداد کافی نیستند؛ وضعیت استردادهای پیشین را بررسی کنید.');
        $stmt=$db->prepare('INSERT INTO account_payments(party_kind,party_id,direction,amount,method,payment_date,note,client_token,cash_account_id,request_hash,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $createdBy=function_exists('auth_user_id')?auth_user_id():null;
        $stmt->bind_param('sissssssisi',$kind,$partyId,$direction,$amount,$method,$date,$note,$token,$cashAccountId,$requestHash,$createdBy);
        $stmt->execute();$pid=(int)$stmt->insert_id;$stmt->close();
        foreach($sources as [$sourceKind,$sourceId,$reservedAmount]) {
            $stmt=$db->prepare('INSERT INTO account_refund_sources(refund_payment_id,source_kind,source_id,amount) VALUES(?,?,?,?)');
            $stmt->bind_param('isis',$pid,$sourceKind,$sourceId,$reservedAmount);
            $stmt->execute();$stmt->close();
        }
        $db->commit();
        return ['payment_id'=>$pid,'duplicate'=>false,'reserved_sources'=>count($sources)];
    } catch (Throwable $e) {$db->rollback();throw $e;}
}
/** A reversal is a new opposite-direction record; original receipts stay auditable. */
function acct_reverse_payment(mysqli $db,int $paymentId,string $reason,string $token): array {
    if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$token)) throw new InvalidArgumentException('شناسه تراکنش نامعتبر است.');
    $reason=trim($reason);
    if (mb_strlen($reason)<5||mb_strlen($reason)>2000) throw new InvalidArgumentException('علت اصلاح باید به‌صورت مستند ثبت شود.');
    $db->begin_transaction();
    try {
        $stmt=$db->prepare('SELECT party_kind,party_id FROM account_payments WHERE id=?');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$res=$stmt->get_result();$where=$res?$res->fetch_assoc():null;$stmt->close();
        if (!$where) throw new InvalidArgumentException('تراکنش یافت نشد.');
        acct_party_lock($db,$where['party_kind'],(int)$where['party_id']);
        $stmt=$db->prepare('SELECT * FROM account_payments WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$p=$stmt->get_result()->fetch_assoc();$stmt->close();
        if ($p['reversed_payment_id']!==null) throw new InvalidArgumentException('سند برگشتی دوباره برگشت‌پذیر نیست.');
        $stmt=$db->prepare('SELECT id FROM account_payments WHERE reversed_payment_id=?');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$res=$stmt->get_result();$already=$res?$res->fetch_assoc():null;$stmt->close();
        if ($already) {$db->commit();return ['payment_id'=>(int)$already['id'],'duplicate'=>true];}
        $requiredDirection=$p['party_kind']==='customer'?'in':'out';
        if($p['direction']===$requiredDirection) {
            acct_assert_refunds_allocatable($db,$p['party_kind'],(int)$p['party_id']);
            $reserved=acct_refund_reserved($db,$p['party_kind'],(int)$p['party_id'],'payment',$paymentId);
            if(money_cmp($reserved,'0')>0)
                throw new InvalidArgumentException('این سند منبع استرداد اعتبار است؛ ابتدا سند استرداد وابسته را اصلاح کنید.');
        }
        $stmt=$db->prepare('SELECT invoice_id FROM account_payment_allocations WHERE payment_id=? AND reversed_at IS NULL ORDER BY invoice_id');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$res=$stmt->get_result();$invoiceIds=[];
        while($res && ($r=$res->fetch_assoc()))$invoiceIds[]=(int)$r['invoice_id'];
        $stmt->close();
        foreach($invoiceIds as $invId)acct_invoice_outstanding($db,$invId,true);
        $direction=$p['direction']==='in'?'out':'in';
        $note="ابطال سند مالی #{$paymentId}: ".$reason;
        $stmt=$db->prepare('INSERT INTO account_payments(party_kind,party_id,direction,amount,method,payment_date,note,client_token,reversed_payment_id,cash_account_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $date=date('Y-m-d');
        $createdBy=function_exists('auth_user_id')?auth_user_id():null;
        $stmt->bind_param('sissssssiii',$p['party_kind'],$p['party_id'],$direction,$p['amount'],$p['method'],$date,$note,$token,$paymentId,$p['cash_account_id'],$createdBy);
        $stmt->execute();$reverseId=(int)$stmt->insert_id;$stmt->close();
        $stmt=$db->prepare('UPDATE account_payment_allocations SET reversed_at=NOW() WHERE payment_id=? AND reversed_at IS NULL');
        $stmt->bind_param('i',$paymentId);$stmt->execute();$stmt->close();
        foreach($invoiceIds as $invId)acct_update_status($db,$invId);
        $db->commit();
        return ['payment_id'=>$reverseId,'duplicate'=>false];
    } catch(Throwable $e){$db->rollback();throw $e;}
}

/** Allocate documented sales return credit without pretending cash was received. */
function acct_allocate_return_credit(mysqli $db,int $returnId,int $invoiceId,$rawAmount,?string $token=null): array {
    $amount=acct_decimal($rawAmount,true);
    $token=$token??bin2hex(random_bytes(20));
    if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$token))throw new InvalidArgumentException('شناسه تخصیص نامعتبر است.');
    $db->begin_transaction();
    try {
        $stmt=$db->prepare('SELECT customer_id FROM sales_returns WHERE id=?');
        $stmt->bind_param('i',$returnId);$stmt->execute();$res=$stmt->get_result();$stub=$res?$res->fetch_assoc():null;$stmt->close();
        if(!$stub || !$stub['customer_id'])throw new InvalidArgumentException('سند برگشت یافت نشد.');
        $customerId=(int)$stub['customer_id'];
        acct_party_lock($db,'customer',$customerId);
        $stmt=$db->prepare('SELECT return_id,invoice_id,amount FROM account_return_allocations WHERE client_token=? LIMIT 1');
        $stmt->bind_param('s',$token);$stmt->execute();$rr=$stmt->get_result();$repeat=$rr?$rr->fetch_assoc():null;$stmt->close();
        if($repeat) {
            if((int)$repeat['return_id']!==$returnId || (int)$repeat['invoice_id']!==$invoiceId ||
                money_cmp($repeat['amount'],$amount)!==0)
                throw new InvalidArgumentException('شناسه تخصیص برای سند متفاوت استفاده شده است.');
            $db->commit();return ['invoice_id'=>$invoiceId,'return_id'=>$returnId,'duplicate'=>true];
        }
        $stmt=$db->prepare('SELECT id,customer_id,payable_amount FROM sales_returns WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$returnId);$stmt->execute();$ret=$stmt->get_result()->fetch_assoc();$stmt->close();
        $v=acct_invoice_outstanding($db,$invoiceId,true);
        if($v['unreconciled'] || $v['invoice']['type']!=='sales_invoice' ||
            (int)$v['invoice']['customer_id']!==$customerId)
            throw new InvalidArgumentException('فاکتور مقصد معتبر نیست یا نیازمند تطبیق تاریخی است.');
        acct_assert_refunds_allocatable($db,'customer',$customerId);
        $stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM account_return_allocations WHERE return_id=?');
        $stmt->bind_param('i',$returnId);$stmt->execute();$used=$stmt->get_result()->fetch_assoc()['s'];$stmt->close();
        $reserved=acct_refund_reserved($db,'customer',$customerId,'return',$returnId);
        if(money_cmp($amount,money_sub(money_sub($ret['payable_amount'],$used),$reserved))>0 ||
           money_cmp($amount,$v['remaining'])>0)
            throw new InvalidArgumentException('مبلغ تخصیص از اعتبار برگشت یا مانده فاکتور بیشتر است.');
        $stmt=$db->prepare('INSERT INTO account_return_allocations(return_id,invoice_id,amount,created_by,client_token) VALUES(?,?,?,?,?)');
        $createdBy=function_exists('auth_user_id')?auth_user_id():null;
        $stmt->bind_param('iisis',$returnId,$invoiceId,$amount,$createdBy,$token);$stmt->execute();$stmt->close();
        acct_update_status($db,$invoiceId);
        $db->commit();
        return ['return_id'=>$returnId,'invoice_id'=>$invoiceId];
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/**
 * Auditable chronological party ledger. Settlement allocations do not create
 * money and therefore do not appear twice. Legacy settlements are labeled
 * "historical", never mixed into today's cash movement.
 */
function acct_party_timeline(array $statement): array {
    $items=[];
    $append=static function(string $date,string $description,string $debit,string $credit,int $order) use (&$items): void {
        $items[]=['date'=>$date,'description'=>$description,'debit'=>$debit,'credit'=>$credit,'order'=>$order];
    };
    $opening=(string)$statement['opening_balance'];
    if(money_cmp($opening,'0')!==0) {
        $append('0000-00-00','مانده افتتاحیه',money_cmp($opening,'0')>0?$opening:'0',
            money_cmp($opening,'0')<0?money_abs($opening):'0',0);
    }
    foreach($statement['invoices'] as $invoice) {
        $append((string)$invoice['date'],'فاکتور '.$invoice['number'],(string)$invoice['total'],'0',10);
        if ($invoice['historical_paid']===null)continue;
        if(money_cmp($invoice['historical_paid'],'0')>0)
            $append((string)$invoice['date'],'تسویه تاریخی '.$invoice['number'].' (بدون گردش صندوق)',
                '0',(string)$invoice['historical_paid'],20);
    }
    foreach($statement['returns']??[] as $return) {
        $append((string)$return['date'],'برگشت از فروش '.$return['number'],'0',(string)$return['amount'],30);
    }
    $expected=$statement['party_kind']==='customer'?'in':'out';
    foreach($statement['payments'] as $payment) {
        $credit=$payment['direction']===$expected;
        $label=($credit?'دریافت/پرداخت ':'استرداد/سند اصلاحی ').'#'.$payment['id'];
        if($payment['is_reversal'])$label='سند معکوس #'.$payment['id'];
        $account=trim((string)($payment['cash_account_name']??''));
        $ref=trim((string)($payment['reference_no']??''));
        $note=trim((string)($payment['note']??''));
        if($account!=='')$label.=' | '.$account;
        if($ref!=='')$label.=' | پیگیری: '.$ref;
        if($note!=='')$label.=' | '.$note;
        $append((string)$payment['date'],$label,
            $credit?'0':(string)$payment['amount'],$credit?(string)$payment['amount']:'0',40);
    }
    usort($items,static function(array $a,array $b): int {
        $order=strcmp($a['date'],$b['date']);
        return $order!==0?$order:$a['order']<=>$b['order'];
    });
    $running='0';
    foreach($items as &$item) {
        $running=money_add($running,money_sub($item['debit'],$item['credit']));
        $item['balance']=$running;
        unset($item['order']);
    }
    unset($item);
    return $items;
}
