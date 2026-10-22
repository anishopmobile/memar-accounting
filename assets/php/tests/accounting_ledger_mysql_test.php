<?php
/** Disposable MySQL integration test. Run ONLY on isolated CI database. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('CI')!=='true') {
    fwrite(STDERR,"Run with CI=true on an EMPTY disposable MySQL service only.\n"); exit(2);
}
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/ledger/ledger_service.php';
function ck(bool $yes,string $name): void {
    if(!$yes) throw new RuntimeException('FAIL '.$name);
    echo "PASS ".$name."\n";
}
function addParty(mysqli $db,string $kind): int {
    if($kind==='customer'){
        $db->query("INSERT INTO customers (first_name,last_name,phone) VALUES ('Ledger','Sandbox','000')");
    }else{
        $db->query("INSERT INTO suppliers (company_name,phone) VALUES ('Ledger Sandbox','000')");
    }
    return (int)$db->insert_id;
}
function addInvoice(mysqli $db,string $kind,int $pid,string $total): int {
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $fk=$kind==='customer'?'customer_id':'supplier_id';
    $number='LEDGER-CI-'.bin2hex(random_bytes(7));
    $sql="INSERT INTO invoices (invoice_number,type,$fk,invoice_date,payment_status,payable_amount) VALUES (?,?,?,CURDATE(),'unpaid',?)";
    $s=$db->prepare($sql);$s->bind_param('ssis',$number,$type,$pid,$total);$s->execute();$id=(int)$db->insert_id;$s->close();
    return $id;
}
function makePayment(mysqli $db,string $kind,int $pid,string $amount,array $allocations=[]): array {
    return ledger_record_payment($db,[
        'party_kind'=>$kind,'party_id'=>$pid,'method'=>'bank_transfer',
        'paid_on'=>date('Y-m-d'),'reference_no'=>'CI','note'=>'Integration test',
        'request_token'=>bin2hex(random_bytes(22)),
        'amount'=>$amount,'allocations'=>$allocations
    ]);
}
$customer=addParty($mysqli,'customer');
$sale=addInvoice($mysqli,'customer',$customer,'5000000');
ck(ledger_invoice_balance($mysqli,$sale)['status']==='unpaid','new invoice unpaid');
$r=makePayment($mysqli,'customer',$customer,'3000000',[['invoice_id'=>$sale,'amount'=>'3000000']]);
ck(!$r['duplicate'],'receipt inserted');
$b=ledger_invoice_balance($mysqli,$sale);
ck($b['paid']==='3000000.00'&&$b['due']==='2000000.00'&&$b['status']==='partial','3m receipt leaves 2m');
makePayment($mysqli,'customer',$customer,'2000000',[['invoice_id'=>$sale,'amount'=>'2000000']]);
ck(ledger_invoice_balance($mysqli,$sale)['status']==='paid','later receipt settles');
ck(ledger_party_summary($mysqli,'customer',$customer)['debt']==='0.00','customer balance zero');
$supplier=addParty($mysqli,'supplier');
ledger_record_opening($mysqli,'supplier',$supplier,'70000000','2026-01-01','Approved legacy supplier opening balance');
$purchase=addInvoice($mysqli,'supplier',$supplier,'15000000');
makePayment($mysqli,'supplier',$supplier,'35000000',[['invoice_id'=>$purchase,'amount'=>'15000000']]);
ck(ledger_invoice_balance($mysqli,$purchase)['status']==='paid','purchase fully paid');
ck(ledger_party_summary($mysqli,'supplier',$supplier)['debt']==='50000000.00','opening 70m + purchase 15m - payment 35m = 50m');
$otherSupplier=addParty($mysqli,'supplier');
$purchase2=addInvoice($mysqli,'supplier',$otherSupplier,'15000000');
makePayment($mysqli,'supplier',$otherSupplier,'10000000',[['invoice_id'=>$purchase2,'amount'=>'10000000']]);
ck(ledger_invoice_balance($mysqli,$purchase2)['due']==='5000000.00','purchase partial 10/15m');
$creditSupplier=addParty($mysqli,'supplier');
$purchase3=addInvoice($mysqli,'supplier',$creditSupplier,'15000000');
$payment=makePayment($mysqli,'supplier',$creditSupplier,'20000000',[['invoice_id'=>$purchase3,'amount'=>'15000000']]);
ck($payment['unallocated']==='5000000.00','excess 5m unapplied');
ck(ledger_party_summary($mysqli,'supplier',$creditSupplier)['credit']==='5000000.00','supplier credit 5m');
$purchase4=addInvoice($mysqli,'supplier',$creditSupplier,'4000000');
$applied=ledger_apply_credit($mysqli,'supplier',$creditSupplier,(int)$payment['payment_id'],$purchase4,'4000000',bin2hex(random_bytes(20)));
ck(!$applied['duplicate']&&ledger_invoice_balance($mysqli,$purchase4)['status']==='paid','credit pays another invoice');
ck(ledger_party_summary($mysqli,'supplier',$creditSupplier)['credit']==='1000000.00','1m remaining credit');
try {ledger_apply_credit($mysqli,'supplier',$creditSupplier,(int)$payment['payment_id'],$purchase2,'100',bin2hex(random_bytes(20)));throw new RuntimeException('ownership rejected failed');}
catch(DomainException $e){ck(true,'reject cross party allocations');}
try {makePayment($mysqli,'customer',$customer,'1',[['invoice_id'=>$sale,'amount'=>'1']]);throw new RuntimeException('overpay should fail');}
catch(DomainException $e){ck(true,'reject allocation over outstanding');}
$legacy=addInvoice($mysqli,'customer',$customer,'10000');
$s=$mysqli->prepare("INSERT INTO ledger_legacy_invoices (invoice_id,recognized_paid,classification) VALUES (?,0,'requires_reconciliation')");
$s->bind_param('i',$legacy);$s->execute();$s->close();
try {makePayment($mysqli,'customer',$customer,'100',[['invoice_id'=>$legacy,'amount'=>'100']]);throw new RuntimeException('missing reconciliation check');}
catch(DomainException $e){ck(true,'unknown historical partial is blocked');}
ledger_reconcile_partial($mysqli,$legacy,'2500','Historical bank deposit confirmed by source record');
ck(ledger_invoice_balance($mysqli,$legacy)['due']==='7500.00','historical partial reconciliation');
echo "ALL ACCOUNTING LEDGER MYSQL INTEGRATION CHECKS PASSED\n";
