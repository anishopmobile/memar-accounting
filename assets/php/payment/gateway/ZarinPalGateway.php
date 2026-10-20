<?php
/**
 * ZarinPalGateway.php — Current ZarinPal REST API v4 integration.
 *
 * Endpoints (v4):
 *   request  POST https://api.zarinpal.com/pg/v4/payment/request.json
 *   start    GET  https://www.zarinpal.com/pg/StartPay/{authority}
 *   verify   POST https://api.zarinpal.com/pg/v4/payment/verify.json
 *
 * The gateway's `amount` is in Toman, so the internal IRR integer amount is
 * converted at this boundary via PaymentMoney::toGatewayToman().
 *
 * Credentials are supplied from stored settings — never hard-coded here.
 */
declare(strict_types=1);

final class ZarinPalGateway implements PaymentGatewayInterface
{
    private const LIVE_REQUEST = 'https://api.zarinpal.com/pg/v4/payment/request.json';
    private const LIVE_VERIFY  = 'https://api.zarinpal.com/pg/v4/payment/verify.json';
    private const LIVE_START   = 'https://www.zarinpal.com/pg/StartPay/';

    private const SANDBOX_REQUEST = 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';
    private const SANDBOX_VERIFY  = 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json';
    private const SANDBOX_START   = 'https://sandbox.zarinpal.com/pg/StartPay/';

    /** Compatible success/verify codes. 100 = success, 101 = already verified. */
    private const CODE_SUCCESS = 100;
    private const CODE_ALREADY_VERIFIED = 101;

    private string $merchantId;
    private bool $sandbox;

    public function __construct(string $merchantId, bool $sandbox = false)
    {
        $this->merchantId = trim($merchantId);
        $this->sandbox = $sandbox;
    }

    public function requestPayment(int $amountToman, string $callbackUrl, string $description, string $reference): PaymentGatewayResult
    {
        if ($this->merchantId === '') {
            return new PaymentGatewayResult(false, 'اطلاعات درگاه زرین‌پال تنظیم نشده است.');
        }
        if ($amountToman <= 0) {
            return new PaymentGatewayResult(false, 'مبلغ پرداخت نامعتبر است.');
        }
        if ($callbackUrl === '' || !filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            return new PaymentGatewayResult(false, 'آدرس بازگشت (callback) نامعتبر است.');
        }

        $body = [
            'merchant_id' => $this->merchantId,
            'amount'      => $amountToman,
            'callback_url'=> $callbackUrl,
            'description' => mb_substr($description, 0, 255, 'UTF-8'),
            'metadata'    => ['reference' => $reference],
        ];
        $result = $this->post($this->requestUrl(), $body);
        if (!$result['ok']) {
            return new PaymentGatewayResult(false, $result['message']);
        }

        $payload = $result['json'];
        $data = $payload['data'] ?? null;
        if ($data === null || !isset($data['authority'])) {
            return new PaymentGatewayResult(false, $this->friendlyError($payload));
        }

        return new PaymentGatewayResult(true, 'درخواست پرداخت ثبت شد.', [
            'authority' => (string) $data['authority'],
            'code' => (int) ($data['code'] ?? 0),
        ]);
    }

    public function redirectUrl(array $authorityData): array
    {
        $auth = (string) ($authorityData['authority'] ?? '');
        if ($auth === '') {
            return ['ok' => false, 'url' => '', 'message' => 'کد مرجع (authority) یافت نشد.'];
        }
        return ['ok' => true, 'url' => $this->startBase() . rawurlencode($auth), 'message' => ''];
    }

    public function parseCallback(array $callbackParams): PaymentGatewayResult
    {
        $status = (string) ($callbackParams['Status'] ?? '');
        $authority = (string) ($callbackParams['Authority'] ?? '');
        if ($status !== 'OK' || $authority === '') {
            return new PaymentGatewayResult(false, 'پرداخت انجام نشد یا توسط کاربر لغو شد.', [
                'status' => $status,
                'authority' => $authority,
            ]);
        }
        return new PaymentGatewayResult(true, 'کاربر از درگاه بازگشت.', [
            'status' => $status,
            'authority' => $authority,
        ]);
    }

    public function verifyPayment(string $authority, int $amountToman): PaymentGatewayResult
    {
        if ($this->merchantId === '') {
            return new PaymentGatewayResult(false, 'اطلاعات درگاه تنظیم نشده است.');
        }
        if ($authority === '' || $amountToman <= 0) {
            return new PaymentGatewayResult(false, 'پارامترهای بررسی نامعتبر است.');
        }

        $body = [
            'merchant_id' => $this->merchantId,
            'authority'   => $authority,
            'amount'      => $amountToman,
        ];
        $result = $this->post($this->verifyUrl(), $body);
        if (!$result['ok']) {
            return new PaymentGatewayResult(false, $result['message']);
        }

        $payload = $result['json'];
        $data = $payload['data'] ?? null;
        $code = (int) ($data['code'] ?? 0);

        if ($data === null) {
            return new PaymentGatewayResult(false, $this->friendlyError($payload));
        }
        if ($code === self::CODE_ALREADY_VERIFIED) {
            return new PaymentGatewayResult(true, 'این پرداخت قبلا تایید شده است.', [
                'ref_id' => (string) ($data['ref_id'] ?? ''),
                'already_verified' => true,
            ]);
        }
        if ($code === self::CODE_SUCCESS) {
            return new PaymentGatewayResult(true, 'پرداخت تایید شد.', [
                'ref_id' => (string) ($data['ref_id'] ?? ''),
                'already_verified' => false,
            ]);
        }
        return new PaymentGatewayResult(false, $this->friendlyError($payload));
    }

    /** Plain POST with JSON body, safe timeout, no secrets logged. */
    private function post(string $url, array $body, int $timeout = 30): array
    {
        $json = json_encode($body);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return ['ok' => false, 'message' => 'خطا در ارتباط با درگاه پرداخت (کد ' . $errno . ').', 'json' => null];
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return ['ok' => false, 'message' => 'پاسخ نامعتبر از درگاه دریافت شد.', 'json' => null];
        }
        return ['ok' => true, 'message' => '', 'json' => $decoded];
    }

    /** Safe human-friendly message, never echoing raw gateway internals. */
    private function friendlyError($payload): string
    {
        $msg = (string) ($payload['data']['message'] ?? '');
        if ($msg !== '') {
            return 'درگاه پرداخت: ' . $msg;
        }
        $code = (string) ($payload['errors']['code'] ?? '');
        $errMsg = (string) ($payload['errors']['message'] ?? '');
        if ($errMsg !== '') {
            return 'خطای درگاه پرداخت (' . ($code !== '' ? $code : 'نامشخص') . ').';
        }
        return 'پرداخت در درگاه ناموفق بود.';
    }

    private function requestUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_REQUEST : self::LIVE_REQUEST;
    }
    private function verifyUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_VERIFY : self::LIVE_VERIFY;
    }
    private function startBase(): string
    {
        return $this->sandbox ? self::SANDBOX_START : self::LIVE_START;
    }
}