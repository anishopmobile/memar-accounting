<?php
/** Run ONLY on disposable MySQL; production safety guard prevents writes. */
declare(strict_types=1);
if (getenv('GITHUB_ACTIONS') !== 'true') { fwrite(STDERR,"Refusing destructive accounting integration test outside CI.\n"); exit(2); }
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/accounting_ledger.php';
require_once dirname(__DIR__) . '/accounting_payment_batch.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function check_acct(bool $ok,string $why): void {
    if(!$ok) throw new RuntimeException($why);
    echo "PASS ".$why."\n";
}
function a_customer(mysqli $db): int {
    $v='acct-'.bin2hex(random_bytes(4));
    $st=$db->prepare("INSERT INTO customers(first_name,last_name,phone) VALUES(?,'Test','000')");
    $st->bind_param('s',$v);$st->execute();$i=(int)$st->insert_id;$st->close();return $i;
}
function a_supplier(mysqli $db): int {
    $v='acct-'.bin2hex(random_bytes(4));
    $st=$db->prepare("INSERT INTO suppliers(company_name,phone) VALUES(?,'000')");
    $st->bind_param('s',$v);$st->execute();$i=(int)$st->insert_id;$st->close();return $i;
}
function a_invoice(mysqli $db,string $kind,int $party,string $amount,?string $legacy='0'): int {
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $num='CI-LEDGER-'.bin2hex(random_bytes(6));
    if($kind==='customer') {
        $st=$db->prepare("INSERT INTO invoices(invoice_number,type,customer_id,invoice_date,payable_amount,legacy_paid_amount) VALUES(?,?,?,CURDATE(),?,?)");
    } else {
        $st=$db->prepare("INSERT INTO invoices(invoice_number,type,supplier_id,invoice_date,payable_amount,legacy_paid_amount) VALUES(?,?,?,CURDATE(),?,?)");
    }
    $st->bind_param('ssiss',$num,$type,$party,$amount,$legacy);
    $st->execute();$i=(int)$st->insert_id;$st->close();return $i;
}
function pay_acct(mysqli $db,string $kind,int $party,string $amount,array $items): array {
    return acct_record_payment($db,[
        'party_kind'=>$kind,'party_id'=>$party,
        'direction'=>$kind==='customer'?'in':'out',
        'amount'=>$amount,'method'=>'bank_transfer','payment_date'=>date('Y-m-d'),
        'client_token'=>bin2hex(random_bytes(16)),'allocations'=>$items
    ]);
}
$c=a_customer($mysqli);$s=a_supplier($mysqli);
$i=a_invoice($mysqli,'customer',$c,'5000000');
$first=pay_acct($mysqli,'customer',$c,'3000000',[['invoice_id'=>$i,'amount'=>'3000000']]);
check_acct(acct_invoice_outstanding($mysqli,$i)['remaining']==='2000000.00','5m invoice with 3m receipt => 2m outstanding');
check_acct(acct_invoice_outstanding($mysqli,$i)['invoice']['payment_status']==='partial','payment status derived partial');
pay_acct($mysqli,'customer',$c,'2000000',[['invoice_id'=>$i,'amount'=>'2000000']]);
check_acct(acct_invoice_outstanding($mysqli,$i)['remaining']==='0.00','second receipt settles invoice');
check_acct(acct_invoice_outstanding($mysqli,$i)['invoice']['payment_status']==='paid','payment status derived paid');
$blocked=false;
try {pay_acct($mysqli,'customer',$c,'1000',[['invoice_id'=>$i,'amount'=>'1000']]);} catch (InvalidArgumentException $e) {$blocked=true;}
check_acct($blocked,'over-allocation rejected');
$sInvoice=a_invoice($mysqli,'supplier',$s,'15000000');
$st=$mysqli->prepare("INSERT INTO account_opening_balances(party_kind,party_id,amount) VALUES('supplier',?,'70000000')");
$st->bind_param('i',$s);$st->execute();$st->close();
$payment=pay_acct($mysqli,'supplier',$s,'35000000',[['invoice_id'=>$sInvoice,'amount'=>'15000000']]);
$state=acct_party_statement($mysqli,'supplier',$s);
check_acct($state['balance']==='50000000.00','70m previous debt + 15m invoice - 35m remittance = 50m');
check_acct($state['unallocated_credit']==='20000000.00','20m excess payment held as explicit credit');
$sInvoice2=a_invoice($mysqli,'supplier',$s,'15000000');
acct_apply_credit($mysqli,$payment['payment_id'],$sInvoice2,'10000000');
check_acct(acct_invoice_outstanding($mysqli,$sInvoice2)['remaining']==='5000000.00','credit applied to later invoice');
$other=a_supplier($mysqli);$otherInv=a_invoice($mysqli,'supplier',$other,'1000');
$blocked=false;
try {pay_acct($mysqli,'supplier',$s,'1000',[['invoice_id'=>$otherInv,'amount'=>'1000']]);} catch (InvalidArgumentException $e) {$blocked=true;}
check_acct($blocked,'cross-party payment rejected');
$legacy=a_invoice($mysqli,'customer',$c,'100000',null);
check_acct(acct_party_statement($mysqli,'customer',$c)['balance_final']===false,'unknown historical partial is reported without false certainty');
$st=$mysqli->prepare("UPDATE invoices SET legacy_paid_amount='40000' WHERE id=?");
$st->bind_param('i',$legacy);$st->execute();$st->close();
acct_update_status($mysqli,$legacy);
check_acct(acct_invoice_outstanding($mysqli,$legacy)['remaining']==='60000.00','historic payment reconciliation works');
// Return credit is not cash, but may settle an invoice.
$c2=a_customer($mysqli);
$original=a_invoice($mysqli,'customer',$c2,'300000');
$returnNumber='CI-RET-'.bin2hex(random_bytes(5));
$stmt=$mysqli->prepare("INSERT INTO sales_returns(return_number,invoice_id,customer_id,return_date,payable_amount) VALUES(?,?,?,CURDATE(),'120000')");
$stmt->bind_param('sii',$returnNumber,$original,$c2);$stmt->execute();$returnId=(int)$stmt->insert_id;$stmt->close();
acct_allocate_return_credit($mysqli,$returnId,$original,'120000');
check_acct(acct_invoice_outstanding($mysqli,$original)['remaining']==='180000.00','120k return credit allocated without pretending cash receipt');
check_acct(acct_party_statement($mysqli,'customer',$c2)['return_credit']==='0.00','allocated return is no longer free credit');

