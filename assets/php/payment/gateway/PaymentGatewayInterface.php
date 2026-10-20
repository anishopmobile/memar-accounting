<?php
/**
 * PaymentGatewayInterface.php — Gateway/service abstraction.
 *
 * Concrete providers (e.g. ZarinPalGateway) implement this contract so the
 * payment flow never depends on a specific provider. All responses are
 * normalized into a PaymentGatewayResult value object. Callback arrival alone
 * is never a success — the caller must always run verification().
 */
declare(strict_types=1);

/**
 * Normalized gateway operation result.
 */
final class PaymentGatewayResult
{
    public bool $ok;
    public string $message;
    /** @var array<string,mixed> */
    public array $data;
    public bool $alreadyVerified = false;

    /** @param array<string,mixed> $data */
    public function __construct(bool $ok, string $message = '', array $data = [])
    {
        $this->ok = $ok;
        $this->message = $message;
        $this->data = $data;
    }
}

interface PaymentGatewayInterface
{
    /**
     * Request a new payment and return an authority / redirect URL.
     *
     * @param int    $amountToman amount in the gateway unit (Toman)
     * @param string $callbackUrl full callback URL
     * @param string $description payment description
     * @param string $reference   internal transaction number (metadata)
     * @return PaymentGatewayResult with 'authority' in payload on success
     */
    public function requestPayment(int $amountToman, string $callbackUrl, string $description, string $reference): PaymentGatewayResult;

    /**
     * Build the redirect URL for the customer to pay at the gateway.
     * @return array{ok:bool, url:string, message:string}
     */
    public function redirectUrl(array $authorityData): array;

    /**
     * Parse the callback query/hash the gateway returns. This is NOT a
     * verification — it only extracts status + authority.
     * @param array<string,mixed> $callbackParams
     * @return PaymentGatewayResult with 'status' and 'authority' in payload
     */
    public function parseCallback(array $callbackParams): PaymentGatewayResult;

    /**
     * Server-side verification. Must be called before marking any transaction
     * as paid. Must be idempotent-safe for duplicate callbacks.
     *
     * @return PaymentGatewayResult with 'ref_id' in payload on success
     */
    public function verifyPayment(string $authority, int $amountToman): PaymentGatewayResult;
}