<?php
/**
 * payment_transaction_repository.php — PaymentTransaction persistence layer.
 *
 * Security/consistency guarantees implemented here:
 *  - transaction numbers and authorities are UNIQUE (no duplicates)
 *  - every status change is a guarded transition (state machine + WHERE guard)
 *  - amounts are integer IRR only; a transaction's amount never changes
 *  - verification is idempotent: markPaid() predicates on current status
 */
declare(strict_types=1);

final class PaymentTransactionRepository
{
    public function __construct(private \mysqli $db)
    {
    }

    public function find(int $id): ?PaymentTransaction
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_transactions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row === null ? null : PaymentTransaction::fromRow($row);
    }

    public function findByAuthority(string $authority): ?PaymentTransaction
    {
        if ($authority === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM payment_transactions WHERE authority = ? LIMIT 1');
        $stmt->bind_param('s', $authority);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row === null ? null : PaymentTransaction::fromRow($row);
    }

    public function findByNumber(string $number): ?PaymentTransaction
    {
        if ($number === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM payment_transactions WHERE transaction_number = ? LIMIT 1');
        $stmt->bind_param('s', $number);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row === null ? null : PaymentTransaction::fromRow($row);
    }

    /**
     * Atomically create a transaction with a unique number.
     *
     * @param array<string,mixed>|null $submittedData
     * @return array{ok:bool, message:string, transaction:?PaymentTransaction}
     */
    public function create(
        int $formId,
        int $amount,
        string $gateway,
        ?array $submittedData = null
    ): array {
        if (!$this->isReasonableAmount($amount)) {
            return ['ok' => false, 'message' => 'مبلغ پرداخت نامعتبر است.', 'transaction' => null];
        }

        $this->db->begin_transaction();
        try {
            $number = $this->nextNumber();
            $mobile = (string) ($submittedData['mobile'] ?? '');
            $customerId = $this->resolveCustomerByMobile($mobile);
            $json = $submittedData !== null ? json_encode($submittedData, JSON_UNESCAPED_UNICODE) : null;

            $stmt = $this->db->prepare(
                'INSERT INTO payment_transactions
                    (form_id, customer_id, transaction_number, amount, currency, status, gateway,
                     customer_name, customer_mobile, customer_email, submitted_data, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $status = PaymentStateMachine::CREATED;
            $currency = 'IRR';
            $ip = $this->clientIp();
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $cName = $this->nullable($submittedData['name'] ?? null, 255);
            $cMobile = $this->nullable($submittedData['mobile'] ?? null, 40);
            $cEmail = $this->nullable($submittedData['email'] ?? null, 255);
            $stmt->bind_param(
                'iisisssssssss',
                $formId,
                $customerId,
                $number,
                $amount,
                $currency,
                $status,
                $gateway,
                $cName,
                $cMobile,
                $cEmail,
                $json,
                $ip,
                $ua
            );
            $stmt->execute();
            $txId = (int) $this->db->insert_id;
            $stmt->close();
            $this->db->commit();
        } catch (\mysqli_sql_exception $e) {
            $this->db->rollback();
            if ((int) $e->getCode() === 1062) {
                return ['ok' => false, 'message' => 'شناسه تراکنش تکراری. دوباره تلاش کنید.', 'transaction' => null];
            }
            return ['ok' => false, 'message' => 'خطا در ایجاد تراکنش.', 'transaction' => null];
        }

        $tx = $this->find($txId);
        return ['ok' => true, 'message' => 'تراکنش ایجاد شد.', 'transaction' => $tx];
    }

    /**
     * Guarded transition. Returns false if the transition is not allowed.
     */
    public function transition(int $id, string $fromStatus, string $toStatus): bool
    {
        if (!PaymentStateMachine::can($fromStatus, $toStatus)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE payment_transactions SET status = ? WHERE id = ? AND status = ?'
        );
        $stmt->bind_param('sis', $toStatus, $id, $fromStatus);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected === 1;
    }

    /** Assign the gateway authority. UNIQUE(authority) only inserts once. */
    public function assignAuthority(int $id, string $authority, string $fromStatus): bool
    {
        if ($authority === '') {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE payment_transactions SET authority = ?, status = ? WHERE id = ? AND status = ?'
        );
        $toStatus = PaymentStateMachine::PENDING;
        $stmt->bind_param('ssis', $authority, $toStatus, $id, $fromStatus);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected === 1;
    }

    /** Record callback arrival. Does NOT mark the transaction paid. */
    public function markCallbackReceived(int $id, string $authority): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_transactions
               SET status = ?, callback_at = NOW()
             WHERE id = ? AND authority = ? AND status = ?'
        );
        $to = PaymentStateMachine::CALLBACK_RECEIVED;
        $pending = PaymentStateMachine::PENDING;
        $stmt->bind_param('siss', $to, $id, $authority, $pending);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected === 1;
    }

    public function markVerifying(int $id): bool
    {
        return $this->transition($id, PaymentStateMachine::CALLBACK_RECEIVED, PaymentStateMachine::VERIFYING);
    }

    /**
     * Idempotent success: only a non-terminal, matching-authority row becomes
     * PAID. Returns false if already paid (duplicate callback) — the caller
     * treats that as "already handled", not an error.
     */
    public function markPaid(int $id, string $authority, string $refId, string $gatewayResponse): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_transactions
             SET status = ?, ref_id = ?, gateway_response = ?, verified_at = NOW(), failure_reason = NULL
             WHERE id = ? AND authority = ? AND status <> ? AND status <> ? AND status <> ?'
        );
        $paid = PaymentStateMachine::PAID;
        $failed = PaymentStateMachine::FAILED;
        $expired = PaymentStateMachine::EXPIRED;
        $stmt->bind_param(
            'sssissss',
            $paid,
            $refId,
            $gatewayResponse,
            $id,
            $authority,
            $paid,
            $failed,
            $expired
        );
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected === 1;
    }

    public function markFailed(int $id, string $reason): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_transactions
             SET status = ?, failure_reason = ?
             WHERE id = ? AND status NOT IN (?, ?, ?)'
        );
        $failed = PaymentStateMachine::FAILED;
        $paid = PaymentStateMachine::PAID;
        $expired = PaymentStateMachine::EXPIRED;
        $stmt->bind_param(
            'ssisis',
            $failed,
            $reason,
            $id,
            $paid,
            $failed,
            $expired
        );
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected === 1;
    }

    public function markCancelled(int $id): bool
    {
        return $this->terminalTransition($id, PaymentStateMachine::CANCELLED);
    }

    private function terminalTransition(int $id, string $toStatus): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_transactions SET status = ?
             WHERE id = ? AND status NOT IN (?, ?, ?, ?)'
        );
        $paid = PaymentStateMachine::PAID;
        $failed = PaymentStateMachine::FAILED;
        $cancelled = PaymentStateMachine::CANCELLED;
        $expired = PaymentStateMachine::EXPIRED;
        $stmt->bind_param(
            'sissss',
            $toStatus,
            $id,
            $paid,
            $failed,
            $cancelled,
            $expired
        );
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected === 1;
    }

    private function isReasonableAmount(int $amount): bool
    {
        return PaymentMoney::isPositive($amount)
            && $amount <= (10 ** PaymentMoney::MAX_DIGITS);
    }

    private function nullable($value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return mb_substr((string) $value, 0, $max, 'UTF-8');
    }

    /** Generate a unique transaction number via the atomic sequence table. */
    private function nextNumber(): string
    {
        $stmt = $this->db->prepare(
            "UPDATE invoice_sequences SET current_value = current_value + 1 WHERE seq_key = 'payment_transaction'"
        );
        $stmt->execute();
        $stmt->close();

        $sel = $this->db->prepare(
            "SELECT current_value FROM invoice_sequences WHERE seq_key = 'payment_transaction' LIMIT 1"
        );
        $sel->execute();
        $res = $sel->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $sel->close();
        $val = $row ? (int) $row['current_value'] : 0;
        return 'PT-' . str_pad((string) $val, 6, '0', STR_PAD_LEFT);
    }

    private function resolveCustomerByMobile(string $mobile): ?int
    {
        $mobile = trim($mobile);
        if ($mobile === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
        $stmt->bind_param('s', $mobile);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    private function clientIp(): ?string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', (string) $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : null;
    }

    /** Whitelist of sortable columns (never trust a raw client column). */
    private function sortMap(): array
    {
        return [
            'id' => 't.id',
            'number' => 't.transaction_number',
            'amount' => 't.amount',
            'status' => 't.status',
            'date' => 't.created_at',
            'mobile' => 't.customer_mobile',
        ];
    }

    /**
     * Advanced search with server-side filtering, pagination and safe sorting.
     *
     * Filter keys supported (all validated/prepared):
     *   search, status, form_id, date_from, date_to,
     *   amount_min, amount_max, mobile, reference
     *
     * @param array<string,mixed> $opts
     * @return array{rows:PaymentTransaction[], total:int, page:int, perPage:int, totalPages:int}
     */
    public function search(array $opts): array
    {
        $page = max(1, (int) ($opts['page'] ?? 1));
        $perPage = min(200, max(2, (int) ($opts['per_page'] ?? 20)));

        $where = [];
        $types = '';
        $params = [];

        // General text search across number / authority / ref / name / mobile
        $search = trim((string) ($opts['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(t.transaction_number LIKE ? OR t.authority LIKE ? OR t.ref_id LIKE ?
                         OR t.customer_name LIKE ? OR t.customer_mobile LIKE ?)';
            $types .= 'sssss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // Status (must be a valid state)
        $status = trim((string) ($opts['status'] ?? ''));
        if ($status !== '' && PaymentStateMachine::isValidStatus($status)) {
            $where[] = 't.status = ?';
            $types .= 's';
            $params[] = $status;
        }

        // Payment form filter
        $formId = (int) ($opts['form_id'] ?? 0);
        if ($formId > 0) {
            $where[] = 't.form_id = ?';
            $types .= 'i';
            $params[] = $formId;
        }

        // Date range (Y-m-d)
        $dateFrom = trim((string) ($opts['date_from'] ?? ''));
        if ($dateFrom !== '' && $this->isDate($dateFrom)) {
            $where[] = 'DATE(t.created_at) >= ?';
            $types .= 's';
            $params[] = $dateFrom;
        }
        $dateTo = trim((string) ($opts['date_to'] ?? ''));
        if ($dateTo !== '' && $this->isDate($dateTo)) {
            $where[] = 'DATE(t.created_at) <= ?';
            $types .= 's';
            $params[] = $dateTo;
        }

        // Amount range (integer IRR)
        $amountMin = (int) ($opts['amount_min'] ?? 0);
        if ($amountMin > 0) {
            $where[] = 't.amount >= ?';
            $types .= 'i';
            $params[] = $amountMin;
        }
        $amountMax = (int) ($opts['amount_max'] ?? 0);
        if ($amountMax > 0) {
            $where[] = 't.amount <= ?';
            $types .= 'i';
            $params[] = $amountMax;
        }

        // Mobile / reference
        $mobile = trim((string) ($opts['mobile'] ?? ''));
        if ($mobile !== '') {
            $where[] = 't.customer_mobile LIKE ?';
            $types .= 's';
            $params[] = '%' . $mobile . '%';
        }
        $reference = trim((string) ($opts['reference'] ?? ''));
        if ($reference !== '') {
            $where[] = 't.ref_id LIKE ?';
            $types .= 's';
            $params[] = '%' . $reference . '%';
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        // COUNT
        $total = 0;
        if ($types !== '') {
            $stmt = $this->db->prepare('SELECT COUNT(*) AS total FROM payment_transactions t ' . $whereSql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            $total = $row ? (int) $row['total'] : 0;
        } else {
            $res = $this->db->query('SELECT COUNT(*) AS total FROM payment_transactions t ' . $whereSql);
            $row = $res ? $res->fetch_assoc() : null;
            $total = $row ? (int) $row['total'] : 0;
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // SORT — only from the whitelist
        $sortColumn = (string) ($opts['sort'] ?? 'date');
        $sortDir = strtoupper((string) ($opts['dir'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';
        $sortMap = $this->sortMap();
        $orderCol = $sortMap[$sortColumn] ?? $sortMap['date'];
        $orderSql = ' ORDER BY ' . $orderCol . ' ' . $sortDir . ', t.id DESC';

        $items = [];
        $listSql = 'SELECT t.* FROM payment_transactions t ' . $whereSql . $orderSql . ' LIMIT ' . (int) $offset . ', ' . (int) $perPage;

        if ($types !== '') {
            $stmt = $this->db->prepare($listSql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $items[] = PaymentTransaction::fromRow($row);
            }
            $stmt->close();
        } else {
            $res = $this->db->query($listSql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $items[] = PaymentTransaction::fromRow($row);
                }
                $res->free();
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /** Dashboard aggregates over all transactions. */
    public function dashboardStats(): array
    {
        $res = $this->db->query(
            "SELECT
                COUNT(*) AS total_all,
                COALESCE(SUM(status = 'paid'), 0) AS total_paid,
                COALESCE(SUM(status = 'failed'), 0) AS total_failed,
                COALESCE(SUM(status IN ('pending','redirected','callback_received','verifying')), 0) AS total_pending,
                COALESCE(SUM(status = 'paid' AND DATE(created_at) = CURDATE()), 0) AS paid_today,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) AS total_revenue
             FROM payment_transactions"
        );
        $row = $res ? $res->fetch_assoc() : null;
        $stats = $row ?: [];
        $stats['total_revenue'] = (int) ($stats['total_revenue'] ?? 0);
        return $stats;
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}