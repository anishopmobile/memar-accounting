<?php
/** Authenticated API for offline accounting receipts, allocations, statements and opening entries. */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/invoice_common.php';
require_once __DIR__ . '/accounting/ledger.php';

$action = trim((string)($_REQUEST['action'] ?? ''));
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'invoice') {
            $id=filter_var($_GET['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) throw new InvalidArgumentException('شناسه فاکتور نامعتبر است.');
            $balance=acct_invoice_balance($mysqli,(int)$id);
            $stmt=$mysqli->prepare("SELECT r.id,r.payment_date,r.amount,r.method,r.account_name,
              r.reference_number,r.note,r.flow,r.status,COALESCE(SUM(a.amount),0) allocated
              FROM accounting_receipts r LEFT JOIN accounting_allocations a ON a.receipt_id=r.id AND a.invoice_id=?
              WHERE r.party_kind=? AND r.party_id=? AND
              (a.id IS NOT NULL OR r.proforma_id=?)
              GROUP BY r.id ORDER BY r.payment_date DESC,r.id DESC");
            $stmt->bind_param('isii',$id,$balance['party_kind'],$balance['party_id'],$id);
            $stmt->execute();$rs=$stmt->get_result();$history=[];
            while($row=$rs->fetch_assoc())$history[]=$row;
            $stmt->close();
            respond_json(true,'',['balance'=>$balance,'history'=>$history]);
        }
        if ($action === 'party') {
            $kind=(string)($_GET['party_kind']??'');
            $id=filter_var($_GET['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) throw new InvalidArgumentException('طرف حساب نامعتبر است.');
            acct_party_table($kind);
            $table=acct_party_table($kind);
            $stmt=$mysqli->prepare("SELECT * FROM {$table} WHERE id=?");
            $stmt->bind_param('i',$id);$stmt->execute();$party=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$party)throw new InvalidArgumentException('طرف حساب پیدا نشد.');
            respond_json(true,'',['party'=>$party]+acct_statement($mysqli,$kind,(int)$id));
        }
        respond_error('درخواست نامعتبر است.',404);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond_error('روش درخواست نامعتبر است.',405);
    require_csrf_or_fail();
    if ($action === 'post') {
        $kind=(string)($_POST['party_kind']??'');
        $partyId=(int)($_POST['party_id']??0);
        $flow=(string)($_POST['flow']??($kind==='customer'?'in':'out'));
        $amount=parseMoneyInput($_POST['amount']??'');
        $method=(string)($_POST['method']??'pos');
        $date=(string)($_POST['payment_date']??date('Y-m-d'));
        $reference=trim((string)($_POST['reference_number']??''));
        $account=trim((string)($_POST['account_name']??''));
        $note=trim((string)($_POST['note']??''));
        $first=(int)($_POST['invoice_id']??0);
        $proforma=(int)($_POST['proforma_id']??0);
        $token=trim((string)($_POST['client_token']??''));
        if($token==='')throw new InvalidArgumentException('شناسه امن درخواست الزامی است.');
        $manual=[];
        if(isset($_POST['allocations']) && $_POST['allocations']!=='') {
            $raw=json_decode((string)$_POST['allocations'],true,20,JSON_THROW_ON_ERROR);
            if(!is_array($raw)||count($raw)>100)throw new InvalidArgumentException('فهرست تخصیص‌ها معتبر نیست.');
            foreach($raw as $entry) {
                if(!is_array($entry))throw new InvalidArgumentException('تخصیص نامعتبر است.');
                $manual[]=['invoice_id'=>(int)($entry['invoice_id']??0),
                  'amount'=>parseMoneyInput($entry['amount']??'')];
            }
        }
        $result=acct_post($mysqli,$kind,$partyId,$flow,$amount,$method,$date,$reference,
          $account,$note,$first?:null,$proforma?:null,$token,false,
          ($_POST['auto_allocate']??'1')==='1',$manual);
        respond_json(true,$result['existing']?'این دریافت/پرداخت قبلاً ثبت شده است.':'دریافت/پرداخت ثبت شد.',$result);
    }
    if ($action === 'reverse') {
        $id=(int)($_POST['receipt_id']??0);
        if(!$id)throw new InvalidArgumentException('شناسه تراکنش معتبر نیست.');
        acct_reverse($mysqli,$id,trim((string)($_POST['note']??'')));
        respond_json(true,'تراکنش با ثبت سند معکوس ابطال شد.');
    }
    if ($action === 'opening') {
        $amount=parseMoneyInput($_POST['amount']??'');
        if(($_POST['sign']??'debt')==='credit')$amount='-'.$amount;
        acct_opening($mysqli,(string)($_POST['party_kind']??''),(int)($_POST['party_id']??0),
          $amount,(string)($_POST['entry_date']??date('Y-m-d')),trim((string)($_POST['note']??'')));
        respond_json(true,'مانده افتتاحیه ثبت شد.');
    }
    if ($action === 'legacy') {
        $amount=parseMoneyInput($_POST['amount']??'');
        acct_verify_legacy($mysqli,(int)($_POST['invoice_id']??0),$amount);
        respond_json(true,'مبلغ پرداخت جزئی قدیمی تأیید شد.');
    }
    if ($action === 'apply_credit') {
        $used=acct_apply_credit($mysqli,(string)($_POST['party_kind']??''),
          (int)($_POST['party_id']??0),(int)($_POST['invoice_id']??0));
        respond_json(true,'اعتبار قابل استفاده به فاکتور تخصیص یافت.',['allocated'=>$used]);
    }
    respond_error('عملیات نامعتبر است.',404);
} catch (JsonException|InvalidArgumentException $e) {
    respond_error($e instanceof JsonException?'اطلاعات تخصیص نامعتبر است.':$e->getMessage(),422);
} catch(Throwable $e) {
    error_log('accounting_action: '.$e->getMessage());
    respond_error('خطا در ثبت عملیات مالی؛ تغییری اعمال نشد.',500);
}
