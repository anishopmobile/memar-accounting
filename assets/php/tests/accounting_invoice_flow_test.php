<?php
/**
 * End-to-end invoice/advance integration; CI disposable MySQL only.
 * Intentionally runs real committed invoice service transactions.
 */
declare(strict_types=1);
if (getenv('CI') !== 'true') {fwrite(STDERR,"CI-only test; do not run on production\n");exit(2);}
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/accounting_ledger.php';
function ensure_flow(bool $condition,string $message): void {
 if(!$condition)throw new RuntimeException('FAIL: '.$message);echo 'PASS: '.$message."\n";
}
$nonce=bin2hex(random_bytes(5));
$mysqli->query("INSERT INTO customers (first_name,last_name,phone) VALUES ('Accounting','Flow','0')");
$c=(int)$mysqli->insert_id;
$code='FLOW-'.$nonce;
$st=$mysqli->prepare("INSERT INTO products (code,name,type,unit,stock,purchase_price,sale_price) VALUES (?, 'Accounting flow product','product','عدد',100,0,0)");
$st->bind_param('s',$code);$st->execute();$p=(int)$st->insert_id;$st->close();
$st=$mysqli->prepare("INSERT INTO product_units (product_id,name,conversion_factor,is_base,purchase_price,sale_price,sort_order) VALUES (?,'عدد',1,1,0,0,0)");
$st->bind_param('i',$p);$st->execute();$unit=(int)$st->insert_id;$st->close();
$items=[['product_id'=>$p,'unit_id'=>$unit,'quantity'=>'1','unit_price'=>'5000000','discount'=>'0']];
$base=['party_id'=>$c,'payment_type'=>'pos','payment_status'=>'paid','invoice_date'=>date('Y-m-d'),'discount'=>'0','tax_rate'=>null,'items'=>$items];
$invoice=createInvoice($mysqli,$base+[
  'type'=>'sales_invoice','client_token'=>'acct-flow-'.$nonce,
  'initial_payments'=>[
    ['amount'=>'2000000','method'=>'cash'],
    ['amount'=>'1000000','method'=>'pos']
  ]
]);
ensure_flow($invoice['ok'],'multi-method sale created: '.($invoice['message']??''));
$invoiceId=(int)$invoice['invoice_id'];
$fin=ledger_invoice_balance($mysqli,$invoiceId);
ensure_flow(money_cmp($fin['balance'],'2000000')===0,'multi-method settled 3m and due 2m');
ensure_flow($fin['invoice']['payment_status']==='partial','derived invoice partial status');
$st=$mysqli->prepare('SELECT COUNT(*) c FROM accounting_allocations WHERE invoice_id=?');
$st->bind_param('i',$invoiceId);$st->execute();$count=(int)$st->get_result()->fetch_assoc()['c'];$st->close();
ensure_flow($count===2,'two independent receipts allocated to one invoice');
$advanceInvoice=createInvoice($mysqli,$base+[
  'type'=>'sales_proforma','client_token'=>'acct-advance-'.$nonce,
  'initial_payments'=>[]
]);
ensure_flow($advanceInvoice['ok'],'proforma created');
$proId=(int)$advanceInvoice['invoice_id'];
$advance=ledger_post($mysqli,[
 'party_kind'=>'customer','party_id'=>$c,'direction'=>'in','amount'=>'6000000',
 'method'=>'bank_transfer','source_proforma_id'=>$proId,'request_key'=>'acct-advance-pay-'.$nonce
]);
ensure_flow((int)$advance['receipt_id']>0,'advance linked to source proforma');
$before=ledger_party_balance($mysqli,'customer',$c);
ensure_flow(money_cmp($before['credit'],'4000000')===0,'proforma advance increases credit without creating debt');
$conversion=convertInvoice($mysqli,$proId);
ensure_flow($conversion['ok'],'proforma conversion succeeded: '.($conversion['message']??''));
$after=ledger_invoice_balance($mysqli,$proId);
ensure_flow(money_cmp($after['balance'],'0')===0,'advance automatically settled converted invoice');
$cash=ledger_party_balance($mysqli,'customer',$c);
ensure_flow(money_cmp($cash['debt'],'1000000')===0,'account balances net original invoice debt and remaining advance');
$st=$mysqli->prepare('SELECT COALESCE(SUM(amount),0) amount FROM accounting_allocations WHERE receipt_id=?');
$advanceId=(int)$advance['receipt_id'];$st->bind_param('i',$advanceId);$st->execute();$used=$st->get_result()->fetch_assoc()['amount'];$st->close();
ensure_flow(money_cmp($used,'5000000')===0,'linked prepayment allocation capped at invoice total');
ensure_flow(money_cmp(money_sub('6000000',$used),'1000000')===0,'unused 1m advance retained for later allocation');
echo "PASS all end-to-end invoice accounting checks\n";
