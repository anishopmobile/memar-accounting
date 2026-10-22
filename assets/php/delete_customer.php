<?php
/** Prevent deleting a party with invoices, openings or any financial movements. */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_csrf_or_fail();
$id=trim((string)($_POST['customer_id']??''));
if($id===''||!ctype_digit($id)||(int)$id<=0)respond_error('شناسه مشتری نامعتبر است.',422);
$partyId=(int)$id;
$mysqli->begin_transaction();
try {
    $s=$mysqli->prepare('SELECT id FROM customers WHERE id=? FOR UPDATE');
    $s->bind_param('i',$partyId);$s->execute();$found=$s->get_result()->fetch_assoc();$s->close();
    if(!$found)throw new DomainException('مشتری یافت نشد.');
    $s=$mysqli->prepare('SELECT COUNT(*) count_invoices FROM invoices WHERE customer_id=?');
    $s->bind_param('i',$partyId);$s->execute();$invoiceCount=(int)$s->get_result()->fetch_assoc()['count_invoices'];$s->close();
    $kind='customer';
    $s=$mysqli->prepare('SELECT COUNT(*) count_payments FROM ledger_payments WHERE party_kind=? AND party_id=?');
    $s->bind_param('si',$kind,$partyId);$s->execute();$paymentCount=(int)$s->get_result()->fetch_assoc()['count_payments'];$s->close();
    $s=$mysqli->prepare('SELECT COUNT(*) count_openings FROM ledger_openings WHERE party_kind=? AND party_id=?');
    $s->bind_param('si',$kind,$partyId);$s->execute();$openingCount=(int)$s->get_result()->fetch_assoc()['count_openings'];$s->close();
    if($invoiceCount||$paymentCount||$openingCount)
        throw new DomainException('مشتری دارای سوابق مالی یا فاکتور است و حذف آن مجاز نیست.');
    $s=$mysqli->prepare('DELETE FROM customers WHERE id=?');
    $s->bind_param('i',$partyId);$s->execute();$s->close();
    $mysqli->commit();
    respond_json(true,'',['action'=>'deleted','customer_id'=>$partyId]);
} catch(DomainException $e){
    $mysqli->rollback();respond_error($e->getMessage(),422);
} catch(Throwable $e){
    $mysqli->rollback();error_log('delete_customer: '.$e->getMessage());
    respond_error('حذف انجام نشد.',500);
}
