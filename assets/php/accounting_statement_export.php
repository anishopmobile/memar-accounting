<?php
/** Authenticated, amount-exact customer/supplier account statement (SpreadsheetML). */
declare(strict_types=1);
require_once __DIR__.'/boot.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/currency.php';
require_once __DIR__.'/accounting_ledger.php';
require_once __DIR__.'/report_excel_lib.php';
date_default_timezone_set('Asia/Tehran');

$kind=trim((string)($_GET['party_kind']??''));
$id=filter_var($_GET['party_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!in_array($kind,['customer','supplier'],true)||!$id)respond_error('طرف حساب نامعتبر است.',422);
try{$statement=acct_party_statement($mysqli,$kind,(int)$id);}
catch(InvalidArgumentException $e){respond_error($e->getMessage(),422);}
$title=($kind==='customer'?'صورت حساب مشتری':'صورت حساب تامین‌کننده').' #'.$id.' - واحد: '.getCurrencyLabel();
$columns=['date'=>'تاریخ میلادی','description'=>'شرح سند',
    'debit'=>'افزایش بدهی','credit'=>'کاهش بدهی','balance'=>'مانده حساب'];
$rows=[];
foreach($statement['movements'] as $m) {
    $rows[]=[
      'date'=>$m['date']==='0000-00-00'?'افتتاحیه':$m['date'],
      'description'=>$m['description'],
      'debit'=>convertFromBaseCurrency($m['debit']),
      'credit'=>convertFromBaseCurrency($m['credit']),
      'balance'=>$statement['balance_final']?convertFromBaseCurrency($m['balance']):'نیازمند تطبیق تاریخی'
    ];
}
$rows[]=[
  'date'=>'','description'=>$statement['balance_final']?'مانده نهایی':'مانده نامشخص ـ اسناد تاریخی نیازمند تطبیق',
  'debit'=>'','credit'=>'',
  'balance'=>$statement['balance_final']?convertFromBaseCurrency($statement['balance']):'نیازمند تطبیق'
];
outputReportExcel($title,$columns,$rows,'Account_Statement_'.$kind.'_'.$id.'_'.date('Ymd_His').'.xls',['debit','credit','balance']);
