<?php
/**
 * payment_service.php — Payment orchestration service.
 *
 * Coordinates form → transaction → gateway → verify, enforcing:
 *  - amount resolved server-side (never trusted from the client)
 *  - callback arrival NEVER implies success
 *  - server-side verification required before marking paid
 *  - idempotent verification (duplicate callbacks are safe)
 */
declare(strict_types=1);

final class PaymentService
{
    private \mysqli $db;
    private PaymentFormRepository $forms;
    private PaymentTransactionRepository $txs;
    private PaymentSettingsRepository $settings;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
        $this->forms = new PaymentFormRepository($db);
        $this->txs = new PaymentTransactionRepository($db);
        $this->settings = new PaymentSettingsRepository($db);
    }

    public function forms(): PaymentFormRepository
    {
        return $this->forms;
    }

    public function transactions(): PaymentTransactionRepository
    {
        return $this->txs;
    }

    /** Build the configured gateway provider from stored settings. */
    public function gateway(): PaymentGatewayInterface
    {
        $s = $this->settings->get();
        return new ZarinPalGateway(
            (string) ($s['zarinpal_merchant_id'] ?? ''),
            (bool) ($s['zarinpal_sandbox'] ?? false)
        );
    }

    /** Whether the configured ZarinPal gateway is enabled in settings. */
    public function isGatewayEnabled(): bool
    {
        $s = $this->settings->get();
        return (bool) ($s['zarinpal_gateway_enabled'] ?? false);
    }

    /**
     * Stage 1 — create a transaction from submitted form data.
     *
     * @return array{ok:bool, message:string, transaction:?PaymentTransaction, form:?PaymentForm}
     */
    public function begin(PaymentForm $form, array $submitted): array
    {
        if (!$this->isGatewayEnabled()) {
            return ['ok' => false, 'message' => 'درگاه پرداخت زرین‌پال در تنظیمات فعال نیست.', 'transaction' => null, 'form' => $form];
        }
        if (!$form->isActive) {
            return ['ok' => false, 'message' => 'این فرم پرداخت غیرفعال است.', 'transaction' => null, 'form' => $form];
        }
        if ($form->isExpired()) {
            return ['ok' => false, 'message' => 'این فرم پرداخت منقضی شده است.', 'transaction' => null, 'form' => $form];
        }

        $amountResult = PaymentValidator::resolveAmount($form, $submitted['amount'] ?? '');
        if (!$amountResult['ok']) {
            return ['ok' => false, 'message' => $amountResult['message'], 'transaction' => null, 'form' => $form];
        }

        $fieldsResult = PaymentValidator::validateFields($form, $submitted);
        if (!$fieldsResult['ok']) {
            return ['ok' => false, 'message' => $fieldsResult['message'], 'transaction' => null, 'form' => $form];
        }
        $data = $fieldsResult['data'];
        $data['amount'] = $amountResult['amount']; // safe: server-resolved

        $created = $this->txs->create($form->id, $amountResult['amount'], 'zarinpal', $data);
        if (!$created['ok']) {
            return ['ok' => false, 'message' => $created['message'], 'transaction' => null, 'form' => $form];
        }

        return ['ok' => true, 'message' => 'تراکنش ایجاد شد.', 'transaction' => $created['transaction'], 'form' => $form];
    }

    /**
     * Stage 2 — request payment from the gateway and store authority.
     *
     * @return array{ok:bool, message:string, redirect:?string, transaction:?PaymentTransaction}
     */
    public function requestGatewayPayment(PaymentTransaction $tx, string $callbackUrl): array
    {
        if (!$this->isGatewayEnabled()) {
            return ['ok' => false, 'message' => 'درگاه پرداخت زرین‌پال در تنظیمات فعال نیست.', 'redirect' => null, 'transaction' => $tx];
        }

        $form = $this->forms->find($tx->formId);
        if ($form === null) {
            return ['ok' => false, 'message' => 'فرم پرداخت یافت نشد.', 'redirect' => null, 'transaction' => $tx];
        }

        $amountToman = PaymentMoney::toGatewayToman($tx->amount);
        $description = $form->purpose ?: $form->title;
        $gw = $this->gateway();
        $result = $gw->requestPayment($amountToman, $callbackUrl, $description, $tx->number);

        if (!$result->ok || ($result->payload['authority'] ?? '') === '') {
            $this->txs->markFailed($tx->id, substr($result->message, 0, 255));
            return ['ok' => false, 'message' => $result->message, 'redirect' => null, 'transaction' => $this->txs->find($tx->id)];
        }

        $authority = (string) $result->payload['authority'];
        $this->txs->assignAuthority($tx->id, $authority, $tx->status);

        $redirectData = $gw->redirectUrl(['authority' => $authority]);
        if (!$redirectData['ok']) {
            return ['ok' => false, 'message' => $redirectData['message'], 'redirect' => null, 'transaction' => $this->txs->find($tx->id)];
        }

        return [
            'ok' => true,
            'message' => $result->message,
            'redirect' => $redirectData['url'],
            'transaction' => $this->txs->find($tx->id),
        ];
    }
