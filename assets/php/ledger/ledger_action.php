<?php
/** Authenticated ledger receipt/disbursement API; no online-gateway side effects. */
require_once __DIR__.'/../boot.php';
require_once __DIR__.'/../db.php';
require_once __DIR__.'/ledger_service.php';
require_once __DIR__.'/../currency.php';
header('Content-Type: application/json; charset=utf-8');
function ledger_respond(int $status,array $data): void {
    http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_or_fail();
        if(($_POST['action']??'')==='reconcile_partial') {
            $id=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$id)throw new InvalidArgumentException('فاکتور نامعتبر است.');
            $result=ledger_reconcile_partial($mysqli,(int)$id,parseMoneyInput((string)($_POST['paid_amount']??'')),(string)($_POST['explanation']??''));
            ledger_respond(200,['success'=>true,'data'=>$result]);
        }
        if(($_POST['action']??'')==='void_payment') {
            $kind=(string)($_POST['party_kind']??'');
            $pid=filter_var($_POST['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $paymentId=filter_var($_POST['payment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$pid||!$paymentId)throw new InvalidArgumentException('شناسه نامعتبر است.');
            ledger_respond(200,['success'=>true,'data'=>ledger_void_payment($mysqli,$kind,(int)$pid,(int)$paymentId,(string)($_POST['reason']??''))]);
        }
        if(($_POST['action']??'')==='apply_credit') {
            $kind=(string)($_POST['party_kind']??'');
            $pid=filter_var($_POST['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $paymentId=filter_var($_POST['payment_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $invoiceId=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$pid||!$paymentId||!$invoiceId)throw new InvalidArgumentException('شناسه نامعتبر است.');
            ledger_respond(200,['success'=>true,'data'=>ledger_apply_credit($mysqli,$kind,(int)$pid,(int)$paymentId,(int)$invoiceId,
              parseMoneyInput((string)($_POST['amount']??'')),(string)($_POST['request_token']??''))]);
        }
        if(($_POST['action']??'')==='opening') {
            $kind=(string)($_POST['party_kind']??'');
            $pid=filter_var($_POST['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$pid)throw new InvalidArgumentException('طرف حساب نامعتبر است.');
            ledger_respond(200,['success'=>true,'data'=>ledger_record_opening($mysqli,$kind,(int)$pid,
              parseMoneyInput((string)($_POST['signed_amount']??'')),(string)($_POST['effective_on']??''),(string)($_POST['note']??''))]);
        }
        $input=[
            'party_kind'=>$_POST['party_kind']??'',
            'direction'=>$_POST['direction']??(($_POST['party_kind']??'')==='customer'?'receipt':'disbursement'),
            'party_id'=>$_POST['party_id']??'',
            'method'=>$_POST['method']??'',
            'paid_on'=>$_POST['paid_on']??'',
            'reference_no'=>$_POST['reference_no']??'',
            'note'=>$_POST['note']??'',
            'request_token'=>$_POST['request_token']??'',
            'amount'=>parseMoneyInput((string)($_POST['amount']??'')),
            'allocations'=>[]
        ];
        $raw=(string)($_POST['allocations']??'[]');
        $arr=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($arr))throw new InvalidArgumentException('تخصیص نامعتبر است.');
        foreach($arr as $row) {
            if(!is_array($row))throw new InvalidArgumentException('تخصیص نامعتبر است.');
            $input['allocations'][]=['invoice_id'=>$row['invoice_id']??null,'amount'=>parseMoneyInput((string)($row['amount']??''))];
        }
        ledger_respond(200,['success'=>true,'data'=>ledger_record_payment($mysqli,$input)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') ledger_respond(405,['success'=>false,'message'=>'Method not allowed']);
    ledger_initialize_legacy($mysqli);
    if(isset($_GET['invoice_id'])) {
        $id=filter_var($_GET['invoice_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)throw new InvalidArgumentException('فاکتور نامعتبر است.');
        $b=ledger_invoice_balance($mysqli,(int)$id);
        unset($b['invoice']['client_token']);
        ledger_respond(200,['success'=>true,'data'=>$b]);
    }
    $kind=(string)($_GET['party_kind']??'');
    $id=filter_var($_GET['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)throw new InvalidArgumentException('طرف حساب نامعتبر است.');
    $details=($_GET['details']??'')==='1';
    ledger_respond(200,['success'=>true,'data'=>$details?ledger_party_details($mysqli,$kind,(int)$id):ledger_party_summary($mysqli,$kind,(int)$id)]);
} catch (InvalidArgumentException|DomainException $e) {
    ledger_respond(422,['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('ledger_action: '.$e->getMessage());
    ledger_respond(500,['success'=>false,'message'=>'خطای داخلی در ثبت عملیات مالی.']);
}
