<?php
/** Run ONLY on disposable MySQL; production safety guard prevents writes. */
declare(strict_types=1);
if (getenv('GITHUB_ACTIONS') !== 'true') { fwrite(STDERR,"Refusing destructive accounting integration test outside CI.\n"); exit(2); }
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/accounting_ledger.php';
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
echo "PASS accounting integration suite\n";
