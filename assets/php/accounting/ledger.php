<?php
/**
 * Accounting subledger. Amounts are DECIMAL(14,2) base TOMAN.
 * Invoices describe obligations; cash movements are separate, immutable records.
 * Reversals retain their original payment and allocation audit trail.
 */
declare(strict_types=1);

function acct_migrate(mysqli $db): void {
    $sql = [
        "CREATE TABLE IF NOT EXISTS accounting_meta (
            meta_key VARCHAR(64) PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounting_receipts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            party_kind ENUM('customer','supplier') NOT NULL,
            party_id INT NOT NULL,
            flow ENUM('in','out') NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            method ENUM('cash','pos','bank_transfer') NOT NULL,
            account_name VARCHAR(120) NULL,
            reference_number VARCHAR(120) NULL,
            note VARCHAR(500) NULL,
            payment_date DATE NOT NULL,
            proforma_id INT NULL,
            status ENUM('posted','reversed','reversal') NOT NULL DEFAULT 'posted',
            reversed_of BIGINT UNSIGNED NULL UNIQUE,
            client_token VARCHAR(64) NULL UNIQUE,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ar_party (party_kind,party_id,payment_date),
            KEY idx_ar_proforma (proforma_id),
            CONSTRAINT fk_ar_proforma FOREIGN KEY (proforma_id) REFERENCES invoices(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ar_reversal FOREIGN KEY (reversed_of) REFERENCES accounting_receipts(id) ON DELETE RESTRICT,
            CONSTRAINT chk_ar_amount CHECK (amount > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounting_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            receipt_id BIGINT UNSIGNED NOT NULL,
            invoice_id INT NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_aa_pair (receipt_id,invoice_id),
            KEY idx_aa_invoice (invoice_id),
            CONSTRAINT fk_aa_receipt FOREIGN KEY (receipt_id) REFERENCES accounting_receipts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_aa_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
            CONSTRAINT chk_aa_amount CHECK (amount > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounting_opening_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            party_kind ENUM('customer','supplier') NOT NULL,
            party_id INT NOT NULL,
            signed_amount DECIMAL(14,2) NOT NULL,
            entry_date DATE NOT NULL,
            note VARCHAR(500) NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ao_party (party_kind,party_id),
            CONSTRAINT chk_ao_nonzero CHECK (signed_amount <> 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS accounting_legacy_invoices (
            invoice_id INT PRIMARY KEY,
            original_status ENUM('paid','unpaid','partial') NOT NULL,
            recognized_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            verified TINYINT(1) NOT NULL DEFAULT 0,
            verified_at TIMESTAMP NULL,
            KEY idx_ali_verified (verified),
            CONSTRAINT fk_ali_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    foreach ($sql as $statement) {
        if (!$db->query($statement)) throw new RuntimeException('Accounting schema setup failed: ' . $db->error);
    }
    // A one-time snapshot of pre-upgrade payment statuses. Never book imaginary cash.
    $db->begin_transaction();
    try {
        $db->query("INSERT IGNORE INTO accounting_meta (meta_key) VALUES ('legacy_invoice_snapshot_v1')");
        if ($db->affected_rows === 1) {
            $db->query("INSERT INTO accounting_legacy_invoices (invoice_id,original_status,recognized_amount,verified)
                SELECT id,payment_status,CASE WHEN payment_status='paid' THEN payable_amount ELSE 0 END,
                CASE WHEN payment_status='partial' THEN 0 ELSE 1 END
                FROM invoices WHERE type IN ('sales_invoice','purchase_invoice')");
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function acct_decimal($raw, bool $positive = true): string {
    $s = trim((string)$raw);
    if (!preg_match('/^-?(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,2})?$/D', $s)) {
        throw new InvalidArgumentException('مبلغ نامعتبر است یا دقت مجاز را ندارد.');
    }
    if ($positive && bccomp($s, '0', 2) <= 0) throw new InvalidArgumentException('مبلغ باید بزرگ‌تر از صفر باشد.');
    return bcadd($s, '0', 2);
}

function acct_date(string $s): string {
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $s) ||
        !checkdate((int)substr($s,5,2),(int)substr($s,8,2),(int)substr($s,0,4))) {
        throw new InvalidArgumentException('تاریخ پرداخت معتبر نیست.');
    }
    return $s;
}

function acct_party_table(string $kind): string {
    return match ($kind) {
        'customer' => 'customers', 'supplier' => 'suppliers',
        default => throw new InvalidArgumentException('نوع طرف حساب نامعتبر است.')
    };
}

function acct_lock_party(mysqli $db, string $kind, int $id): void {
    if ($id < 1) throw new InvalidArgumentException('طرف حساب نامعتبر است.');
    $table = acct_party_table($kind);
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE id=? FOR UPDATE");
    $stmt->bind_param('i',$id); $stmt->execute(); $res=$stmt->get_result();
    $ok=(bool)$res->fetch_assoc(); $stmt->close();
    if (!$ok) throw new InvalidArgumentException('طرف حساب یافت نشد.');
}

function acct_one(mysqli $db, string $sql, string $types = '', array $args = []): string {
    $stmt = $db->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute(); $res = $stmt->get_result(); $row=$res->fetch_row(); $stmt->close();
    return (string)($row[0] ?? '0');
}

/** Returned, paid, legacy, outstanding are exact base currency strings. */
function acct_invoice_balance(mysqli $db, int $id): array {
    $stmt=$db->prepare('SELECT id,type,customer_id,supplier_id,payable_amount,invoice_number FROM invoices WHERE id=?');
    $stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$r) throw new InvalidArgumentException('فاکتور یافت نشد.');
    $isSales=str_starts_with($r['type'],'sales');
    $isFinal=in_array($r['type'],['sales_invoice','purchase_invoice'],true);
    $returned=$isSales && $isFinal ? acct_one($db,
      'SELECT COALESCE(SUM(payable_amount),0) FROM sales_returns WHERE invoice_id=?','i',[$id]) : '0';
    $legacy=$isFinal ? acct_one($db,
      'SELECT COALESCE(recognized_amount,0) FROM accounting_legacy_invoices WHERE invoice_id=?','i',[$id]) : '0';
    $allocated=$isFinal ? acct_one($db,
      "SELECT COALESCE(SUM(a.amount),0) FROM accounting_allocations a
       INNER JOIN accounting_receipts p ON p.id=a.receipt_id AND p.status='posted'
       WHERE a.invoice_id=?", 'i',[$id]) : '0';
    $advance=!$isFinal ? acct_one($db,
      "SELECT COALESCE(SUM(amount),0) FROM accounting_receipts WHERE proforma_id=? AND status='posted'",'i',[$id]) : '0';
    $net=bcsub((string)$r['payable_amount'],$returned,2);
    if (bccomp($net,'0',2)<0) $net='0.00';
    $paid=bcadd($allocated,$legacy,2);
    $remaining=bcsub($net,$paid,2);
    $credit='0.00';
    if (bccomp($remaining,'0',2)<0) { $credit=bcsub('0',$remaining,2); $remaining='0.00'; }
    $status=!$isFinal ? 'proforma' :
       (bccomp($remaining,'0',2)===0 ? 'paid' :
        (bccomp($paid,'0',2)>0 || bccomp($returned,'0',2)>0 ? 'partial' : 'unpaid'));
    return [
      'invoice_id'=>$id,'invoice_number'=>$r['invoice_number'],'type'=>$r['type'],
      'party_kind'=>$isSales?'customer':'supplier',
      'party_id'=>(int)($isSales?$r['customer_id']:$r['supplier_id']),
      'payable_amount'=>(string)$r['payable_amount'],'returned'=>$returned,
      'net_amount'=>$net,'legacy_paid'=>$legacy,'allocated'=>$allocated,
      'paid'=>$paid,'remaining'=>$remaining,'overpaid_credit'=>$credit,
      'advance'=>$advance,'status'=>$status
    ];
}

function acct_sync_invoice(mysqli $db,int $id): void {
    $b=acct_invoice_balance($db,$id);
    if ($b['status']==='proforma') return;
    $stmt=$db->prepare('UPDATE invoices SET payment_status=? WHERE id=?');
    $stmt->bind_param('si',$b['status'],$id); $stmt->execute(); $stmt->close();
}

/** Must be called inside transaction while party row is locked. */
function acct_allocate(mysqli $db,int $receiptId,int $invoiceId,string $amount,string $kind,int $partyId): void {
    $amount=acct_decimal($amount);
    $inv=acct_invoice_balance($db,$invoiceId);
    if (!in_array($inv['type'],['sales_invoice','purchase_invoice'],true)
      || $inv['party_kind']!==$kind || $inv['party_id']!==$partyId) {
        throw new InvalidArgumentException('تخصیص به فاکتور نامرتبط یا پیش‌فاکتور امکان‌پذیر نیست.');
    }
    if (bccomp($amount,$inv['remaining'],2)>0) throw new InvalidArgumentException('مبلغ تخصیص بیشتر از مانده فاکتور است.');
    $row=$db->query('SELECT id FROM accounting_receipts WHERE id='.(int)$receiptId.' FOR UPDATE')->fetch_assoc();
    if (!$row) throw new InvalidArgumentException('تراکنش یافت نشد.');
    $available=acct_one($db, "SELECT p.amount-COALESCE(SUM(a.amount),0)
      FROM accounting_receipts p LEFT JOIN accounting_allocations a ON a.receipt_id=p.id
      WHERE p.id=? AND p.status='posted' GROUP BY p.id,p.amount",'i',[$receiptId]);
    if (bccomp($amount,$available,2)>0) throw new InvalidArgumentException('اعتبار این پرداخت کافی نیست.');
    $stmt=$db->prepare('INSERT INTO accounting_allocations (receipt_id,invoice_id,amount) VALUES (?,?,?)');
    $stmt->bind_param('iis',$receiptId,$invoiceId,$amount);
    $stmt->execute(); $stmt->close();
    acct_sync_invoice($db,$invoiceId);
}

/** Optional preferred invoice followed by oldest obligations; no automatic credit reuse. */
function acct_auto_allocate(mysqli $db,int $receiptId,string $kind,int $partyId,string $amount,?int $firstInvoice): array {
    $ids=[];
    if ($firstInvoice) $ids[]=$firstInvoice;
    $fk=$kind==='customer'?'customer_id':'supplier_id';
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $stmt=$db->prepare("SELECT id FROM invoices WHERE {$fk}=? AND type=? ORDER BY invoice_date,id");
    $stmt->bind_param('is',$partyId,$type); $stmt->execute();
    $res=$stmt->get_result();
    while ($row=$res->fetch_assoc()) { $id=(int)$row['id']; if (!in_array($id,$ids,true)) $ids[]=$id; }
    $stmt->close();
    $alloc=[];
    foreach($ids as $id) {
        if (bccomp($amount,'0',2)<=0) break;
        $inv=acct_invoice_balance($db,$id);
        if (bccomp($inv['remaining'],'0',2)<=0) continue;
        $chunk=bccomp($amount,$inv['remaining'],2)>0 ? $inv['remaining'] : $amount;
        acct_allocate($db,$receiptId,$id,$chunk,$kind,$partyId);
        $alloc[]=['invoice_id'=>$id,'amount'=>$chunk];
        $amount=bcsub($amount,$chunk,2);
    }
    return $alloc;
}

/**
 * Flow: customer+in (collection), supplier+out (supplier payment).
 * Opposite flows are refunds and need explicit user intent.
 * Caller can own transaction (invoice create/convert) by passing $nested=true.
 */
function acct_post(mysqli $db,string $kind,int $partyId,string $flow,$amount,string $method,
    string $date,string $reference='',string $account='',string $note='',
    ?int $preferredInvoice=null,?int $proformaId=null,string $token='',bool $nested=false,
    bool $autoAllocate=true,array $manual=[]): array {
    acct_party_table($kind);
    if (!in_array($flow,['in','out'],true) || !in_array($method,['cash','pos','bank_transfer'],true)) {
        throw new InvalidArgumentException('روش یا جهت پرداخت نامعتبر است.');
    }
    $amount=acct_decimal($amount); $date=acct_date($date);
    if (mb_strlen($reference)>120 || mb_strlen($account)>120 || mb_strlen($note)>500 || strlen($token)>64) {
        throw new InvalidArgumentException('اطلاعات پرداخت بیش از حد مجاز است.');
    }
    if ($proformaId && ($preferredInvoice || $manual)) throw new InvalidArgumentException('پیش‌پرداخت نباید به فاکتور قطعی تخصیص پیدا کند.');
    if (!$nested) $db->begin_transaction();
    try {
        acct_lock_party($db,$kind,$partyId);
        if ($token!=='') {
            $st=$db->prepare('SELECT id,party_kind,party_id FROM accounting_receipts WHERE client_token=?');
            $st->bind_param('s',$token);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
            if ($row) {
                if ($row['party_kind']!==$kind || (int)$row['party_id']!==$partyId) throw new InvalidArgumentException('شناسه تکراری متعلق به حساب دیگری است.');
                if (!$nested) $db->commit();
                return ['id'=>(int)$row['id'],'existing'=>true,'allocations'=>[]];
            }
        }
        if ($proformaId) {
            $p=acct_invoice_balance($db,$proformaId);
            if ($p['party_kind']!==$kind || $p['party_id']!==$partyId || $p['status']!=='proforma') {
                throw new InvalidArgumentException('پیش‌فاکتور متعلق به این طرف حساب نیست.');
            }
        }
        $st=$db->prepare('INSERT INTO accounting_receipts
          (party_kind,party_id,flow,amount,method,account_name,reference_number,note,payment_date,proforma_id,client_token,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $user=current_user();
        $userId=isset($user['id'])?(int)$user['id']:null;
        $tk=$token!==''?$token:null;
        $st->bind_param('sisssssssisi',$kind,$partyId,$flow,$amount,$method,$account,$reference,$note,$date,$proformaId,$tk,$userId);
        $st->execute();$rid=(int)$db->insert_id;$st->close();
        $alloc=[];
        $normalFlow=($kind==='customer' && $flow==='in') || ($kind==='supplier' && $flow==='out');
        if (!$normalFlow && ($preferredInvoice || $manual)) throw new InvalidArgumentException('استرداد وجه نمی‌تواند بدهی فاکتور را تسویه کند.');
        if ($normalFlow && !$proformaId) {
            if ($manual) {
                $sum='0.00';
                foreach($manual as $row) {
                    if (!is_array($row)) throw new InvalidArgumentException('تخصیص نامعتبر است.');
                    $chunk=acct_decimal($row['amount']??'');
                    $sum=bcadd($sum,$chunk,2);
                    if (bccomp($sum,$amount,2)>0) throw new InvalidArgumentException('جمع تخصیص از مبلغ پرداخت بیشتر است.');
                    $iid=(int)($row['invoice_id']??0);
                    acct_allocate($db,$rid,$iid,$chunk,$kind,$partyId);
                    $alloc[]=['invoice_id'=>$iid,'amount'=>$chunk];
                }
            } elseif($autoAllocate) {
                $alloc=acct_auto_allocate($db,$rid,$kind,$partyId,$amount,$preferredInvoice);
            }
        }
        if (!$nested) $db->commit();
        return ['id'=>$rid,'existing'=>false,'allocations'=>$alloc];
    } catch(Throwable $e) { if (!$nested) $db->rollback(); throw $e; }
}

/** Allocate existing unassigned credit, including after conversion of a proforma. */
function acct_apply_credit(mysqli $db,string $kind,int $partyId,int $invoiceId): string {
    $db->begin_transaction();
    try {
        acct_lock_party($db,$kind,$partyId);
        $inv=acct_invoice_balance($db,$invoiceId);
        if ($inv['party_kind']!==$kind || $inv['party_id']!==$partyId || $inv['status']==='proforma') {
            throw new InvalidArgumentException('فاکتور معتبر نیست.');
        }
        $st=$db->prepare("SELECT r.id,r.amount-COALESCE(SUM(a.amount),0) available
          FROM accounting_receipts r LEFT JOIN accounting_allocations a ON r.id=a.receipt_id
          WHERE r.party_kind=? AND r.party_id=? AND r.status='posted'
            AND r.flow=? AND (r.proforma_id IS NULL OR EXISTS(
              SELECT 1 FROM invoices i WHERE i.id=r.proforma_id AND i.type IN ('sales_invoice','purchase_invoice')))
          GROUP BY r.id HAVING available>0 ORDER BY r.payment_date,r.id");
        $flow=$kind==='customer'?'in':'out';
        $st->bind_param('sis',$kind,$partyId,$flow);$st->execute();$rs=$st->get_result();
        $rows=[];while($row=$rs->fetch_assoc())$rows[]=$row;$st->close();
        $used='0.00';
        foreach($rows as $row) {
            $remain=acct_invoice_balance($db,$invoiceId)['remaining'];
            if(bccomp($remain,'0',2)<=0)break;
            $chunk=bccomp($remain,$row['available'],2)<0?$remain:$row['available'];
            acct_allocate($db,(int)$row['id'],$invoiceId,$chunk,$kind,$partyId);
            $used=bcadd($used,$chunk,2);
        }
        $db->commit();return $used;
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function acct_reverse(mysqli $db,int $id,string $note=''): void {
    $db->begin_transaction();
    try {
        $st=$db->prepare('SELECT * FROM accounting_receipts WHERE id=?');
        $st->bind_param('i',$id);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();
        if(!$r || $r['status']!=='posted')throw new InvalidArgumentException('تراکنش قابل ابطال نیست.');
        acct_lock_party($db,$r['party_kind'],(int)$r['party_id']);
        $st=$db->prepare("UPDATE accounting_receipts SET status='reversed' WHERE id=? AND status='posted'");
        $st->bind_param('i',$id);$st->execute();$count=$st->affected_rows;$st->close();
        if($count!==1)throw new RuntimeException('تراکنش قبلاً ابطال شده است.');
        $flow=$r['flow']==='in'?'out':'in';
        $memo=mb_substr('ابطال سند '.$id.' — '.$note,0,500);
        $st=$db->prepare("INSERT INTO accounting_receipts
          (party_kind,party_id,flow,amount,method,account_name,reference_number,note,payment_date,status,reversed_of,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,'reversal',?,?)");
        $user=current_user();$userId=isset($user['id'])?(int)$user['id']:null;
        $st->bind_param('sisssssssii',$r['party_kind'],$r['party_id'],$flow,$r['amount'],$r['method'],
          $r['account_name'],$r['reference_number'],$memo,$r['payment_date'],$id,$userId);
        $st->execute();$st->close();
        $st=$db->prepare('SELECT invoice_id FROM accounting_allocations WHERE receipt_id=?');
        $st->bind_param('i',$id);$st->execute();$rs=$st->get_result();$ids=[];
        while($a=$rs->fetch_assoc())$ids[]=(int)$a['invoice_id'];$st->close();
        foreach($ids as $iid)acct_sync_invoice($db,$iid);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function acct_opening(mysqli $db,string $kind,int $id,$amount,string $date,string $note): void {
    acct_party_table($kind);$amount=acct_decimal($amount,false);$date=acct_date($date);
    if(bccomp($amount,'0',2)===0 || trim($note)==='' || mb_strlen($note)>500)
      throw new InvalidArgumentException('مبلغ غیرصفر و توضیح افتتاحیه الزامی است.');
    $db->begin_transaction();
    try {
        acct_lock_party($db,$kind,$id);
        $st=$db->prepare('INSERT INTO accounting_opening_entries
         (party_kind,party_id,signed_amount,entry_date,note,created_by) VALUES (?,?,?,?,?,?)');
        $user=current_user();$userId=isset($user['id'])?(int)$user['id']:null;
        $st->bind_param('sisssi',$kind,$id,$amount,$date,$note,$userId);
        $st->execute();$st->close();$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/** Historical partial payments are documentary opening adjustments, NOT money movements. */
function acct_verify_legacy(mysqli $db,int $invoiceId,$amount): void {
    $amount=acct_decimal($amount,false);
    $db->begin_transaction();
    try {
        $st=$db->prepare('SELECT i.type,i.customer_id,i.supplier_id,i.payable_amount,l.original_status,l.verified
          FROM invoices i JOIN accounting_legacy_invoices l ON i.id=l.invoice_id WHERE i.id=?');
        $st->bind_param('i',$invoiceId);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
        if(!$row || $row['original_status']!=='partial'||(int)$row['verified']===1)
          throw new InvalidArgumentException('فقط مبالغ جزئی قدیمیِ بررسی‌نشده قابل تأیید هستند.');
        $kind=str_starts_with($row['type'],'sales')?'customer':'supplier';
        $party=(int)($kind==='customer'?$row['customer_id']:$row['supplier_id']);
        acct_lock_party($db,$kind,$party);
        if(bccomp($amount,$row['payable_amount'],2)>0)
          throw new InvalidArgumentException('پرداخت تاریخی نمی‌تواند بیشتر از مبلغ فاکتور باشد.');
        $st=$db->prepare('UPDATE accounting_legacy_invoices
         SET recognized_amount=?,verified=1,verified_at=CURRENT_TIMESTAMP WHERE invoice_id=? AND verified=0');
        $st->bind_param('si',$amount,$invoiceId);$st->execute();
        if($st->affected_rows!==1)throw new RuntimeException('تأیید قبلاً ثبت شده است.');
        $st->close();acct_sync_invoice($db,$invoiceId);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function acct_party_summary(mysqli $db,string $kind,int $id): array {
    acct_party_table($kind);
    $fk=$kind==='customer'?'customer_id':'supplier_id';
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $sales=acct_one($db,"SELECT COALESCE(SUM(payable_amount),0) FROM invoices WHERE {$fk}=? AND type=?",'is',[$id,$type]);
    $returns=$kind==='customer'?acct_one($db,'SELECT COALESCE(SUM(payable_amount),0) FROM sales_returns WHERE customer_id=?','i',[$id]):'0';
    $legacy=acct_one($db,"SELECT COALESCE(SUM(l.recognized_amount),0) FROM accounting_legacy_invoices l JOIN invoices i ON i.id=l.invoice_id WHERE i.{$fk}=? AND i.type=?",'is',[$id,$type]);
    $opening=acct_one($db,"SELECT COALESCE(SUM(signed_amount),0) FROM accounting_opening_entries WHERE party_kind=? AND party_id=?",'si',[$kind,$id]);
    $flow=$kind==='customer'?'in':'out';
    $settled=acct_one($db,"SELECT COALESCE(SUM(CASE WHEN flow=? THEN amount ELSE -amount END),0)
      FROM accounting_receipts WHERE party_kind=? AND party_id=? AND status='posted'",'ssi',[$flow,$kind,$id]);
    $balance=bcsub(bcadd(bcsub($sales,$returns,2),$opening,2),bcadd($legacy,$settled,2),2);
    $pending=acct_one($db,"SELECT COUNT(*) FROM accounting_legacy_invoices l
      JOIN invoices i ON i.id=l.invoice_id WHERE i.{$fk}=? AND l.verified=0",'i',[$id]);
    return [
      'invoiced'=>$sales,'returns'=>$returns,'legacy'=>$legacy,'opening'=>$opening,
      'net_settled'=>$settled,'balance'=>$balance,
      'debt'=>bccomp($balance,'0',2)>0?$balance:'0.00',
      'credit'=>bccomp($balance,'0',2)<0?bcsub('0',$balance,2):'0.00',
      'pending_legacy'=>(int)$pending
    ];
}

/** Exact chronological statement; positive delta increases party debt. */
function acct_statement(mysqli $db,string $kind,int $id): array {
    $summary=acct_party_summary($db,$kind,$id);
    $events=[];$fk=$kind==='customer'?'customer_id':'supplier_id';
    $type=$kind==='customer'?'sales_invoice':'purchase_invoice';
    $st=$db->prepare("SELECT id,invoice_date,invoice_number,payable_amount FROM invoices WHERE {$fk}=? AND type=?");
    $st->bind_param('is',$id,$type);$st->execute();$rs=$st->get_result();
    while($r=$rs->fetch_assoc())$events[]=['date'=>$r['invoice_date'],'key'=>'1-'.$r['id'],'description'=>'فاکتور '.$r['invoice_number'],
      'delta'=>$r['payable_amount'],'invoice_id'=>(int)$r['id']];
    $st->close();
    if($kind==='customer') {
      $st=$db->prepare('SELECT id,return_date,return_number,payable_amount FROM sales_returns WHERE customer_id=?');
      $st->bind_param('i',$id);$st->execute();$rs=$st->get_result();
      while($r=$rs->fetch_assoc())$events[]=['date'=>$r['return_date'],'key'=>'2-'.$r['id'],
        'description'=>'برگشت از فروش '.$r['return_number'],'delta'=>bcsub('0',$r['payable_amount'],2)];
      $st->close();
    }
    $st=$db->prepare("SELECT l.invoice_id,l.recognized_amount,i.invoice_date,i.invoice_number
      FROM accounting_legacy_invoices l JOIN invoices i ON i.id=l.invoice_id WHERE i.{$fk}=? AND i.type=? AND l.recognized_amount>0");
    $st->bind_param('is',$id,$type);$st->execute();$rs=$st->get_result();
    while($r=$rs->fetch_assoc())$events[]=['date'=>$r['invoice_date'],'key'=>'3-'.$r['invoice_id'],
      'description'=>'تسویه مستند تاریخی '.$r['invoice_number'],'delta'=>bcsub('0',$r['recognized_amount'],2)];
    $st->close();
    $st=$db->prepare('SELECT id,entry_date,signed_amount,note FROM accounting_opening_entries WHERE party_kind=? AND party_id=?');
    $st->bind_param('si',$kind,$id);$st->execute();$rs=$st->get_result();
    while($r=$rs->fetch_assoc())$events[]=['date'=>$r['entry_date'],'key'=>'0-'.$r['id'],
      'description'=>'مانده/اصلاحیه افتتاحیه: '.$r['note'],'delta'=>$r['signed_amount']];
    $st->close();
    $st=$db->prepare('SELECT id,payment_date,amount,method,flow,status,reference_number,note FROM accounting_receipts WHERE party_kind=? AND party_id=?');
    $st->bind_param('si',$kind,$id);$st->execute();$rs=$st->get_result();
    while($r=$rs->fetch_assoc()){
      $effective=$r['status']==='posted';
      $normal=$r['flow']===($kind==='customer'?'in':'out');
      $delta=$effective?($normal?bcsub('0',$r['amount'],2):$r['amount']):'0.00';
      $events[]=['date'=>$r['payment_date'],'key'=>'4-'.$r['id'],
        'description'=>($effective?($normal?'دریافت/پرداخت':'استرداد وجه'):'سند ابطال‌شده').' #'.$r['id'].' '.($r['reference_number']??'').' '.($r['note']??''),
        'delta'=>$delta,'receipt_id'=>(int)$r['id'],'status'=>$r['status'],'method'=>$r['method']];
    }
    $st->close();
    usort($events,fn($a,$b)=>strcmp($a['date'].'|'.$a['key'],$b['date'].'|'.$b['key']));
    $running='0.00';
    foreach($events as &$ev){$running=bcadd($running,$ev['delta'],2);$ev['running']=$running;}
    unset($ev);
    return ['summary'=>$summary,'events'=>$events];
}
