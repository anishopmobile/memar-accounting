<?php
/**
 * payment_notification_repository.php — Notification log persistence.
 *
 * Payment success and SMS delivery are independent states. This log lets
 * admins distinguish "payment successful" from "SMS delivered". A UNIQUE
 * (transaction_id, type, provider) constraint prevents duplicate SMS sends.
 */
declare(strict_types=1);

final class PaymentNotificationRepository
{
    public function __construct(private \mysqli $db)
    {
    }

    /**
     * Create (or keep existing) a notification log row for a transaction.
     * Returns the id of the existing/new row.
     */
    public function log(
        int $transactionId,
        string $type,        // customer|admin
        string $recipient,
        string $provider,    // farazsms
        string $status,      // pending|sent|failed|disabled
        ?string $message = null
    ): int {
        // INSERT ... ON DUPLICATE KEY UPDATE keeps the first row (idempotent).
        $stmt = $this->db->prepare(
            'INSERT INTO payment_notifications
                (transaction_id, type, recipient, provider, status, message, attempt_count)
             VALUES (?, ?, ?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->bind_param('isssss', $transactionId, $type, $recipient, $provider, $status, $message);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Check if a notification row already exists (dup guard).
     */
    public function exists(int $transactionId, string $type, string $provider): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM payment_notifications WHERE transaction_id = ? AND type = ? AND provider = ? LIMIT 1'
        );
        $stmt->bind_param('iss', $transactionId, $type, $provider);
        $stmt->execute();
        $stmt->store_result();
        $found = $stmt->num_rows > 0;
        $stmt->close();
        return $found;
    }

    public function markSent(int $id, ?string $providerRequestId = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_notifications SET status = ?, provider_request_id = ?, sent_at = NOW() WHERE id = ?'
        );
        $status = 'sent';
        $stmt->bind_param('ssi', $status, $providerRequestId, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function markFailed(int $id, string $error): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE payment_notifications SET status = ?, error_message = ?, attempt_count = attempt_count + 1 WHERE id = ?'
        );
        $status = 'failed';
        $stmt->bind_param('ssi', $status, $error, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forTransaction(int $transactionId): array
    {
        $rows = [];
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_notifications WHERE transaction_id = ? ORDER BY id ASC'
        );
        $stmt->bind_param('i', $transactionId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}