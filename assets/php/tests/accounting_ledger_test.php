<?php
/** Disposable MySQL accounting integration tests, never run on a production database. */
declare(strict_types=1);
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/invoice_common.php';
require_once dirname(__DIR__).'/auth.php';

function test_eq($actual,$expected,string $label): void {
    if ((string)$actual !== (string)$expected) {
        throw new RuntimeException("FAIL $label: got ".var_export($actual,true).", expected ".var_export($expected,true));
    }
    echo "PASS $label\n";
}
function fixture_party(mysqli $m,string $table,string $first): int {
    $stmt=$m->prepare("INSERT INTO {$table} (first_name,last_name,phone) VALUES (?,'Test','09121110000')");
    $stmt->bind_param('s',$first);$stmt->execute();$id=(int)$m->insert_id;$stmt->close();return $id;
}
function fixture_invoice(mysqli $m,string $kind,int $party,string $amount,string $type=''): int {
    $type=$type?:($kind==='customer'?'sales_invoice':'purchase_invoice');
    $num='ACCT-CI-'.bin2hex(random_bytes(5));
    $c=$kind==='customer'?$party:null;$s=$kind==='supplier'?$party:null;
    $stmt=$m->prepare('INSERT INTO invoices (invoice_number,type,customer_id,supplier_id,payment_type,payment_status,invoice_date,payable_amount)
     VALUES (?,?,?,?,"pos","unpaid",CURDATE(),?)');
    $stmt->bind_param('ssiis',$num,$type,$c,$s,$amount);$stmt->execute();$id=(int)$m->insert_id;$stmt->close();return $id;
}
$mysqli->begin_transaction();
try {
    // NOTE: acct_post owns a transaction; fixtures are committed only on disposable CI MySQL.
    // Separate payments below test real durable committed transactions.
    $customer=fixture_party($mysqli,'customers','Receivables');
    $supplier=fixture_party($mysqli,'suppliers','Payables');
    $sale=fixture_invoice($mysqli,'customer',$customer,'5000000');
    $old=fixture_invoice($mysqli,'supplier',$supplier,'70000000');
    $buy=fixture_invoice($mysqli,'supplier',$supplier,'15000000');
    $part=fixture_invoice($mysqli,'supplier',$supplier,'15000000');
    $pro=fixture_invoice($mysqli,'customer',$customer,'4000000','sales_proforma');
    $mysqli->commit();

    test_eq(acct_invoice_balance($mysqli,$sale)['remaining'],'5000000.00','new sale starts unpaid');
    $a=acct_post($mysqli,'customer',$customer,'in','3000000','pos',date('Y-m-d'),
        'POS-1','','partial sale',$sale,null,'test-sale-a');
    test_eq(acct_invoice_balance($mysqli,$sale)['remaining'],'2000000.00','sale 3m payment leaves 2m due');
    test_eq(acct_invoice_balance($mysqli,$sale)['status'],'partial','partial status derived');
    $aRetry=acct_post($mysqli,'customer',$customer,'in','3000000','pos',date('Y-m-d'),
        'POS-1','','partial sale',$sale,null,'test-sale-a');
    test_eq($aRetry['id'],$a['id'],'request retries idempotent');
    test_eq($aRetry['existing'],true,'idempotency signals existing');
    $b=acct_post($mysqli,'customer',$customer,'in','2000000','cash',date('Y-m-d'),
        'CASH-2','','final sale',$sale,null,'test-sale-b');
    test_eq(acct_invoice_balance($mysqli,$sale)['status'],'paid','fully paid status');
    acct_reverse($mysqli,$b['id'],'wrong receipt');
    test_eq(acct_invoice_balance($mysqli,$sale)['remaining'],'2000000.00','reversal restores receivable');
    test_eq(acct_party_summary($mysqli,'customer',$customer)['debt'],'2000000.00','customer balance excludes reversed payment');

    acct_post($mysqli,'supplier',$supplier,'out','35000000','bank_transfer',
       date('Y-m-d'),'BANK-35','','new+old purchase',$buy,null,'test-supplier-1');
    test_eq(acct_invoice_balance($mysqli,$buy)['remaining'],'0.00','new supplier invoice settled first');
    test_eq(acct_invoice_balance($mysqli,$old)['remaining'],'50000000.00','old supplier debt reduced 20m');
    test_eq(acct_invoice_balance($mysqli,$part)['remaining'],'15000000.00','other invoice left untouched');
    test_eq(acct_party_summary($mysqli,'supplier',$supplier)['debt'],'65000000.00','supplier debt net 100m-35m');
    acct_post($mysqli,'supplier',$supplier,'out','10000000','cash',date('Y-m-d'),
       'CASH-PART','','custom allocation',null,null,'test-supplier-2',false,false,
       [['invoice_id'=>$part,'amount'=>'10000000']]);
    test_eq(acct_invoice_balance($mysqli,$part)['remaining'],'5000000.00','manually allocated purchase partial');
    test_eq(acct_party_summary($mysqli,'supplier',$supplier)['debt'],'55000000.00','supplier debt net after partial');
    acct_post($mysqli,'supplier',$supplier,'out','60000000','pos',date('Y-m-d'),
       'POS-60','','settle remaining and credit',null,null,'test-supplier-3');
    test_eq(acct_party_summary($mysqli,'supplier',$supplier)['credit'],'5000000.00','overpayment becomes 5m credit');
    $future=fixture_invoice($mysqli,'supplier',$supplier,'7000000');
    $allocated=acct_apply_credit($mysqli,'supplier',$supplier,$future);
    test_eq($allocated,'5000000.00','prior supplier credit allocated to future invoice');
    test_eq(acct_invoice_balance($mysqli,$future)['remaining'],'2000000.00','future invoice 2m remains');
    test_eq(acct_party_summary($mysqli,'supplier',$supplier)['debt'],'2000000.00','credit consumption does not double count cash');

    acct_opening($mysqli,'supplier',$supplier,'1000000',date('Y-m-d'),'Legacy documented debit');
    test_eq(acct_party_summary($mysqli,'supplier',$supplier)['debt'],'3000000.00','opening debt recorded once');

    acct_post($mysqli,'customer',$customer,'in','1000000','cash',date('Y-m-d'),
       'ADVANCE','','proforma advance',null,$pro,'test-proforma');
    test_eq(acct_invoice_balance($mysqli,$pro)['status'],'proforma','proforma never generates final obligation');
    test_eq(acct_invoice_balance($mysqli,$pro)['advance'],'1000000.00','proforma advance documented');
    $mysqli->query("UPDATE invoices SET type='sales_invoice' WHERE id=".(int)$pro);
    test_eq(acct_apply_credit($mysqli,'customer',$customer,$pro),'1000000.00','converted proforma advance applies as credit');
    test_eq(acct_invoice_balance($mysqli,$pro)['remaining'],'3000000.00','converted proforma due after advance');

    // A partial historical payment must be verified without inventing cash.
    $legacyInvoice=fixture_invoice($mysqli,'customer',$customer,'9000000');
    $mysqli->query("INSERT INTO accounting_legacy_invoices (invoice_id,original_status,recognized_amount,verified)
       VALUES (".(int)$legacyInvoice.",'partial',0,0)");
    test_eq(acct_party_summary($mysqli,'customer',$customer)['pending_legacy'],1,'historical unknown partial is flagged');
    acct_verify_legacy($mysqli,$legacyInvoice,'4000000');
    test_eq(acct_invoice_balance($mysqli,$legacyInvoice)['remaining'],'5000000.00','historical paid amount reduces debt');
    test_eq(acct_party_summary($mysqli,'customer',$customer)['pending_legacy'],0,'legacy partial marked verified');
    try { acct_verify_legacy($mysqli,$legacyInvoice,'2000000'); throw new RuntimeException('duplicate verification accepted'); }
    catch(InvalidArgumentException $e) {echo "PASS historical verification immutable\n";}

    // Return is a commercial credit, not a fabricated cash withdrawal.
    $retNum='ACCT-RET-'.bin2hex(random_bytes(4));
    $stmt=$mysqli->prepare("INSERT INTO sales_returns (return_number,invoice_id,customer_id,return_date,payable_amount)
      VALUES (?,?,?,CURDATE(),'4000000')");
    $stmt->bind_param('sii',$retNum,$sale,$customer);$stmt->execute();$stmt->close();
    acct_sync_invoice($mysqli,$sale);
    test_eq(acct_invoice_balance($mysqli,$sale)['remaining'],'0.00','sale return reduces outstanding');
    test_eq(acct_invoice_balance($mysqli,$sale)['overpaid_credit'],'2000000.00','return creates 2m account credit when paid 3m');
    echo "PASS all accounting integration checks\n";
} catch(Throwable $e) {
    // This suite executes against a disposable MySQL server only.
    try {$mysqli->rollback();}catch(Throwable $_){}
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
