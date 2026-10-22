<?php
/** Disposable browser fixture only; refuses production. */
declare(strict_types=1);
if (getenv('GITHUB_ACTIONS')!=='true') { fwrite(STDERR,"CI only\n");exit(2); }
require_once dirname(__DIR__).'/db.php';
function accounting_fixture_party(mysqli $db,string $table): int {
    $marker='CI-ledger-ui-'.bin2hex(random_bytes(4));
    if($table==='customers') {
        $st=$db->prepare("INSERT INTO customers(first_name,last_name,phone) VALUES(?,'Browser','010101')");
    }else{
        $st=$db->prepare("INSERT INTO suppliers(company_name,phone) VALUES(?,'020202')");
    }
    $st->bind_param('s',$marker);$st->execute();$id=(int)$st->insert_id;$st->close();return $id;
}
function accounting_fixture_invoice(mysqli $db,int $id,string $type): int{
    $number='CI-UI-'.bin2hex(random_bytes(5));
    if($type==='sales_invoice')$st=$db->prepare("INSERT INTO invoices(invoice_number,type,customer_id,invoice_date,payable_amount,legacy_paid_amount,payment_status) VALUES(?,?,?,CURDATE(),'5000000',0,'unpaid')");
    else $st=$db->prepare("INSERT INTO invoices(invoice_number,type,supplier_id,invoice_date,payable_amount,legacy_paid_amount,payment_status) VALUES(?,?,?,CURDATE(),'15000000',0,'unpaid')");
    $st->bind_param('ssi',$number,$type,$id);$st->execute();$newId=(int)$st->insert_id;$st->close();return $newId;
}
$c=accounting_fixture_party($mysqli,'customers');$s=accounting_fixture_party($mysqli,'suppliers');
echo json_encode(['customer_id'=>$c,'supplier_id'=>$s,'sales_invoice_id'=>accounting_fixture_invoice($mysqli,$c,'sales_invoice'),'purchase_invoice_id'=>accounting_fixture_invoice($mysqli,$s,'purchase_invoice')],JSON_THROW_ON_ERROR);
