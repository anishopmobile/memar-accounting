<?php
/**
 * SmsProviderInterface.php — SMS service abstraction.
 *
 * Concrete providers (e.g. FarazSmsProvider) implement this contract so SMS
 * sending stays decoupled from the payment flow. A provider result is always
 * normalized; providers must NEVER throw on a failed send — they return
 * ok=false with a message. SMS delivery is independent from payment success.
 */
declare(strict_types=1);

/**
 * Normalized SMS provider result.
 */
final class SmsResult
{
    public bool $ok;
    public string $message;
    /** @var array<string,mixed> */
    public array $data;

    /** @param array<string,mixed> $data */
    public function __construct(bool $ok, string $message = '', array $data = [])
    {
        $this->ok = $ok;
        $this->message = $message;
        $this->data = $data;
    }
}

interface SmsProviderInterface
{
    /**
     * Send a pattern-based SMS (instant, transactional).
     *
     * @param string               $recipient   09xxxxxxxxx mobile
     * @param string               $patternCode the approved pattern code
     * @param array<string,string> $values       pattern variable values
     * @return SmsResult
     */
    public function sendPattern(string $recipient, string $patternCode, array $values): SmsResult;

    /**
     * Send a free-text SMS.
     * @return SmsResult
     */
    public function sendSimple(string $recipient, string $text): SmsResult;

    /**
     * Verify the API key works (balance check). Free — safe test.
     * @return SmsResult
     */
    public function testConnection(): SmsResult;
}