<?php
/**
 * SmsService.php — Sends customer/admin SMS after verified payment.
 *
 * Critical rules:
 *  - SMS is only sent AFTER a transaction is PAID (server-side verified).
 *  - SMS failure NEVER changes payment status; results are logged separately.
 *  - Duplicate sends are blocked via payment_notifications UNIQUE constraint.
 *  - Configurable pattern codes + admin mobile.
 */
declare(strict_types=1);

final class SmsService
{
    private \mysqli $db;
    private PaymentSettingsRepository $settings;
    private PaymentNotificationRepository $notifications;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->settings = new PaymentSettingsRepository($db);
        $this->notifications = new PaymentNotificationRepository($db);
    }

    public function provider(): SmsProviderInterface
    {
        $s = $this->settings->get();
        return new FarazSmsProvider(
            (string) ($s['sms_api_key'] ?? ''),
            (string) ($s['sms_sender_line'] ?? ''),
            1
        );
    }

    /**
     * Build the customer success SMS values map.
     */
    public function customerValues(PaymentTransaction $tx): array
    {
        return [
            'tracking_code' => (string) ($tx->refId ?? $tx->number),
            'transaction_id' => $tx->number,
            'amount' => number_format($tx->amount),
            'date' => $tx->verifiedAt !== null ? jdate('j F Y', strtotime($tx->verifiedAt)) : '',
        ];
    }

    /**
     * Build the admin SMS values map.
     */
    public function adminValues(PaymentTransaction $tx, PaymentForm $form): array
    {
        return [
            'name' => (string) ($tx->customerName ?? ''),
            'mobile' => (string) ($tx->customerMobile ?? ''),
            'amount' => number_format($tx->amount),
            'purpose' => (string) ($form->purpose ?: $form->title),
            'date' => $tx->verifiedAt !== null ? jdate('j F Y', strtotime($tx->verifiedAt)) : '',
        ];
    }

    /**
     * Send customer + admin notifications for a PAID transaction.
     * Returns a summary: [customer => SmsResult, admin => SmsResult].
     *
     * Safety: if the transaction is not paid, returns disabled results.
     *
     * @return array{customer:SmsResult, admin:SmsResult}
     */
    public function notifyPaid(PaymentTransaction $tx): array
    {
        // Only ever notify after a verified payment.
        if (!$tx->isPaid()) {
            $disabled = new SmsResult(false, 'تراکنش پرداخت نشده است.');
            return ['customer' => $disabled, 'admin' => $disabled];
        }

        $s = $this->settings->get();
        $provider = null;
        if (!empty($s['sms_api_key'])) {
            $provider = $this->provider();
        }

        $results = [
            'customer' => new SmsResult(false, 'پیامک غیرفعال است.'),
            'admin' => new SmsResult(false, 'پیامک غیرفعال است.'),
        ];

        // Customer SMS
        $customerEnabled = (bool) ($s['sms_customer_enabled'] ?? false);
        $patternCustomer = trim((string) ($s['sms_success_pattern_code'] ?? ''));
        $mobile = (string) ($tx->customerMobile ?? '');
        if ($customerEnabled && $provider !== null && $patternCustomer !== '' && $mobile !== '') {
            $values = $this->customerValues($tx);
            $results['customer'] = $this->sendDeduped($tx->id, 'customer', $mobile, $patternCustomer, $values);
        } else {
            $recipient = $mobile !== '' ? $mobile : '—';
            $this->notifications->log($tx->id, 'customer', $recipient, 'farazsms', 'disabled', 'پیامک مشتری غیرفعال یا تنظیم ناقص');
        }

        // Admin SMS
        $adminEnabled = (bool) ($s['sms_admin_enabled'] ?? false);
        $patternAdmin = trim((string) ($s['sms_admin_pattern_code'] ?? ''));
        $adminMobile = trim((string) ($s['sms_admin_mobile'] ?? ''));
        if ($adminEnabled && $provider !== null && $patternAdmin !== '' && $adminMobile !== '') {
            $form = (new PaymentFormRepository($this->db))->find($tx->formId);
            $values = $form !== null ? $this->adminValues($tx, $form) : [];
            $results['admin'] = $this->sendDeduped($tx->id, 'admin', $adminMobile, $patternAdmin, $values);
        } else {
            $this->notifications->log($tx->id, 'admin', $adminMobile !== '' ? $adminMobile : '—', 'farazsms', 'disabled', 'پیامک مدیر غیرفعال یا تنظیم ناقص');
        }

        return $results;
    }

    /**
     * Deduplicated send. If a notification row exists for (tx,type,provider),
     * it does NOT send again.
     */
    private function sendDeduped(int $txId, string $type, string $mobile, string $patternCode, array $values): SmsResult
    {
        if ($this->notifications->exists($txId, $type, 'farazsms')) {
            return new SmsResult(false, 'اعلان قبلا برای این تراکنش فرستاده شده است.');
        }
        $logId = $this->notifications->log($txId, $type, $mobile, 'farazsms', 'pending');

        $result = $this->provider()->sendPattern($mobile, $patternCode, $values);

        if ($result->ok) {
            $requestId = (string) ($result->data['messageid'] ?? ($result->data['message_id'] ?? null));
            $this->notifications->markSent($logId, $requestId !== '' ? $requestId : null);
        } else {
            $this->notifications->markFailed($logId, substr($result->message, 0, 255));
        }
        return $result;
    }
}