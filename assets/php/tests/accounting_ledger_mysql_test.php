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
$refund=ledger_record_payment($mysqli,[
  'party_kind'=>'supplier','party_id'=>$creditSupplier,'direction'=>'receipt',
  'method'=>'bank_transfer','paid_on'=>date('Y-m-d'),'reference_no'=>'REF-CI',
  'note'=>'Confirmed supplier return of unapplied money','amount'=>'1000000',
  'request_token'=>bin2hex(random_bytes(20)),'allocations'=>[]
]);
ck($refund['unallocated']==='0.00','real refund is not reusable invoice credit');
ck(ledger_party_summary($mysqli,'supplier',$creditSupplier)['credit']==='0.00','supplier refund extinguishes 1m credit');
try {
  ledger_record_payment($mysqli,[
    'party_kind'=>'supplier','party_id'=>$creditSupplier,'direction'=>'receipt','method'=>'cash',
    'paid_on'=>date('Y-m-d'),'reference_no'=>'INVALID','note'=>'Invalid excessive refund',
    'amount'=>'1','request_token'=>bin2hex(random_bytes(20)),'allocations'=>[]
  ]);
  throw new RuntimeException('excess refund should fail');
} catch(DomainException $e) {ck(true,'over-refund denied');}
// Atomic invoice + initial payments: payment validation failure must roll back the invoice too.
$code='LEDGER-PRODUCT-'.bin2hex(random_bytes(5));
$s=$mysqli->prepare("INSERT INTO products(code,name,type,stock,sale_price,purchase_price) VALUES(?,'Accounting test product','product',100,'5000000','15000000')");
$s->bind_param('s',$code);$s->execute();$productId=(int)$mysqli->insert_id;$s->close();
$unitId='';
$baseData=[
  'party_id'=>$customer,'payment_type'=>'pos','payment_status'=>'unpaid',
  'invoice_date'=>date('Y-m-d'),'discount'=>'0','note'=>'atomic integration',
  'client_token'=>bin2hex(random_bytes(20)),
  'items'=>[['product_id'=>$productId,'unit_id'=>$unitId,'quantity'=>'1','unit_price'=>'5000000','discount'=>'0']]
];
$atomic=$baseData;
$atomic['type']='sales_invoice';
$atomic['initial_payments']=[[
  'amount'=>'3000000','method'=>'pos','paid_on'=>date('Y-m-d'),
  'reference_no'=>'CI-ATOMIC','note'=>'', 'allocations'=>[]
]];
$created=createInvoice($mysqli,$atomic);
ck($created['ok'],'atomic invoice plus payment creation');
$atomicInvoice=(int)$created['invoice_id'];
ck(ledger_invoice_balance($mysqli,$atomicInvoice)['due']==='2000000.00','invoice initial payment reflected');
$again=createInvoice($mysqli,$atomic);
ck($again['ok']&&(int)$again['invoice_id']===$atomicInvoice,'invoice idempotent replay retains same payment');
$s=$mysqli->prepare('SELECT COUNT(*) n FROM ledger_allocations WHERE invoice_id=?');
$s->bind_param('i',$atomicInvoice);$s->execute();$count=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
ck($count===1,'idempotent invoice did not double charge');
$blockedEdit=editInvoice($mysqli,$atomicInvoice,$atomic);
ck(!$blockedEdit['ok'],'financially posted invoice editing is blocked');
$blockedDelete=deleteInvoice($mysqli,$atomicInvoice);
ck(!$blockedDelete['ok'],'financially posted invoice cannot be deleted');
$failed=$baseData;$failed['type']='sales_invoice';$failed['client_token']=bin2hex(random_bytes(20));
$failed['initial_payments']=[['amount'=>'200','method'=>'invalid','paid_on'=>date('Y-m-d')]];
$rejected=createInvoice($mysqli,$failed);
ck(!$rejected['ok'],'invalid initial method rolled back invoice');
$s=$mysqli->prepare('SELECT COUNT(*) n FROM invoices WHERE client_token=?');
$s->bind_param('s',$failed['client_token']);$s->execute();$n=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
ck($n===0,'invalid initial payment left no half-saved invoice');
$pro=$baseData;$pro['type']='purchase_proforma';$pro['party_id']=$supplier;
$pro['client_token']=bin2hex(random_bytes(20));
$pro['items']=[['product_id'=>$productId,'unit_id'=>$unitId,'quantity'=>'1','unit_price'=>'15000000','discount'=>'0']];
$pro['initial_payments']=[['amount'=>'5000000','method'=>'bank_transfer','paid_on'=>date('Y-m-d'),'reference_no'=>'PR-DEP','note'=>'']];
$proMade=createInvoice($mysqli,$pro);
ck($proMade['ok'],'purchase proforma accepts tracked deposit');
$proId=(int)$proMade['invoice_id'];
$s=$mysqli->prepare('SELECT COUNT(*) n FROM ledger_proforma_deposits WHERE proforma_invoice_id=?');
$s->bind_param('i',$proId);$s->execute();$n=(int)$s->get_result()->fetch_assoc()['n'];$s->close();
ck($n===1,'proforma deposit link exists before conversion');
$converted=convertInvoice($mysqli,$proId);
ck($converted['ok'],'proforma conversion successful');
ck(ledger_invoice_balance($mysqli,$proId)['due']==='10000000.00','deposit applied to converted purchase invoice');
// One source of truth across party pages, exports and dashboard.
require_once dirname(__DIR__).'/ledger/ledger_reports.php';
$customers=ledger_parties_report($mysqli,'customer');
$ourCustomer=array_values(array_filter($customers,fn($row)=>(int)$row['id']===$customer))[0]??null;
ck($ourCustomer!==null,'ledger customer appears in account reports');
ck((string)$ourCustomer['debt']===ledger_party_summary($mysqli,'customer',$customer)['debt'],'customer reports equal ledger balance');
ck((int)$ourCustomer['requires_reconciliation']===0,'reconciled old partial invoice not falsely uncertain');
$suppliers=ledger_parties_report($mysqli,'supplier');
$ourSupplier=array_values(array_filter($suppliers,fn($row)=>(int)$row['id']===$supplier))[0]??null;
ck($ourSupplier!==null,'ledger supplier appears in supplier reports');
ck((string)$ourSupplier['unpaid']==='60000000.00','supplier report reflects existing opening and new 15m proforma conversion minus 5m deposit');
$overall=ledger_outstanding_for_period($mysqli,date('Y-m-d'),date('Y-m-d'));
ck(money_cmp($overall,'0')>=0,'dashboard outstanding query returns nonnegative decimal');
$voidParty=addParty($mysqli,'customer');
$voidInvoice=addInvoice($mysqli,'customer',$voidParty,'500');
$voidPayment=makePayment($mysqli,'customer',$voidParty,'200',[['invoice_id'=>$voidInvoice,'amount'=>'200']]);
ck(ledger_invoice_balance($mysqli,$voidInvoice)['due']==='300.00','payment before void');
$voided=ledger_void_payment($mysqli,'customer',$voidParty,(int)$voidPayment['payment_id'],'Confirmed mistaken duplicate till receipt');
ck($voided['voided_payment_id']===$voidPayment['payment_id'],'void leaves transaction with audit history');
ck(ledger_invoice_balance($mysqli,$voidInvoice)['due']==='500.00','void restores invoice due');
$now=$mysqli->query('SELECT void_reason FROM ledger_payments WHERE id='.(int)$voidPayment['payment_id'])->fetch_assoc();
ck(str_contains($now['void_reason'],'mistaken'),'documented void explanation survives');
try{ledger_void_payment($mysqli,'customer',$voidParty,(int)$voidPayment['payment_id'],'Attempt to void same payment a second time');throw new RuntimeException('double void accepted');}
catch(DomainException $e){ck(true,'duplicate void rejected');}
// Two independent PHP requests race to allocate 80 each to a 100 invoice.
$raceParty=addParty($mysqli,'customer');
$raceInvoice=addInvoice($mysqli,'customer',$raceParty,'100.00');
$worker=__DIR__.'/accounting_ledger_race_worker.php';
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' customer '.(int)$raceParty.' '.(int)$raceInvoice;
$spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$children=[];
for($i=0;$i<2;$i++){
    $proc=proc_open($cmd,$spec,$pipes,null,['CI'=>'true']);
    if(!is_resource($proc))throw new RuntimeException('Unable to run concurrent PHP test.');
    fclose($pipes[0]);
    $children[]=[$proc,$pipes];
}
$statuses=[];
foreach($children as [$proc,$pipes]) {
    $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $code=proc_close($proc);
    $statuses[]=$code;
    echo 'RACE CHILD status='.$code.' '.trim($stdout).' '.trim($stderr)."\n";
}
sort($statuses);
ck($statuses===[0,3],'concurrent settlement permits exactly one of two 80/100 transactions');
ck(ledger_invoice_balance($mysqli,$raceInvoice)['due']==='20.00','concurrency preserves invoice 20 balance');
echo "ALL ACCOUNTING LEDGER MYSQL INTEGRATION CHECKS PASSED\n";
