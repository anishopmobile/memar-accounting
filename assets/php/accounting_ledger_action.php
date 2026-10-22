<?php
/** Authenticated accounting receipts, payments, and account statements. */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/accounting_ledger.php';
require_once __DIR__ . '/accounting_payment_batch.php';
date_default_timezone_set('Asia/Tehran');

$action=(string)($_REQUEST['action']??'');
try {
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='accounts') {
        $res=$mysqli->query('SELECT id,name,account_type FROM accounting_cash_accounts WHERE is_active=1 ORDER BY account_type,id');
        $accounts=[];while($res && ($row=$res->fetch_assoc()))$accounts[]=$row;
        respond_json(true,'حساب‌ها',['accounts'=>$accounts]);
    }
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='statement') {
        $kind=(string)($_GET['party_kind']??'');
        $id=filter_var($_GET['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) throw new InvalidArgumentException('طرف حساب نامعتبر است.');
        respond_json(true,'صورت‌حساب',acct_party_statement($mysqli,$kind,(int)$id));
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') {
        respond_error('عملیات نامعتبر است.',405);
    }
    require_csrf_or_fail();
    if ($action==='create_account') {
        $name=trim((string)($_POST['name']??''));
        $type=(string)($_POST['account_type']??'');
        if (mb_strlen($name)<3 || mb_strlen($name)>120 || !in_array($type,['cash','pos','bank_transfer'],true))
            throw new InvalidArgumentException('نام یا نوع حساب نامعتبر است.');
        $stmt=$mysqli->prepare('INSERT INTO accounting_cash_accounts(name,account_type) VALUES(?,?)');
        $stmt->bind_param('ss',$name,$type);$stmt->execute();$id=(int)$stmt->insert_id;$stmt->close();
        respond_json(true,'حساب جدید ایجاد شد.',['id'=>$id]);
    }
    if ($action==='payment_batch') {
        $payments=json_decode((string)($_POST['payments']??''),true,32,JSON_THROW_ON_ERROR);
        $allocs=json_decode((string)($_POST['allocations']??'[]'),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($payments)||!is_array($allocs))throw new InvalidArgumentException('اطلاعات پرداخت نامعتبر است.');
        foreach($payments as &$p) {
            if(!is_array($p))throw new InvalidArgumentException('ردیف پرداخت نامعتبر است.');
            $p['amount']=parseMoneyInput((string)($p['amount']??''));
        }
        unset($p);
        foreach($allocs as &$a) {
            if(!is_array($a))throw new InvalidArgumentException('تخصیص نامعتبر است.');
            $a['amount']=parseMoneyInput((string)($a['amount']??''));
        }
        unset($a);
        $input=[
           'party_kind'=>$_POST['party_kind']??'','party_id'=>$_POST['party_id']??0,
           'source_proforma_id'=>$_POST['source_proforma_id']??0,
           'payment_date'=>$_POST['payment_date']??date('Y-m-d'),
           'client_token'=>$_POST['client_token']??'',
           'reference_no'=>$_POST['reference_no']??'',
           'note'=>$_POST['note']??'',
           'payments'=>$payments,'allocations'=>$allocs
        ];
        respond_json(true,'دریافت/پرداخت ترکیبی ثبت شد.',acct_record_payment_batch($mysqli,$input));
    }
    if ($action==='payment') {
        $alloc=$_POST['allocations']??'[]';
        if (is_string($alloc)) $alloc=json_decode($alloc,true,16,JSON_THROW_ON_ERROR);
        $input=[
            'party_kind'=>$_POST['party_kind']??'','party_id'=>$_POST['party_id']??0,
            'source_proforma_id'=>$_POST['source_proforma_id']??0,
            'amount'=>parseMoneyInput((string)($_POST['amount']??'')),
            'method'=>$_POST['method']??'','cash_account_id'=>$_POST['cash_account_id']??null,'direction'=>$_POST['direction']??'',
            'payment_date'=>$_POST['payment_date']??date('Y-m-d'),
            'reference_no'=>$_POST['reference_no']??'','note'=>$_POST['note']??'',
            'client_token'=>$_POST['client_token']??'','allocations'=>[]
        ];
        if (!is_array($alloc)) throw new InvalidArgumentException('تخصیص‌های نامعتبر.');
        foreach ($alloc as $a) {
            if (!is_array($a)) throw new InvalidArgumentException('تخصیص نامعتبر.');
            $a['amount']=parseMoneyInput((string)($a['amount']??''));
            $input['allocations'][]=$a;
        }
        respond_json(true,'پرداخت ثبت شد.',acct_record_payment($mysqli,$input));
    }
    if ($action==='refund') {
        $input=[
          'party_kind'=>$_POST['party_kind']??'',
          'party_id'=>$_POST['party_id']??0,
          'amount'=>parseMoneyInput((string)($_POST['amount']??'')),
          'method'=>$_POST['method']??'',
          'cash_account_id'=>$_POST['cash_account_id']??null,
          'payment_date'=>$_POST['payment_date']??date('Y-m-d'),
          'client_token'=>$_POST['client_token']??'',
          'note'=>$_POST['note']??''
        ];
        respond_json(true,'استرداد اعتبار ثبت شد.',acct_record_refund($mysqli,$input));
    }
    if ($action==='reverse') {
        $id=filter_var($_POST['payment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) throw new InvalidArgumentException('شناسه سند مالی نامعتبر است.');
        respond_json(true,'سند برگشتی ثبت شد.',acct_reverse_payment($mysqli,(int)$id,(string)($_POST['reason']??''),(string)($_POST['client_token']??'')));
    }
    if ($action==='apply_return_credit') {
        $returnId=filter_var($_POST['return_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $invoiceId=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$returnId || !$invoiceId) throw new InvalidArgumentException('سند برگشت یا فاکتور نامعتبر است.');
        $amount=parseMoneyInput((string)($_POST['amount']??''));
        respond_json(true,'اعتبار برگشت از فروش تخصیص داده شد.',acct_allocate_return_credit($mysqli,(int)$returnId,(int)$invoiceId,$amount));
    }
    if ($action==='apply_credit') {
        $paymentId=filter_var($_POST['payment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $invoiceId=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$paymentId || !$invoiceId) throw new InvalidArgumentException('پرداخت یا فاکتور نامعتبر است.');
        $amount=parseMoneyInput((string)($_POST['amount']??''));
        respond_json(true,'اعتبار به فاکتور تخصیص داده شد.',acct_apply_credit($mysqli,(int)$paymentId,(int)$invoiceId,$amount));
    }
    if ($action==='opening_balance') {
        $kind=acct_party_kind((string)($_POST['party_kind']??''));
        $id=filter_var($_POST['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) throw new InvalidArgumentException('طرف حساب نامعتبر است.');
        $raw=trim((string)($_POST['amount']??''));
        $base=parseMoneyInput($raw);
        if (!preg_match('/^-?\\d{1,12}(?:\\.\\d{1,2})?$/D',$base)) throw new InvalidArgumentException('مانده افتتاحیه نامعتبر است.');
        $note=trim((string)($_POST['note']??''));
        if (mb_strlen($note)>2000) throw new InvalidArgumentException('توضیح بیش از حد مجاز است.');
        $mysqli->begin_transaction();
        try {
            acct_party_lock($mysqli,$kind,(int)$id);
            $stmt=$mysqli->prepare('INSERT INTO account_opening_balances(party_kind,party_id,amount,note) VALUES(?,?,?,?)');
            $stmt->bind_param('siss',$kind,$id,$base,$note);$stmt->execute();$stmt->close();
            $mysqli->commit();
        } catch (Throwable $e) {$mysqli->rollback();throw $e;}
        respond_json(true,'مانده افتتاحیه ثبت شد.',['party_id'=>(int)$id]);
    }
    if ($action==='reconcile_legacy') {
        $invoiceId=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $amount=acct_decimal(parseMoneyInput((string)($_POST['historical_paid']??'')));
        if (!$invoiceId) throw new InvalidArgumentException('فاکتور نامعتبر است.');
        $mysqli->begin_transaction();
        try {
            $stmt=$mysqli->prepare('SELECT * FROM invoices WHERE id=? FOR UPDATE');
            $stmt->bind_param('i',$invoiceId);$stmt->execute();$res=$stmt->get_result();$inv=$res?$res->fetch_assoc():null;$stmt->close();
            if (!$inv || !in_array($inv['type'],['sales_invoice','purchase_invoice'],true)
                || $inv['legacy_paid_amount']!==null) throw new InvalidArgumentException('این فاکتور نیازی به تطبیق تاریخی ندارد.');
            if (money_cmp($amount,$inv['payable_amount'])>0) throw new InvalidArgumentException('پرداخت تاریخی از مبلغ فاکتور بیشتر است.');
            $stmt=$mysqli->prepare('UPDATE invoices SET legacy_paid_amount=? WHERE id=? AND legacy_paid_amount IS NULL');
            $stmt->bind_param('si',$amount,$invoiceId);$stmt->execute();$stmt->close();
            acct_update_status($mysqli,(int)$invoiceId);
            $mysqli->commit();
        } catch (Throwable $e) {$mysqli->rollback();throw $e;}
        respond_json(true,'مبلغ پرداخت تاریخی ثبت شد.',['invoice_id'=>(int)$invoiceId]);
    }
    respond_error('عملیات نامعتبر.',400);
} catch (InvalidArgumentException|JsonException $e) {
    respond_error($e->getMessage(),422);
} catch (Throwable $e) {
    error_log('accounting_ledger_action: '.$e->getMessage());
    respond_error('خطای ثبت مالی؛ هیچ تراکنش ناقصی ذخیره نشد.',500);
}
