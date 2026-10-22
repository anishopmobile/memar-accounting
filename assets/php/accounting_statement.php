<?php
/** Read-only accounting statement endpoint. */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/accounting_ledger.php';
try {
 $kind=(string)($_GET['party_kind']??'');
 $party=(int)($_GET['party_id']??0);
 if($party<1)throw new InvalidArgumentException('شناسه طرف حساب نامعتبر است.');
 $fk=ledger_party_field($kind);
 $balance=ledger_party_balance($mysqli,$kind,$party);
 $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
 $st=$mysqli->prepare("SELECT i.id,i.invoice_number,i.invoice_date,i.payable_amount,i.payment_status,
    COALESCE(SUM(aa.amount),0) allocated
    FROM invoices i LEFT JOIN accounting_allocations aa ON aa.invoice_id=i.id
    WHERE i.type=? AND i.{$fk}=? GROUP BY i.id ORDER BY i.invoice_date DESC,i.id DESC LIMIT 250");
 $st->bind_param('si',$type,$party);$st->execute();$res=$st->get_result();$invoices=[];
 while($row=$res->fetch_assoc()){
    $f=ledger_invoice_balance($mysqli,(int)$row['id']);
    $row['remaining']=$f['balance'];$row['needs_review']=$f['needs_review'];
    $invoices[]=$row;
 }$st->close();
 $st=$mysqli->prepare("SELECT ar.*,COALESCE(SUM(aa.amount),0) allocated
     FROM accounting_receipts ar LEFT JOIN accounting_allocations aa ON ar.id=aa.receipt_id
     WHERE ar.party_kind=? AND ar.party_id=? GROUP BY ar.id
     ORDER BY ar.payment_date DESC,ar.id DESC LIMIT 250");
 $st->bind_param('si',$kind,$party);$st->execute();$res=$st->get_result();$payments=[];
 while($row=$res->fetch_assoc()){$row['available']=money_sub($row['amount'],$row['allocated']);$payments[]=$row;}$st->close();
 respond_json(true,'',['balance'=>$balance,'invoices'=>$invoices,'payments'=>$payments]);
}catch(InvalidArgumentException $e){respond_error($e->getMessage(),422);}
catch(Throwable $e){error_log('Accounting read: '.$e->getMessage());respond_error('خطا در دریافت صورت‌حساب.',500);}
