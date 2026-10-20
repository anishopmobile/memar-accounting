<?php
/**
 * payment_settings_repository.php — Reads ZarinPal / SMS settings.
 *
 * Credentials are stored server-side only and are NEVER returned raw to a
 * response that could be shown in a browser. Masked copies are provided for
 * UI display.
 */
declare(strict_types=1);

final class PaymentSettingsRepository
{
    public function __construct(private \mysqli $db)
    {
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM payment_settings WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: [];
    }

    /**
     * Update ZarinPal settings. Empty credential fields keep the stored value
     * so masked secrets are not accidentally wiped by a partial form POST.
     */
    public function saveZarinpal(array $in): bool
    {
        $current = $this->get();
        $merchant = trim((string) ($in['merchant_id'] ?? ''));
        if ($merchant === '') {
            $merchant = (string) ($current['zarinpal_merchant_id'] ?? '');
        }
        $merchantId = $merchant;
        $callbackUrl = trim((string) ($in['zarinpal_callback_url'] ?? ''));
        if ($callbackUrl === '') {
            $callbackUrl = (string) ($current['zarinpal_callback_url'] ?? '');
        }
        $description = trim((string) ($in['zarinpal_default_description'] ?? ''));
        if ($description === '') {
            $description = (string) ($current['zarinpal_default_description'] ?? '');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO payment_settings
                (id, zarinpal_merchant_id, zarinpal_sandbox, zarinpal_gateway_enabled,
                 zarinpal_callback_url, zarinpal_default_description)
             VALUES (1, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                zarinpal_merchant_id = VALUES(zarinpal_merchant_id),
                zarinpal_sandbox = VALUES(zarinpal_sandbox),
                zarinpal_gateway_enabled = VALUES(zarinpal_gateway_enabled),
                zarinpal_callback_url = VALUES(zarinpal_callback_url),
                zarinpal_default_description = VALUES(zarinpal_default_description)'
        );
        $sandbox = empty($in['zarinpal_sandbox']) ? 0 : 1;
        $enabled = empty($in['zarinpal_gateway_enabled']) ? 0 : 1;
        $stmt->bind_param(
            'siiss',
            $merchantId,
            $sandbox,
            $enabled,
            $callbackUrl,
            $description
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /** Mask a secret for safe UI display (never reveal the full value). */
    public static function maskSecret(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $len = strlen($value);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 3) . str_repeat('*', $len - 6) . substr($value, -3);
    }

    /**
     * Update Faraz SMS settings. Empty API key keeps the stored value so the
     * masked secret is not wiped by a partial form POST.
     */
    public function saveSms(array $in): bool
    {
        $current = $this->get();
        $apiKey = trim((string) ($in['sms_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = (string) ($current['sms_api_key'] ?? '');
        }
        $sender = trim((string) ($in['sms_sender_line'] ?? ''));
        if ($sender === '') {
            $sender = (string) ($current['sms_sender_line'] ?? '');
        }

        $customerEnabled = empty($in['sms_customer_enabled']) ? 0 : 1;
        $adminEnabled = empty($in['sms_admin_enabled']) ? 0 : 1;

        $stmt = $this->db->prepare(
            'INSERT INTO payment_settings
                (id, sms_api_key, sms_sender_line, sms_customer_enabled, sms_admin_enabled,
                 sms_success_pattern_code, sms_admin_pattern_code, sms_admin_mobile)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                sms_api_key = VALUES(sms_api_key),
                sms_sender_line = VALUES(sms_sender_line),
                sms_customer_enabled = VALUES(sms_customer_enabled),
                sms_admin_enabled = VALUES(sms_admin_enabled),
                sms_success_pattern_code = VALUES(sms_success_pattern_code),
                sms_admin_pattern_code = VALUES(sms_admin_pattern_code),
                sms_admin_mobile = VALUES(sms_admin_mobile)'
        );
        $successPattern = trim((string) ($in['sms_success_pattern_code'] ?? ''));
        $adminPattern = trim((string) ($in['sms_admin_pattern_code'] ?? ''));
        $adminMobile = trim((string) ($in['sms_admin_mobile'] ?? ''));
        $stmt->bind_param(
            'ssiisss',
            $apiKey,
            $sender,
            $customerEnabled,
            $adminEnabled,
            $successPattern,
            $adminPattern,
            $adminMobile
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Return the configured default payment form id (or null).
     */
    public function defaultFormId(): ?int
    {
        $s = $this->get();
        $id = (int) ($s['default_form_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Mark a form as the default public payment form.
     */
    public function setDefaultForm(?int $formId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO payment_settings (id, default_form_id) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE default_form_id = VALUES(default_form_id)'
        );
        $stmt->bind_param('i', $formId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}