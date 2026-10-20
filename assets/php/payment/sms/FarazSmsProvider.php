<?php
/**
 * FarazSmsProvider.php — Faraz SMS (IranPayamak) REST API integration.
 *
 * Official docs: https://docs.farazsms.com/
 *   Base URL: https://api.iranpayamak.com
 *   Auth:     header `Api-Key`
 *   Pattern:  POST /ws/v1/sms/pattern  (code, line_number, number_format, recipient, values)
 *   Simple:   POST /ws/v1/sms/simple    (text, line_number, number_format)
 *   Balance:  GET  /ws/v1/account/balance  (free API key test)
 *
 * Credentials come from stored settings — never hard-coded here.
 * Recipients are 09xxxxxxxxx (no +98), per official docs.
 */
declare(strict_types=1);

final class FarazSmsProvider implements SmsProviderInterface
{
    private const BASE_URL = 'https://api.iranpayamak.com';

    private string $apiKey;
    private string $senderLine;
    /** @var int|string number_format expected by the API (usually 1 = plain mobile) */
    private $numberFormat;

    /** @param int|string $numberFormat */
    public function __construct(string $apiKey, string $senderLine = '', $numberFormat = 1)
    {
        $this->apiKey = trim($apiKey);
        $this->senderLine = trim($senderLine);
        $this->numberFormat = $numberFormat;
    }

    public function sendPattern(string $recipient, string $patternCode, array $values): SmsResult
    {
        if ($this->apiKey === '') {
            return new SmsResult(false, 'کلید API پیامک تنظیم نشده است.');
        }
        if (!$this->isValidIranMobile($recipient)) {
            return new SmsResult(false, 'شماره گیرنده معتبر نیست.');
        }
        if ($patternCode === '') {
            return new SmsResult(false, 'کد پترن پیامک تنظیم نشده است.');
        }

        $body = [
            'code'          => $patternCode,
            'line_number'   => $this->senderLine,
            'number_format' => $this->numberFormat,
            'recipient'     => $recipient,
        ];
        if ($values !== []) {
            $body['values'] = $values;
        }

        return $this->post('/ws/v1/sms/pattern', $body);
    }

    public function sendSimple(string $recipient, string $text): SmsResult
    {
        if ($this->apiKey === '') {
            return new SmsResult(false, 'کلید API پیامک تنظیم نشده است.');
        }
        if (!$this->isValidIranMobile($recipient)) {
            return new SmsResult(false, 'شماره گیرنده معتبر نیست.');
        }
        if (trim($text) === '') {
            return new SmsResult(false, 'متن پیامک خالی است.');
        }

        $body = [
            'text'          => $text,
            'line_number'   => $this->senderLine,
            'number'        => $recipient,
        ];
        return $this->post('/ws/v1/sms/simple', $body);
    }

    public function testConnection(): SmsResult
    {
        if ($this->apiKey === '') {
            return new SmsResult(false, 'کلید API پیامک تنظیم نشده است.');
        }

        $ch = curl_init(self::BASE_URL . '/ws/v1/account/balance');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Api-Key: ' . $this->apiKey,
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return new SmsResult(false, 'خطا در ارتباط با سرویس پیامک (کد ' . $errno . ').');
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return new SmsResult(false, 'پاسخ نامعتبر از سرویس پیامک دریافت شد.');
        }
        // The balance endpoint returns {status: "error", message: "Api-Key not valid"} for bad keys.
        if (isset($decoded['status']) && $decoded['status'] === 'error') {
            return new SmsResult(false, (string) ($decoded['message'] ?? 'کلید API پیامک معتبر نیست.'));
        }
        if (isset($decoded['http_error']) && !empty($decoded['http_error'])) {
            return new SmsResult(false, 'کلید API پیامک معتبر نیست.');
        }
        return new SmsResult(true, 'اتصال به سرویس پیامک برقرار است.', $decoded);
    }

    /** POST JSON body to an API path without leaking the key. */
    private function post(string $path, array $body, int $timeout = 20): SmsResult
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Api-Key: ' . $this->apiKey,
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
            return new SmsResult(false, 'خطا در ارتباط با سرویس پیامک (کد ' . $errno . ').');
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return new SmsResult(false, 'پاسخ نامعتبر از سرویس پیامک دریافت شد.');
        }
        if (isset($decoded['errors']) && !empty($decoded['errors'])) {
            $msg = (string) ($decoded['errors']['message'] ?? 'خطای سرویس پیامک.');
            $code = (string) ($decoded['errors']['code'] ?? '');
            return new SmsResult(false, 'سرویس پیامک: ' . $msg . ($code !== '' ? ' (' . $code . ')' : ''));
        }
        if (isset($decoded['success']) && $decoded['success'] === false) {
            return new SmsResult(false, 'سرویس پیامک درخواست را رد کرد.');
        }
        return new SmsResult(true, 'پیامک با موفقیت ارسال شد.', $decoded);
    }

    private function isValidIranMobile(string $mobile): bool
    {
        return (bool) preg_match('/^09\d{9}$/', $mobile);
    }
}