<?php
/**
 * Automatic settlement is deliberately opt-in for ONE selected customer.
 * Never used on invoice edits, proformas, supplier invoices or older invoices.
 * All calls occur inside createInvoice()'s existing DB transaction.
 */
declare(strict_types=1);
require_once __DIR__ . '/accounting_ledger.php';

function acct_auto_lock_selected_customer(mysqli $db, int $customerId): bool
{
    if ($customerId <= 0) return false;
    // Serialize invoice creation with settings updates. Defaults to OFF.
    $stmt=$db->prepare('SELECT enabled,customer_id FROM accounting_auto_settlement_settings WHERE id=1 FOR UPDATE');
    $stmt->execute();$res=$stmt->get_result();$cfg=$res?$res->fetch_assoc():null;$stmt->close();
    if (!$cfg || (int)$cfg['enabled']!==1 || (int)$cfg['customer_id']!==$customerId) return false;
    // Existing ledger uses party -> invoice lock order.
    acct_party_lock($db,'customer',$customerId);
    return true;
}

function acct_auto_post_new_sale(mysqli $db, int $invoiceId, int $customerId,
                                string $amount, string $method, string $date): void
{
    if ($invoiceId <= 0 || $customerId <= 0) throw new LogicException('Invalid automatic settlement target');
    if (money_cmp($amount,'0')===0) {
        // Zero-valued sales are closed without fictitious cash movement.
        acct_update_status($db,$invoiceId);
        return;
    }
    // ID-bound internal idempotency key, not a user-controlled payment token.
    $result=acct_record_payment($db,[
        'party_kind'=>'customer','party_id'=>$customerId,'direction'=>'in',
        'amount'=>$amount,'method'=>$method,'payment_date'=>$date,
        'client_token'=>'auto_sales_invoice_'.$invoiceId,
        'note'=>'دریافت خودکار هنگام ثبت فاکتور فروش #'.$invoiceId,
        'allocations'=>[['invoice_id'=>$invoiceId,'amount'=>$amount]],
    ],true);
    if ($result['duplicate']) throw new LogicException('Unexpected duplicate automatic payment');
}

function acct_auto_invoice_has_receipt(mysqli $db,int $invoiceId): bool
{
    if($invoiceId<=0)return false;
    $token='auto_sales_invoice_'.$invoiceId;
    $stmt=$db->prepare('SELECT 1 FROM account_payments p
        JOIN account_payment_allocations a ON a.payment_id=p.id
        WHERE p.client_token=? AND a.invoice_id=? AND a.reversed_at IS NULL
          AND NOT EXISTS (SELECT 1 FROM account_payments rev WHERE rev.reversed_payment_id=p.id)
        LIMIT 1');
    $stmt->bind_param('si',$token,$invoiceId);$stmt->execute();
    $res=$stmt->get_result();$exists=$res && $res->fetch_assoc();$stmt->close();
    return (bool)$exists;
}
