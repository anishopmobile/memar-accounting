<?php
/** Actual dashboard outstanding vs. ledger balances on disposable MySQL. */
declare(strict_types=1);
require_once dirname(__DIR__).'/db.php';
require_once dirname(__DIR__).'/accounting_ledger.php';
require_once dirname(__DIR__).'/currency.php';
$check=static function(bool $yes,string $label): void {if(!$yes)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";};
function dashboard_html(): string {ob_start();include __DIR__.'/../../../pages/dashboard.php';return (string)ob_get_clean();}
$initial=dashboard_html();
$check(str_contains($initial,'فروش امروز') && str_contains($initial,'فروش ماهانه'),'dashboard metric cards render');
$check(!preg_match('/Warning|Fatal error|Notice/',$initial),'dashboard renders without PHP warning');
$mysqli->begin_transaction();
try{
 $mysqli->query("INSERT INTO customers (first_name,last_name,phone) VALUES ('Dashboard','Accounting','0')");
 $party=(int)$mysqli->insert_id;
 $name='ACC-DASH-'.bin2hex(random_bytes(6));$type='sales_invoice';$date=date('Y-m-d');$amt='5000000';$status='unpaid';
 $st=$mysqli->prepare("INSERT INTO invoices (invoice_number,type,customer_id,invoice_date,payable_amount,payment_status) VALUES (?,?,?,?,?,?)");
 $st->bind_param('ssisss',$name,$type,$party,$date,$amt,$status);$st->execute();$id=(int)$mysqli->insert_id;$st->close();
 ledger_post_in_tx($mysqli,[
   'party_kind'=>'customer','party_id'=>$party,'direction'=>'in','amount'=>'3000000',
   'method'=>'cash','allocations'=>[['invoice_id'=>$id,'amount'=>'3000000']]
 ]);
 $f=ledger_invoice_balance($mysqli,$id);
 $check(money_cmp($f['balance'],'2000000')===0,'dashboard test fixture has 2m receivable');
 $html=dashboard_html();
 $check(str_contains($html,'فروش امروز') && str_contains($html,'فروش ماهانه'),'dashboard renders with partially settled invoice');
 $check(!preg_match('/Warning|Fatal error|Notice/',$html),'dashboard remains warning-free after settlement');
 $check(money_cmp($GLOBALS['todayUnpaid']??'0','2000000')>=0,'dashboard today balance includes 2m outstanding');
 $check(money_cmp($GLOBALS['monthlyUnpaid']??'0','2000000')>=0,'dashboard month balance includes 2m outstanding');
}finally{$mysqli->rollback();}
echo "PASS accounting dashboard checks\n";
