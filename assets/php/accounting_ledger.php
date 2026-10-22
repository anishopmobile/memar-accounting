<?php
/** Unified accounting receivables/payables engine; money always in TOMAN. */
declare(strict_types=1);
require_once __DIR__ . '/invoice_common.php';

function ledger_party_field(string $kind): string {
    if (!in_array($kind, ['customer','supplier'], true)) throw new InvalidArgumentException('نوع طرف حساب نامعتبر است.');
    return $kind === 'customer' ? 'customer_id' : 'supplier_id';
}
function ledger_assert_party(mysqli $db, string $kind, int $id): void {
    if ($id < 1) throw new InvalidArgumentException('شناسه طرف حساب نامعتبر است.');
    $table = $kind === 'customer' ? 'customers' : 'suppliers';
    $st=$db->prepare("SELECT id FROM {$table} WHERE id=? FOR UPDATE");
    $st->bind_param('i',$id); $st->execute(); $exists=(bool)$st->get_result()->fetch_assoc(); $st->close();
    if (!$exists) throw new InvalidArgumentException('طرف حساب یافت نشد.');
}
function ledger_money($input, bool $allowZero=false): string {
    $s=trim((string)$input);
    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $s)) throw new InvalidArgumentException('مبلغ نامعتبر است.');
    $m=money_round($s);
    if (money_cmp($m,'0') < ($allowZero ? 0 : 1)) throw new InvalidArgumentException('مبلغ باید مثبت باشد.');
    return $m;
}
function ledger_invoice_balance(mysqli $db, int $id, bool $lock=false): array {
    $st=$db->prepare('SELECT * FROM invoices WHERE id=? '.($lock?'FOR UPDATE':''));
    $st->bind_param('i',$id); $st->execute(); $inv=$st->get_result()->fetch_assoc(); $st->close();
    if (!$inv) throw new InvalidArgumentException('فاکتور یافت نشد.');
    $st=$db->prepare("SELECT COALESCE(SUM(aa.amount),0) AS amount FROM accounting_allocations aa
      JOIN accounting_receipts ar ON ar.id=aa.receipt_id AND ar.state='posted'
      WHERE aa.invoice_id=?");
    $st->bind_param('i',$id);$st->execute();$allocated=$st->get_result()->fetch_assoc()['amount'];$st->close();
    $st=$db->prepare('SELECT COALESCE(amount,0) amount, COALESCE(needs_review,0) needs_review FROM accounting_legacy_settlements WHERE invoice_id=?');
    $st->bind_param('i',$id);$st->execute();$legacy=$st->get_result()->fetch_assoc() ?: ['amount'=>'0','needs_review'=>0];$st->close();
    $returned='0';
    if ($inv['type']==='sales_invoice') {
        $st=$db->prepare('SELECT COALESCE(SUM(payable_amount),0) AS amount FROM sales_returns WHERE invoice_id=?');
        $st->bind_param('i',$id);$st->execute();$returned=$st->get_result()->fetch_assoc()['amount'];$st->close();
    }
    $settled=money_add(money_add($allocated,$legacy['amount']),$returned);
    $balance=money_sub($inv['payable_amount'],$settled);
    return ['invoice'=>$inv,'allocated'=>$allocated,'historical'=>$legacy['amount'],
        'returned'=>$returned,'settled'=>$settled,'balance'=>$balance,'needs_review'=>(bool)$legacy['needs_review']];
}
function ledger_update_invoice_status(mysqli $db,int $id): string {
    $s=ledger_invoice_balance($db,$id,true);
    if (str_contains($s['invoice']['type'],'proforma')) return (string)$s['invoice']['payment_status'];
    $status=money_cmp($s['balance'],'0')<=0 ? 'paid' : (money_cmp($s['settled'],'0')>0 ? 'partial' : 'unpaid');
    $st=$db->prepare('UPDATE invoices SET payment_status=? WHERE id=?');
    $st->bind_param('si',$status,$id);$st->execute();$st->close();
    return $status;
}
/** Must be called inside a transaction; callers lock the party row first. */
function ledger_post_in_tx(mysqli $db,array $in): array {
    $kind=(string)($in['party_kind']??'');
    $party=(int)($in['party_id']??0);
    ledger_assert_party($db,$kind,$party);
    $direction=(string)($in['direction']??'');
    if (!in_array($direction,['in','out'],true)) throw new InvalidArgumentException('جهت دریافت/پرداخت نامعتبر است.');
    $amount=ledger_money($in['amount']??'');
    $method=(string)($in['method']??'');
    if (!in_array($method,['cash','pos','bank_transfer'],true)) throw new InvalidArgumentException('روش دریافت/پرداخت نامعتبر است.');
    $date=(string)($in['payment_date']??date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) throw new InvalidArgumentException('تاریخ نامعتبر است.');
    $key=trim((string)($in['request_key']??''));
    if ($key !== '' && !preg_match('/^[A-Za-z0-9:_-]{12,80}$/D',$key)) throw new InvalidArgumentException('شناسه عملیات نامعتبر است.');

    $allocs=$in['allocations']??[];
    if (!is_array($allocs)) throw new InvalidArgumentException('تخصیص‌ها نامعتبرند.');
    $sum='0';$seen=[];$normalized=[];
    foreach ($allocs as $one) {
        if (!is_array($one)) throw new InvalidArgumentException('تخصیص نامعتبر است.');
        $id=(int)($one['invoice_id']??0);
        if ($id<1 || isset($seen[$id])) throw new InvalidArgumentException('شماره فاکتور تکراری یا نامعتبر است.');
        $seen[$id]=true;$v=ledger_money($one['amount']??'');
        $sum=money_add($sum,$v);$normalized[$id]=$v;
    }
    if (money_cmp($sum,$amount)>0) throw new InvalidArgumentException('جمع تخصیص‌ها از مبلغ پرداخت بیشتر است.');
    ksort($normalized,SORT_NUMERIC);
    $proformaId=(int)($in['source_proforma_id']??0);
    $label=substr(trim((string)($in['account_label']??'')),0,120);
    $ref=substr(trim((string)($in['reference_number']??'')),0,120);
    $note=substr(trim((string)($in['note']??'')),0,2000);
    $fingerprint=hash('sha256',json_encode([
        $kind,$party,$direction,$amount,$method,$date,$label,$ref,$note,$proformaId,$normalized
    ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    if ($key!=='') {
        $st=$db->prepare('SELECT id,request_fingerprint FROM accounting_receipts WHERE request_key=?');
        $st->bind_param('s',$key);$st->execute();$prior=$st->get_result()->fetch_assoc();$st->close();
        if($prior) {
            if(!hash_equals((string)($prior['request_fingerprint']??''),$fingerprint))
                throw new InvalidArgumentException('شناسه عملیات برای محتوای متفاوتی استفاده شده است.');
            return ['receipt_id'=>(int)$prior['id'],'duplicate'=>true];
        }
    }
    foreach ($normalized as $id=>$v) {
        $s=ledger_invoice_balance($db,(int)$id,true);$inv=$s['invoice'];
        $foreign=$kind==='customer'?'customer_id':'supplier_id';
        $expected=$kind==='customer'?'sales_invoice':'purchase_invoice';
        $expectedDirection=$kind==='customer'?'in':'out';
        if ($inv['type']!==$expected || (int)$inv[$foreign]!==$party || $direction!==$expectedDirection) throw new InvalidArgumentException('تخصیص به فاکتور این طرف حساب مجاز نیست.');
        if ($s['needs_review']) throw new InvalidArgumentException('ابتدا مانده پرداخت جزئی تاریخی فاکتور را تعیین کنید.');
        if (money_cmp($v,$s['balance'])>0) throw new InvalidArgumentException('تخصیص از مانده فاکتور بیشتر است.');
    }
    if ($proformaId>0) {
        if ($normalized) throw new InvalidArgumentException('پیش‌دریافت فقط به پیش‌فاکتور مرتبط می‌شود و هنوز تخصیص قطعی ندارد.');
        $st=$db->prepare('SELECT type,customer_id,supplier_id FROM invoices WHERE id=? FOR UPDATE');
        $st->bind_param('i',$proformaId);$st->execute();$pro=$st->get_result()->fetch_assoc();$st->close();
        $expected=$kind==='customer'?'sales_proforma':'purchase_proforma';
        $fk=$kind==='customer'?'customer_id':'supplier_id';
        $needDirection=$kind==='customer'?'in':'out';
        if(!$pro || $pro['type']!==$expected || (int)$pro[$fk]!==$party || $direction!==$needDirection)
            throw new InvalidArgumentException('پیش‌فاکتور متعلق به این طرف حساب نیست.');
    }
        $st=$db->prepare("INSERT INTO accounting_receipts
     (party_kind,party_id,direction,amount,method,account_label,payment_date,reference_number,note,request_key,source_proforma_id,request_fingerprint)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $linkedProforma=$proformaId ?: null;
    $st->bind_param('sissssssssis',$kind,$party,$direction,$amount,$method,$label,$date,$ref,$note,$key,$linkedProforma,$fingerprint);
    $st->execute();$receiptId=(int)$db->insert_id;$st->close();
    foreach ($normalized as $id=>$v) {
        $st=$db->prepare('INSERT INTO accounting_allocations (receipt_id,invoice_id,amount) VALUES (?,?,?)');
        $st->bind_param('iis',$receiptId,$id,$v);$st->execute();$st->close();
        ledger_update_invoice_status($db,(int)$id);
    }
    return ['receipt_id'=>$receiptId,'duplicate'=>false,'unallocated'=>money_sub($amount,$sum)];
}
function ledger_post(mysqli $db,array $in): array {
    $db->begin_transaction();
    try { $r=ledger_post_in_tx($db,$in);$db->commit();return $r; }
    catch(Throwable $e){$db->rollback();throw $e;}
}
function ledger_party_balance(mysqli $db,string $kind,int $party): array {
    $field=ledger_party_field($kind);
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $st=$db->prepare("SELECT COALESCE(SUM(i.payable_amount),0) AS amount FROM invoices i WHERE i.type=? AND i.{$field}=?");
    $st->bind_param('si',$type,$party);$st->execute();$invoices=$st->get_result()->fetch_assoc()['amount'];$st->close();
    $returns='0';
    if ($kind==='customer'){
        $st=$db->prepare('SELECT COALESCE(SUM(payable_amount),0) amount FROM sales_returns WHERE customer_id=?');
        $st->bind_param('i',$party);$st->execute();$returns=$st->get_result()->fetch_assoc()['amount'];$st->close();
    }
    $st=$db->prepare("SELECT COALESCE(SUM(ls.amount),0) amount FROM accounting_legacy_settlements ls
       JOIN invoices i ON i.id=ls.invoice_id WHERE i.type=? AND i.{$field}=?");
    $st->bind_param('si',$type,$party);$st->execute();$legacy=$st->get_result()->fetch_assoc()['amount'];$st->close();
    $st=$db->prepare("SELECT direction,COALESCE(SUM(amount),0) amount FROM accounting_receipts WHERE party_kind=? AND party_id=? AND state='posted' GROUP BY direction");
    $st->bind_param('si',$kind,$party);$st->execute();$res=$st->get_result();$in='0';$out='0';
    while($row=$res->fetch_assoc()) {if($row['direction']==='in')$in=$row['amount'];else $out=$row['amount'];}$st->close();
    $st=$db->prepare('SELECT opening_amount FROM accounting_openings WHERE party_kind=? AND party_id=?');
    $st->bind_param('si',$kind,$party);$st->execute();$opening=$st->get_result()->fetch_assoc()['opening_amount']??'0';$st->close();
    $net=money_add(money_sub(money_sub($invoices,$returns),$legacy),$opening);
    $net=$kind==='customer'?money_add(money_sub($net,$in),$out):money_add(money_sub($net,$out),$in);
    return ['invoiced'=>$invoices,'returns'=>$returns,'historical_settled'=>$legacy,'opening'=>$opening,
     'received'=>$in,'paid'=>$out,'net'=>$net,'debt'=>money_cmp($net,'0')>0?$net:'0',
     'credit'=>money_cmp($net,'0')<0?money_abs($net):'0'];
}
function ledger_set_opening(mysqli $db,string $kind,int $party,string $amount,string $note=''): void {
    $db->begin_transaction();
    try {
        ledger_assert_party($db,$kind,$party);
        if (!preg_match('/^-?\d{1,12}(?:\.\d{1,2})?$/D',$amount)) throw new InvalidArgumentException('مانده افتتاحیه نامعتبر است.');
        $existing=$db->prepare('SELECT 1 FROM accounting_openings WHERE party_kind=? AND party_id=?');
        $existing->bind_param('si',$kind,$party);$existing->execute();$found=$existing->get_result()->fetch_assoc();$existing->close();
        if($found)throw new InvalidArgumentException('مانده افتتاحیه قبلاً ثبت شده است.');
        $st=$db->prepare('INSERT INTO accounting_openings (party_kind,party_id,opening_amount,note) VALUES (?,?,?,?)');
        $st->bind_param('siss',$kind,$party,$amount,$note);$st->execute();$st->close();$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/** Allocate an existing advance/overpayment to an invoice without moving money again. */
function ledger_allocate_existing(mysqli $db,int $receiptId,int $invoiceId,string $amount,bool $inTx=false): void {
    $value=ledger_money($amount);if(!$inTx)$db->begin_transaction();
    try {
        // Lock parent party first, then payment and invoice.
        $st=$db->prepare('SELECT party_kind,party_id,direction,state FROM accounting_receipts WHERE id=?');
        $st->bind_param('i',$receiptId);$st->execute();$pre=$st->get_result()->fetch_assoc();$st->close();
        if(!$pre || $pre['state']!=='posted')throw new InvalidArgumentException('سند دریافت/پرداخت یافت نشد.');
        ledger_assert_party($db,$pre['party_kind'],(int)$pre['party_id']);
        $st=$db->prepare('SELECT * FROM accounting_receipts WHERE id=? FOR UPDATE');
        $st->bind_param('i',$receiptId);$st->execute();$receipt=$st->get_result()->fetch_assoc();$st->close();
        $st=$db->prepare('SELECT COALESCE(SUM(amount),0) sum_paid FROM accounting_allocations WHERE receipt_id=?');
        $st->bind_param('i',$receiptId);$st->execute();$used=$st->get_result()->fetch_assoc()['sum_paid'];$st->close();
        if(money_cmp(money_add($used,$value),$receipt['amount'])>0)throw new InvalidArgumentException('اعتبار استفاده‌نشده کافی نیست.');
        $balance=ledger_invoice_balance($db,$invoiceId,true);$inv=$balance['invoice'];
        $expected=$pre['party_kind']==='customer'?'sales_invoice':'purchase_invoice';
        $fk=$pre['party_kind']==='customer'?'customer_id':'supplier_id';
        $direction=$pre['party_kind']==='customer'?'in':'out';
        if($inv['type']!==$expected || (int)$inv[$fk] !== (int)$pre['party_id'] || $receipt['direction']!==$direction)throw new InvalidArgumentException('سند با فاکتور انتخابی سازگار نیست.');
        if($balance['needs_review'] || money_cmp($value,$balance['balance'])>0)throw new InvalidArgumentException('مانده فاکتور برای تخصیص کافی نیست.');
        $st=$db->prepare('INSERT INTO accounting_allocations (receipt_id,invoice_id,amount) VALUES(?,?,?) ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)');
        $st->bind_param('iis',$receiptId,$invoiceId,$value);$st->execute();$st->close();
        ledger_update_invoice_status($db,$invoiceId);if(!$inTx)$db->commit();
    }catch(Throwable $e){if(!$inTx)$db->rollback();throw $e;}
}

/** Cancel an erroneous posted receipt without deleting history or moving cash. */
function ledger_reverse_receipt(mysqli $db,int $receiptId,string $reason,bool $inTx=false): int {
    $reason=trim($reason);
    if($receiptId<1 || mb_strlen($reason)<4 || mb_strlen($reason)>500)
        throw new InvalidArgumentException('دلیل اصلاح سند (حداقل ۴ حرف) الزامی است.');
    if(!$inTx)$db->begin_transaction();
    try {
        $st=$db->prepare('SELECT party_kind,party_id FROM accounting_receipts WHERE id=?');
        $st->bind_param('i',$receiptId);$st->execute();$pre=$st->get_result()->fetch_assoc();$st->close();
        if(!$pre)throw new InvalidArgumentException('سند مالی یافت نشد.');
        ledger_assert_party($db,$pre['party_kind'],(int)$pre['party_id']);
        $st=$db->prepare('SELECT * FROM accounting_receipts WHERE id=? FOR UPDATE');
        $st->bind_param('i',$receiptId);$st->execute();$orig=$st->get_result()->fetch_assoc();$st->close();
        if(!$orig || $orig['state']!=='posted' || $orig['reversal_of']!==null)
            throw new InvalidArgumentException('این سند قابل ابطال نیست یا قبلاً ابطال شده است.');
        $st=$db->prepare('SELECT invoice_id FROM accounting_allocations WHERE receipt_id=? ORDER BY invoice_id');
        $st->bind_param('i',$receiptId);$st->execute();$invoices=array_column($st->get_result()->fetch_all(MYSQLI_ASSOC),'invoice_id');$st->close();
        $st=$db->prepare("UPDATE accounting_receipts SET state='reversed' WHERE id=? AND state='posted'");
        $st->bind_param('i',$receiptId);$st->execute();
        if($st->affected_rows!==1)throw new RuntimeException('Concurrent receipt reversal');
        $st->close();
        $dir=$orig['direction']==='in'?'out':'in';
        $note='ابطال سند #'.$receiptId.': '.$reason;
        $date=date('Y-m-d');
        $key='reversal-'.$receiptId;
        $st=$db->prepare("INSERT INTO accounting_receipts
           (party_kind,party_id,direction,amount,method,account_label,payment_date,reference_number,note,state,reversal_of,request_key)
           VALUES (?,?,?,?,?,?,?,?,?,'reversed',?,?)");
        $st->bind_param('sisssssssis',$orig['party_kind'],$orig['party_id'],$dir,$orig['amount'],$orig['method'],$orig['account_label'],$date,$orig['reference_number'],$note,$receiptId,$key);
        $st->execute();$newId=(int)$db->insert_id;$st->close();
        foreach($invoices as $id)ledger_update_invoice_status($db,(int)$id);
        if(!$inTx)$db->commit();return $newId;
    }catch(Throwable $e){if(!$inTx)$db->rollback();throw $e;}
}