/**
     * Stage 3 — handle the gateway callback.
     *
     * The callback is parsed but the transaction is only marked PAID after
     * server-side verification succeeds. Idempotent for duplicate callbacks.
     *
     * @return array{ok:bool, message:string, transaction:?PaymentTransaction}
     */
    public function handleCallback(string $authority, array $callbackParams): array
    {
        if ($authority === '') {
            return ['ok' => false, 'message' => 'کد مرجع پرداخت یافت نشد.', 'transaction' => null];
        }
        $tx = $this->txs->findByAuthority($authority);
        if ($tx === null) {
            return ['ok' => false, 'message' => 'تراکنش مرتبط با این پرداخت یافت نشد.', 'transaction' => null];
        }

        $gw = $this->gateway();
        $parsed = $gw->parseCallback($callbackParams);
        if (!$parsed->ok) {
            // User did not complete payment (cancelled / failed at gateway).
            $this->txs->markFailed($tx->id, 'پرداخت انجام نشد یا لغو شد.');
            return ['ok' => false, 'message' => $parsed->message, 'transaction' => $this->txs->find($tx->id)];
        }

        // Record callback arrival (guard: must match the pending authority).
        $this->txs->markCallbackReceived($tx->id, $authority);
        $this->txs->markVerifying($tx->id);

        $amountToman = PaymentMoney::toGatewayToman($tx->amount);
        $verify = $gw->verifyPayment($authority, $amountToman);

        if (!$verify->ok) {
            $this->txs->markFailed($tx->id, substr($verify->message, 0, 255));
            return ['ok' => false, 'message' => $verify->message, 'transaction' => $this->txs->find($tx->id)];
        }

        $refId = (string) ($verify->payload['ref_id'] ?? '');
        // Idempotent: markPaid returns false if already paid — treat as handled.
        $markPaid = $this->txs->markPaid(
            $tx->id,
            $authority,
            $refId,
            json_encode($verify->payload, JSON_UNESCAPED_UNICODE)
        );

        $fresh = $this->txs->find($tx->id);

        if ($markPaid && $fresh !== null && $fresh->isPaid()) {
            // Only notify on the FIRST transition to paid (SMS service is
            // idempotent via payment_notifications UNIQUE constraint).
            $sms = new SmsService($this->db);
            $sms->notifyPaid($fresh);
        }

        $msg = $markPaid
            ? 'پرداخت با موفقیت تایید شد.'
            : ($fresh !== null && $fresh->isPaid()
                ? 'پرداخت قبلا تایید شده است.'
                : 'بررسی پرداخت ناقص بود.');
        return ['ok' => $fresh !== null && $fresh->isPaid(), 'message' => $msg, 'transaction' => $fresh];
    }
}