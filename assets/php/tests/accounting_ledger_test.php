<?php
/**
 * Isolated MySQL ledger regression; only run against disposable local CI DB.
 * Requires mysql root without password, matches other test fixtures.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/accounting_ledger.php';
function testok(bool $yes,string $msg): void {if(!$yes)throw new RuntimeException('FAIL '.$msg); echo "PASS $msg\n";}
function eqmoney(string $a,string $b,string $msg): void{testok(money_cmp($a,$b)===0,$msg." ($a vs $b)");}
$mysqli->begin_transaction();
try {
    $nonce=bin2hex(random_bytes(6));
    $mysqli->query("INSERT INTO customers (first_name,last_name,phone) VALUES ('Ledger','Test','123000')");
    $customer=(int)$mysqli->insert_id;
    $mysqli->query("INSERT INTO suppliers (company_name,phone) VALUES ('Ledger Supplier','123001')");
    $supplier=(int)$mysqli->insert_id;
    $now=date('Y-m-d');
    $st=$mysqli->prepare("INSERT INTO invoices (invoice_number,type,customer_id,supplier_id,invoice_date,payable_amount,payment_status) VALUES (?,?,?,?,?,?,'unpaid')");
    $name='C-'.$nonce;$type='sales_invoice';$cid=$customer;$sid=null;$total='5000000';
    $st->bind_param('ssiiss',$name,$type,$cid,$sid,$now,$total);
    $st->execute();$sales=(int)$mysqli->insert_id;
    $name='P-'.$nonce;$type='purchase_invoice';$cid=null;$sid=$supplier;$total='15000000';
    $st->execute();$purchase=(int)$mysqli->insert_id;
    $st->close();
    $payment=ledger_post_in_tx($mysqli,[
      'party_kind'=>'customer','party_id'=>$customer,'direction'=>'in','amount'=>'3000000',
      'method'=>'pos','payment_date'=>$now,'request_key'=>'test-'.$nonce.'-sales',
      'allocations'=>[['invoice_id'=>$sales,'amount'=>'3000000']]
    ]);
    $withoutKey=ledger_post_in_tx($mysqli,[
      'party_kind'=>'supplier','party_id'=>$supplier,'direction'=>'out','amount'=>'100000',
      'method'=>'cash','payment_date'=>$now,'allocations'=>[]
    ]);
    $withoutKeyAgain=ledger_post_in_tx($mysqli,[
      'party_kind'=>'supplier','party_id'=>$supplier,'direction'=>'out','amount'=>'200000',
      'method'=>'pos','payment_date'=>$now,'allocations'=>[]
    ]);
    testok($withoutKey['receipt_id']!==$withoutKeyAgain['receipt_id'],'multiple receipts without optional idempotency key are allowed');
        ledger_reverse_receipt($mysqli,(int)$withoutKey['receipt_id'],'CI cleanup reverses cash',true);
    ledger_reverse_receipt($mysqli,(int)$withoutKeyAgain['receipt_id'],'CI cleanup reverses POS',true);
        eqmoney(ledger_invoice_balance($mysqli,$sales)['balance'],'2000000','partially paid invoice has real remainder');
    testok(ledger_invoice_balance($mysqli,$sales)['invoice']['payment_status']==='partial','status derives from settlements');
    eqmoney(ledger_party_balance($mysqli,'customer',$customer)['debt'],'2000000','customer debt reflects posted receipts');
    $same=ledger_post_in_tx($mysqli,[
      'party_kind'=>'customer','party_id'=>$customer,'direction'=>'in','amount'=>'3000000',
      'method'=>'pos','payment_date'=>$now,'request_key'=>'test-'.$nonce.'-sales',
      'allocations'=>[['invoice_id'=>$sales,'amount'=>'3000000']]
    ]);
    testok($same['duplicate'] && $same['receipt_id']===$payment['receipt_id'],'idempotent retry');
    $reject=false;
    try{ledger_post_in_tx($mysqli,[
      'party_kind'=>'customer','party_id'=>$customer,'direction'=>'in','amount'=>'3000000',
      'method'=>'cash','allocations'=>[['invoice_id'=>$sales,'amount'=>'3000000']]
    ]);}catch(InvalidArgumentException $e){$reject=true;}
    testok($reject,'cannot overallocate outstanding invoice');
    $extra=ledger_post_in_tx($mysqli,[
      'party_kind'=>'customer','party_id'=>$customer,'direction'=>'in','amount'=>'3000000',
      'method'=>'cash','payment_date'=>$now,'request_key'=>'test-'.$nonce.'-excess',
      'allocations'=>[['invoice_id'=>$sales,'amount'=>'2000000']]
    ]);
    eqmoney(ledger_party_balance($mysqli,'customer',$customer)['credit'],'1000000','overpayment retained as party credit');
    testok(ledger_invoice_balance($mysqli,$sales)['invoice']['payment_status']==='paid','invoice reaches paid');
    $reversal=ledger_reverse_receipt($mysqli,(int)$payment['receipt_id'],'Payment entered incorrectly',true);
    testok($reversal>0,'financial reversal has independent immutable audit ID');
    eqmoney(ledger_invoice_balance($mysqli,$sales)['balance'],'3000000','reversal reopens invoice balance');
    eqmoney(ledger_party_balance($mysqli,'customer',$customer)['debt'],'2000000','reversed cash no longer counts as receipt');
    ledger_allocate_existing($mysqli,(int)$extra['receipt_id'],$sales,'1000000',true);
    eqmoney(ledger_invoice_balance($mysqli,$sales)['balance'],'2000000','unallocated credit can settle another part of invoice');
    $reject=false;
    try{ledger_reverse_receipt($mysqli,(int)$payment['receipt_id'],'Repeated reversal',true);}catch(InvalidArgumentException $e){$reject=true;}
    testok($reject,'same payment cannot be reversed twice');
    $st=$mysqli->prepare("INSERT INTO accounting_openings (party_kind,party_id,opening_amount) VALUES ('supplier',?,'70000000')");
    $st->bind_param('i',$supplier);$st->execute();$st->close();
    $p=ledger_post_in_tx($mysqli,[
      'party_kind'=>'supplier','party_id'=>$supplier,'direction'=>'out','amount'=>'35000000',
      'method'=>'bank_transfer','payment_date'=>$now,'request_key'=>'test-'.$nonce.'-supplier',
      'allocations'=>[['invoice_id'=>$purchase,'amount'=>'15000000']]
    ]);
    eqmoney($p['unallocated'],'20000000','extra supplier payment remains unapplied');
    eqmoney(ledger_invoice_balance($mysqli,$purchase)['balance'],'0','new supplier invoice fully settled');
    eqmoney(ledger_party_balance($mysqli,'supplier',$supplier)['debt'],'50000000','supplier opening and current invoice net correctly');
    testok(ledger_invoice_balance($mysqli,$purchase)['invoice']['payment_status']==='paid','supplier payment status derived');
    echo "PASS all accounting ledger integration checks\n";
}finally{$mysqli->rollback();}
