<?php
/**
 * Auto settlement integration tests: disposable CI MySQL only.
 * Never run on a real shop database.
 */
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'){fwrite(STDERR,"CI only\n");exit(2);}
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/invoice_common.php';
require_once dirname(__DIR__).'/accounting_auto_settlement.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);

function aut_assert(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('Auto settlement failed: '.$label);
    echo 'PASS '.$label."\n";
}
function aut_customer(mysqli $db): int {
    $name='CI Auto '.bin2hex(random_bytes(5));
    $s=$db->prepare('INSERT INTO customers(first_name,last_name,phone) VALUES(?,"Customer","000")');
    $s->bind_param('s',$name);$s->execute();$id=(int)$s->insert_id;$s->close();
    return $id;
}
function aut_item(mysqli $db): int {
    $code='CI-AUTO-'.bin2hex(random_bytes(6));$name='Auto receipt product';
    $s=$db->prepare("INSERT INTO products(code,name,type,unit,stock,sale_price,purchase_price) VALUES(?,?,'product','عدد',100,100000,20000)");
    $s->bind_param('ss',$code,$name);$s->execute();$id=(int)$s->insert_id;$s->close();
    $s=$db->prepare("INSERT INTO product_units(product_id,name,conversion_factor,is_base,sale_price,purchase_price) VALUES(?,'عدد',1,1,100000,20000)");
    $s->bind_param('i',$id);$s->execute();$s->close();
    return $id;
}
function aut_input(string $type,int $party,int $product,?string $token=null,string $method='pos'): array {
    return ['type'=>$type,'party_id'=>$party,'payment_type'=>$method,'payment_status'=>'unpaid',
        'invoice_date'=>date('Y-m-d'),'discount'=>'0','note'=>'CI auto settlement',
        'client_token'=>$token??bin2hex(random_bytes(16)),'tax_rate'=>'0',
        'items'=>[['product_id'=>$product,'quantity'=>'1','unit_price'=>'100000','discount'=>'0']]];
}
function aut_config(mysqli $db,int $enabled,?int $customer): void {
    $s=$db->prepare('UPDATE accounting_auto_settlement_settings SET enabled=?,customer_id=? WHERE id=1');
    $s->bind_param('ii',$enabled,$customer);$s->execute();$s->close();
}
function aut_count(mysqli $db,int $invoiceId): int {
    $s=$db->prepare('SELECT COUNT(*) AS c FROM account_payment_allocations WHERE invoice_id=? AND reversed_at IS NULL');
    $s->bind_param('i',$invoiceId);$s->execute();$n=(int)$s->get_result()->fetch_assoc()['c'];$s->close();return $n;
}
$public=aut_customer($mysqli);
$other=aut_customer($mysqli);
$prod=aut_item($mysqli);
aut_config($mysqli,0,null);
aut_assert((int)$mysqli->query('SELECT enabled FROM accounting_auto_settlement_settings WHERE id=1')->fetch_assoc()['enabled']===0,
    'new feature starts off by default');
$noAuto=createInvoice($mysqli,aut_input('sales_invoice',$public,$prod));
aut_assert($noAuto['ok'] && empty($noAuto['auto_settled']) && aut_count($mysqli,(int)$noAuto['invoice_id'])===0,
    'disabled public customer stays in manual accounting mode');
aut_config($mysqli,1,$public);
$token=bin2hex(random_bytes(16));
$auto=createInvoice($mysqli,aut_input('sales_invoice',$public,$prod,$token,'pos'));
aut_assert($auto['ok'] && !empty($auto['auto_settled']),'enabled selected customer is auto-settled');
$id=(int)$auto['invoice_id'];
$paid=acct_invoice_outstanding($mysqli,$id);
aut_assert($paid['remaining']==='0.00' && $paid['invoice']['payment_status']==='paid',
    'new public sale status derives from actual settlement allocation');
aut_assert(aut_count($mysqli,$id)===1 && acct_auto_invoice_has_receipt($mysqli,$id),
    'exactly one auditable auto receipt exists for new public sale');
$st=$mysqli->prepare('SELECT p.method,p.amount,p.payment_date,a.amount AS allocated FROM account_payments p
    JOIN account_payment_allocations a ON a.payment_id=p.id WHERE a.invoice_id=?');
$st->bind_param('i',$id);$st->execute();$p=$st->get_result()->fetch_assoc();$st->close();
aut_assert($p['method']==='pos' && money_cmp($p['amount'],$paid['invoice']['payable_amount'])===0 &&
    money_cmp($p['allocated'],$p['amount'])===0 && $p['payment_date']===$paid['invoice']['invoice_date'],
    'original invoice payment method/date and TOMAN amount are recorded without float drift');
$retry=createInvoice($mysqli,aut_input('sales_invoice',$public,$prod,$token,'pos'));
aut_assert($retry['ok'] && (int)$retry['invoice_id']===$id &&
    aut_count($mysqli,$id)===1 && acct_auto_invoice_has_receipt($mysqli,$id),
    'retry with same invoice token never creates duplicate invoice or payment');
aut_assert(acct_party_statement($mysqli,'customer',$public)['balance']==='100000.00',
    'older unpaid public invoice is not retroactively settled by feature');
$otherInv=createInvoice($mysqli,aut_input('sales_invoice',$other,$prod));
aut_assert($otherInv['ok'] && empty($otherInv['auto_settled']) &&
    aut_count($mysqli,(int)$otherInv['invoice_id'])===0,
    'other customer remains manual even with auto setting enabled');
$proforma=createInvoice($mysqli,aut_input('sales_proforma',$public,$prod));
aut_assert($proforma['ok'] && empty($proforma['auto_settled']) &&
    aut_count($mysqli,(int)$proforma['invoice_id'])===0,
    'public customer sales proforma is not automatically settled');
aut_config($mysqli,0,$public);
$afterDisable=createInvoice($mysqli,aut_input('sales_invoice',$public,$prod));
aut_assert($afterDisable['ok'] && empty($afterDisable['auto_settled']) &&
    aut_count($mysqli,(int)$afterDisable['invoice_id'])===0,
    'disabling returns to exact manual accounting workflow');
aut_config($mysqli,1,$public);
$beforeStock=$mysqli->query('SELECT stock FROM products WHERE id='.$prod)->fetch_assoc()['stock'];
$mysqli->query("UPDATE accounting_cash_accounts SET is_active=0 WHERE account_type='bank_transfer'");
$failed=createInvoice($mysqli,aut_input('sales_invoice',$public,$prod,null,'bank_transfer'));
aut_assert(!$failed['ok'],'missing active bank account prevents half-saved public invoices');
$afterStock=$mysqli->query('SELECT stock FROM products WHERE id='.$prod)->fetch_assoc()['stock'];
aut_assert(money_cmp($beforeStock,$afterStock)===0,'failed automatic receipt rolls inventory back atomically');
$mysqli->query("UPDATE accounting_cash_accounts SET is_active=1 WHERE account_type='bank_transfer'");
aut_config($mysqli,0,null);
echo "PASS auto settlement MySQL integration suite\n";