$common=[
 'party_kind'=>'customer','party_id'=>$c2,'direction'=>'in','amount'=>'200000','method'=>'pos',
 'payment_date'=>date('Y-m-d'),'client_token'=>bin2hex(random_bytes(16)),
 'allocations'=>[['invoice_id'=>$original,'amount'=>'180000']]
];
$once=acct_record_payment($mysqli,$common);
$twice=acct_record_payment($mysqli,$common);
check_acct($twice['duplicate']===true && $once['payment_id']===$twice['payment_id'],'identical retry is idempotent');
$conflict=$common;$conflict['amount']='201000';$blocked=false;
try {acct_record_payment($mysqli,$conflict);}catch(InvalidArgumentException $e){$blocked=true;}
check_acct($blocked,'conflicting retry is rejected');
$statement=acct_party_statement($mysqli,'customer',$c2);
check_acct($statement['balance']==='-20000.00','20k unallocated customer credit accurately reported');
$refund=acct_record_refund($mysqli,[
 'party_kind'=>'customer','party_id'=>$c2,'amount'=>'10000','method'=>'cash',
 'client_token'=>bin2hex(random_bytes(16))
]);
check_acct(acct_party_statement($mysqli,'customer',$c2)['balance']==='-10000.00','customer refund reduces available credit');
acct_reverse_payment($mysqli,$refund['payment_id'],'اشتباه در استرداد',bin2hex(random_bytes(16)));
check_acct(acct_party_statement($mysqli,'customer',$c2)['balance']==='-20000.00','refund reversal is auditable');
acct_reverse_payment($mysqli,$once['payment_id'],'اصلاح دریافت اشتباه',bin2hex(random_bytes(16)));
check_acct(acct_invoice_outstanding($mysqli,$original)['remaining']==='180000.00','receipt reversal restores invoice outstanding without deleting journal entries');
check_acct(acct_party_statement($mysqli,'customer',$c2)['balance']==='180000.00','receipt and reversal fully cancel net cash');
$blocked=false;
try {acct_reverse_payment($mysqli,$once['payment_id'],'ابطال مجدد اشتباه',bin2hex(random_bytes(16)));}catch(InvalidArgumentException $e){$blocked=true;}
check_acct(!$blocked,'repeated reversal is idempotently recognized');

