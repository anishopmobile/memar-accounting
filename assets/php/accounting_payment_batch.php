<?php
/**
 * Atomic multi-method receipt/remittance. One business action can involve
 * cash + POS + transfer, but creates individually auditable cashbook rows.
 */
declare(strict_types=1);
require_once __DIR__ . '/accounting_ledger.php';

function acct_record_payment_batch(mysqli $db, array $input): array
{
    $kind=acct_party_kind((string)($input['party_kind']??''));
    $partyId=filter_var($input['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$partyId) throw new InvalidArgumentException('طرف حساب نامعتبر است.');
    $token=(string)($input['client_token']??'');
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$token)) throw new InvalidArgumentException('شناسه عملیات مالی نامعتبر است.');
    $date=(string)($input['payment_date']??date('Y-m-d'));
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$dt || $dt->format('Y-m-d')!==$date) throw new InvalidArgumentException('تاریخ نامعتبر است.');
    $rows=$input['payments']??null;
    if (!is_array($rows) || count($rows)<1 || count($rows)>12 || array_keys($rows)!==range(0,count($rows)-1))
        throw new InvalidArgumentException('یک تا دوازده روش پرداخت مجاز است.');
    $normalized=[];$total='0';
    foreach($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('ردیف پرداخت نامعتبر است.');
        $amount=acct_decimal($row['amount']??'',true);
        $method=(string)($row['method']??'');
        if (!in_array($method,['cash','pos','bank_transfer'],true))
            throw new InvalidArgumentException('روش پرداخت نامعتبر است.');
        $accountId=(int)($row['cash_account_id']??0);
        if($accountId<0)throw new InvalidArgumentException('حساب پرداخت نامعتبر است.');
        $normalized[]=['amount'=>$amount,'method'=>$method,'cash_account_id'=>$accountId];
        $total=money_add($total,$amount);
    }
    $allocRows=$input['allocations']??[];
    if (!is_array($allocRows) || count($allocRows)>100)
        throw new InvalidArgumentException('تعداد تخصیص‌ها نامعتبر است.');
    $alloc=[];$allocated='0';
    foreach($allocRows as $a) {
        if (!is_array($a))throw new InvalidArgumentException('تخصیص نامعتبر است.');
        $invId=filter_var($a['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$invId || isset($alloc[(int)$invId]))throw new InvalidArgumentException('فاکتور تکراری یا نامعتبر است.');
        $amount=acct_decimal($a['amount']??'',true);
        $alloc[(int)$invId]=$amount;
        $allocated=money_add($allocated,$amount);
    }
    if(money_cmp($allocated,$total)>0)
        throw new InvalidArgumentException('تخصیص‌ها از مجموع پرداخت‌ها بیشتر است.');
    ksort($alloc,SORT_NUMERIC);
    $sourceId=(int)($input['source_proforma_id']??0);
    if($sourceId<0)throw new InvalidArgumentException('پیش‌فاکتور نامعتبر است.');
    $ref=trim((string)($input['reference_no']??''));
    $note=trim((string)($input['note']??''));
    if(mb_strlen($ref)>120||mb_strlen($note)>2000)
        throw new InvalidArgumentException('توضیحات بیش از حد مجاز است.');
    $hash=hash('sha256',json_encode([$kind,(int)$partyId,$token,$date,$sourceId,$ref,$note,$normalized,$alloc],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $db->begin_transaction();
    try {
        acct_party_lock($db,$kind,(int)$partyId);
        $stmt=$db->prepare('SELECT request_hash FROM account_payment_batches WHERE token=? FOR UPDATE');
        $stmt->bind_param('s',$token);$stmt->execute();$res=$stmt->get_result();$old=$res?$res->fetch_assoc():null;$stmt->close();
        if($old) {
            if(!hash_equals((string)$old['request_hash'],$hash))
                throw new InvalidArgumentException('شناسه تکراری برای عملیات متفاوت استفاده شده است.');
            $stmt=$db->prepare('SELECT id FROM account_payments WHERE batch_token=? ORDER BY id');
            $stmt->bind_param('s',$token);$stmt->execute();$res=$stmt->get_result();$ids=[];
            while($res && ($row=$res->fetch_assoc()))$ids[]=(int)$row['id'];
            $stmt->close();$db->commit();
            return ['payment_ids'=>$ids,'duplicate'=>true,'total'=>$total,'unallocated_credit'=>money_sub($total,$allocated)];
        }
        $stmt=$db->prepare('INSERT INTO account_payment_batches(token,request_hash,party_kind,party_id) VALUES(?,?,?,?)');
        $stmt->bind_param('sssi',$token,$hash,$kind,$partyId);$stmt->execute();$stmt->close();
        $remaining=$alloc;$ids=[];
        foreach($normalized as $index=>$row) {
            $budget=$row['amount'];$partAlloc=[];
            foreach($remaining as $invoiceId=>$needed) {
                if(money_cmp($budget,'0')<=0)break;
                if(money_cmp($needed,'0')<=0)continue;
                $part=money_cmp($needed,$budget)>0 ? $budget : $needed;
                $partAlloc[]=['invoice_id'=>(int)$invoiceId,'amount'=>$part];
                $remaining[$invoiceId]=money_sub($needed,$part);
                $budget=money_sub($budget,$part);
            }
            $partToken='part_'.substr(hash('sha256',$token.'|'.$index),0,54);
            $res=acct_record_payment($db,[
                'party_kind'=>$kind,'party_id'=>(int)$partyId,
                'direction'=>$kind==='customer'?'in':'out',
                'amount'=>$row['amount'],'method'=>$row['method'],'cash_account_id'=>$row['cash_account_id'],
                'payment_date'=>$date,'client_token'=>$partToken,'batch_token'=>$token,
                'source_proforma_id'=>$sourceId,'reference_no'=>$ref,'note'=>$note,
                'allocations'=>$partAlloc
            ],true);
            if($res['duplicate'])throw new InvalidArgumentException('ردیف پرداخت قبلاً در یک عملیات دیگر ثبت شده است.');
            $ids[]=(int)$res['payment_id'];
        }
        foreach($remaining as $left)if(money_cmp($left,'0')!==0)
            throw new LogicException('تخصیص ناقص در تراکنش مالی.');
        $db->commit();
        return ['payment_ids'=>$ids,'duplicate'=>false,'total'=>$total,'unallocated_credit'=>money_sub($total,$allocated)];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
