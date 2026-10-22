<?php
/** Ledger-backed reporting with one aggregate SQL query; no N+1 per party. */
declare(strict_types=1);
require_once __DIR__.'/ledger_service.php';

function ledger_parties_report(mysqli $db,string $kind,string $search=''): array {
    if(!in_array($kind,['customer','supplier'],true))throw new InvalidArgumentException('نوع طرف حساب نامعتبر است.');
    ledger_initialize_legacy($db);
    $customer=$kind==='customer';
    $table=$customer?'customers':'suppliers';
    $iFk=$customer?'customer_id':'supplier_id';
    $iType=$customer?'sales_invoice':'purchase_invoice';
    $movement=$customer?'receipt':'disbursement';
    $searchFields=$customer
        ? "CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))"
        : "CONCAT(COALESCE(p.company_name,''),' ',COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))";
    $returnJoin=$customer ? "LEFT JOIN (
            SELECT customer_id party_id,SUM(payable_amount) amount FROM sales_returns GROUP BY customer_id
        ) r ON r.party_id=p.id" : "";
    $returned=$customer?'COALESCE(r.amount,0)':'0';
    $balance="(COALESCE(i.amount,0)+COALESCE(o.signed_amount,0)-COALESCE(h.amount,0)-COALESCE(m.amount,0)-$returned)";
    $cols=$customer ? "p.id,p.first_name,p.last_name,p.national_code,p.phone,p.economic_code,p.registration_number,p.address,p.postal_code,p.note,p.created_at"
                    : "p.id,p.company_name,p.first_name,p.last_name,p.national_code,p.phone,p.economic_code,p.registration_number,p.address,p.postal_code,p.note,p.created_at";
    $sql="SELECT $cols,
           COALESCE(i.amount,0) total_purchases,
           COALESCE(i.amount,0) total_spent,
           COALESCE(h.amount,0) historically_paid,
           COALESCE(m.amount,0) accounted_payments,
           $returned returned_amount,
           COALESCE(o.signed_amount,0) opening_amount,
           GREATEST($balance,0) debt,
           GREATEST($balance,0) unpaid,
           GREATEST(-$balance,0) credit,
           COALESCE(h.requires_reconciliation,0) requires_reconciliation
        FROM $table p
        LEFT JOIN (
            SELECT $iFk party_id,SUM(payable_amount) amount FROM invoices
            WHERE type='$iType' GROUP BY $iFk
        ) i ON i.party_id=p.id
        LEFT JOIN (
            SELECT x.$iFk party_id,SUM(l.recognized_paid) amount,
               SUM(CASE WHEN l.classification='requires_reconciliation' THEN 1 ELSE 0 END) requires_reconciliation
            FROM ledger_legacy_invoices l
            JOIN invoices x ON x.id=l.invoice_id
            WHERE x.type='$iType' GROUP BY x.$iFk
        ) h ON h.party_id=p.id
        LEFT JOIN (
            SELECT party_id,SUM(amount) amount FROM ledger_payments
            WHERE party_kind='$kind' AND direction='$movement' AND voided_at IS NULL
            GROUP BY party_id
        ) m ON m.party_id=p.id
        LEFT JOIN ledger_openings o ON o.party_kind='$kind' AND o.party_id=p.id
        $returnJoin";
    if($search!=='')$sql.=" WHERE $searchFields LIKE ?";
    $sql.=' ORDER BY p.created_at DESC,p.id DESC';
    if($search!=='') {
        $stmt=$db->prepare($sql);
        $like='%'.$search.'%';
        $stmt->bind_param('s',$like);$stmt->execute();
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        return $rows;
    }
    $r=$db->query($sql);
    if(!$r)throw new RuntimeException('Ledger report query failed.');
    $rows=$r->fetch_all(MYSQLI_ASSOC);$r->free();
    return $rows;
}

/** Sum of actual remaining amounts on final sales invoices within a date window. */
function ledger_outstanding_for_period(mysqli $db,string $from,string $to): string {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$to))throw new InvalidArgumentException('بازه زمانی نامعتبر است.');
    ledger_initialize_legacy($db);
    $sql="SELECT COALESCE(SUM(GREATEST(i.payable_amount
        -COALESCE(l.recognized_paid,0)
        -COALESCE(p.amount,0)
        -COALESCE(c.amount,0)
        -COALESCE(r.amount,0),0)),0) AS outstanding
      FROM invoices i
      LEFT JOIN ledger_legacy_invoices l ON l.invoice_id=i.id
      LEFT JOIN (
        SELECT a.invoice_id,SUM(a.amount) amount
          FROM ledger_allocations a JOIN ledger_payments p ON p.id=a.payment_id AND p.voided_at IS NULL
          GROUP BY a.invoice_id
      ) p ON p.invoice_id=i.id
      LEFT JOIN (
        SELECT a.invoice_id,SUM(a.amount) amount
          FROM ledger_credit_allocations a JOIN ledger_payments p ON p.id=a.payment_id AND p.voided_at IS NULL
          GROUP BY a.invoice_id
      ) c ON c.invoice_id=i.id
      LEFT JOIN (
        SELECT invoice_id,SUM(payable_amount) amount FROM sales_returns GROUP BY invoice_id
      ) r ON r.invoice_id=i.id
      WHERE i.type='sales_invoice' AND i.invoice_date BETWEEN ? AND ?";
    $s=$db->prepare($sql);$s->bind_param('ss',$from,$to);$s->execute();$amount=(string)($s->get_result()->fetch_assoc()['outstanding']??'0');$s->close();
    return money_round($amount);
}