// Atomic multi-method settlement: split 200k into cash 50k and POS 150k.
$batchCustomer=a_customer($mysqli);
$batchInvoice=a_invoice($mysqli,'customer',$batchCustomer,'220000');
$batch=[
 'party_kind'=>'customer','party_id'=>$batchCustomer,
 'client_token'=>bin2hex(random_bytes(20)),
 'payment_date'=>date('Y-m-d'),
 'payments'=>[['method'=>'cash','amount'=>'50000'],['method'=>'pos','amount'=>'150000']],
 'allocations'=>[['invoice_id'=>$batchInvoice,'amount'=>'200000']]
];
$resultBatch=acct_record_payment_batch($mysqli,$batch);
check_acct(count($resultBatch['payment_ids'])===2,'mixed receipt writes two independently auditable payments');
check_acct(acct_invoice_outstanding($mysqli,$batchInvoice)['remaining']==='20000.00','combined cash and POS settle 200k of 220k');
$retryBatch=acct_record_payment_batch($mysqli,$batch);
check_acct($retryBatch['duplicate']===true && $retryBatch['payment_ids']===$resultBatch['payment_ids'],'whole mixed-method batch retries idempotently');
$conflictBatch=$batch;$conflictBatch['payments'][1]['amount']='160000';
$blocked=false;
try{acct_record_payment_batch($mysqli,$conflictBatch);}catch(InvalidArgumentException $e){$blocked=true;}
check_acct($blocked,'conflicting batch replay rejected and unchanged');
$invalidBatch=$batch;
$invalidBatch['client_token']=bin2hex(random_bytes(20));
$invalidBatch['allocations']=[['invoice_id'=>$batchInvoice,'amount'=>'150000']];
$blocked=false;
try{acct_record_payment_batch($mysqli,$invalidBatch);}catch(InvalidArgumentException $e){$blocked=true;}
check_acct($blocked,'batch over-allocation rolls back all payment methods');
$st=$mysqli->prepare("SELECT COUNT(*) AS c FROM account_payments WHERE party_kind='customer' AND party_id=?");
$st->bind_param('i',$batchCustomer);$st->execute();$num=(int)$st->get_result()->fetch_assoc()['c'];$st->close();
check_acct($num===2,'failed mixed-method action did not leave orphan cash transaction');

// Historical proforma advances are linked to proforma until conversion.
$advanceCustomer=a_customer($mysqli);
$proformaNumber='CI-PRO-'.bin2hex(random_bytes(6));
$stmt=$mysqli->prepare("INSERT INTO invoices(invoice_number,type,customer_id,invoice_date,payable_amount,legacy_paid_amount) VALUES(?,'sales_proforma',?,CURDATE(),'900000',0)");
$stmt->bind_param('si',$proformaNumber,$advanceCustomer);$stmt->execute();$proformaId=(int)$stmt->insert_id;$stmt->close();
$advanceBatch=[
 'party_kind'=>'customer','party_id'=>$advanceCustomer,
 'source_proforma_id'=>$proformaId,'client_token'=>bin2hex(random_bytes(20)),
 'payments'=>[['method'=>'bank_transfer','amount'=>'200000']],
 'allocations'=>[]
];
$advance=acct_record_payment_batch($mysqli,$advanceBatch);
$st=$mysqli->prepare("SELECT source_proforma_id FROM account_payments WHERE id=?");
$paymentId=$advance['payment_ids'][0];$st->bind_param('i',$paymentId);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
check_acct((int)$row['source_proforma_id']===$proformaId,'advance retains verifiable originating proforma');
check_acct(acct_party_statement($mysqli,'customer',$advanceCustomer)['balance']==='-200000.00','proforma receipt is credit rather than immediate sale');
$st=$mysqli->prepare("UPDATE invoices SET type='sales_invoice' WHERE id=?");
$st->bind_param('i',$proformaId);$st->execute();$st->close();
check_acct(acct_record_payment_batch($mysqli,$advanceBatch)['duplicate']===true,'converted proforma does not break retry idempotency');
acct_apply_credit($mysqli,$paymentId,$proformaId,'200000');
check_acct(acct_invoice_outstanding($mysqli,$proformaId)['remaining']==='700000.00','advance credit can be allocated to converted invoice');

echo "PASS accounting integration suite\n";
