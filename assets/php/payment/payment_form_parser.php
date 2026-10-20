<?php
/**
 * payment_form_parser.php — Maps administrator POST input into payment domain
 * models. Strict server-side validation; nothing from the client is trusted.
 *
 * Amounts are normalized to integers (IRR/ریال). Field keys are whitelisted.
 * URLs are validated to http/https to prevent protocol-relative/script links.
 */
declare(strict_types=1);

final class PaymentFieldTypes
{
    public const ALL = [
        'name', 'mobile', 'email', 'description',
        'text', 'textarea', 'number', 'select', 'radio', 'checkbox',
    ];
}

final class PaymentThemes
{
    public const ALL = ['default', 'clean'];
}

final class PaymentFormParser
{
    /** Best-effort Persian→Latin transliteration for clean URL slugs. */
    public static function slugify(string $value): string
    {
        $value = trim($value);
        $map = [
            'آ' => 'a', 'ا' => 'a', 'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's',
            'ج' => 'j', 'چ' => 'ch', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'z',
            'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
            'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f',
            'ق' => 'gh', 'ک' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
            'و' => 'v', 'ه' => 'h', 'ی' => 'y',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];
        $out = strtr($value, $map);
        $out = mb_strtolower($out, 'UTF-8');
        $out = preg_replace('/[^a-z0-9]+/', '-', $out);
        $out = trim((string) $out, '-');
        if ($out === '') {
            $out = 'form-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        return $out;
    }

    /**
     * Build a PaymentForm from POST fields.
     */
    public static function buildFromPost(array $in, ?PaymentForm $existing = null): PaymentForm
    {
        $f = $existing ?? new PaymentForm();

        $title = trim((string) ($in['title'] ?? ''));
        $slug = trim((string) ($in['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::slugify($title);
        } else {
            $slug = preg_replace('/[^a-zA-Z0-9\-_]/', '-', strtolower($slug));
            $slug = trim((string) $slug, '-');
        }

        $amountMode = (string) ($in['amount_mode'] ?? 'fixed');
        if (!in_array($amountMode, ['fixed', 'custom', 'selectable'], true)) {
            $amountMode = 'fixed';
        }

        $theme = (string) ($in['theme'] ?? 'default');
        if (!in_array($theme, PaymentThemes::ALL, true)) {
            $theme = 'default';
        }

        $redirectUrl = trim((string) ($in['redirect_url'] ?? ''));
        if ($redirectUrl !== '' && !self::isSafeUrl($redirectUrl)) {
            $redirectUrl = '';
        }

        $expiresAt = trim((string) ($in['expires_at'] ?? ''));
        if ($expiresAt !== '' && !self::isValidDate($expiresAt)) {
            $expiresAt = '';
        }
        if ($expiresAt === '') {
            $expiresAt = null;
        }

        $f->title = $title;
        $f->slug = $slug;
        $f->description = self::thisNull($in['description'] ?? null);
        $f->amountMode = $amountMode;
        $f->fixedAmount = PaymentMoney::normalize($in['fixed_amount'] ?? '0');
        $f->minAmount = PaymentMoney::normalize($in['min_amount'] ?? '0');
        $f->maxAmount = PaymentMoney::normalize($in['max_amount'] ?? '0');
        $f->purpose = self::thisNull($in['purpose'] ?? null);
        $f->isActive = !empty($in['is_active']);
        $f->expiresAt = $expiresAt;
        $f->successMessage = self::thisNull($in['success_message'] ?? null);
        $f->redirectUrl = $redirectUrl !== '' ? $redirectUrl : null;
        $f->redirectDelaySeconds = PaymentMoney::normalize($in['redirect_delay_seconds'] ?? '0');
        $f->notifyCustomer = !empty($in['notify_customer']);
        $f->notifyAdmin = !empty($in['notify_admin']);
        $f->theme = $theme;

        return $f;
    }
    /**
     * Build dynamic PaymentFormField[] from parallel POST arrays.
     *
     * @return PaymentFormField[]
     */
    public static function buildFieldsFromPost(array $in): array
    {
        $fields = [];
        $keys = $in['field_key'] ?? [];
        if (!is_array($keys)) {
            return $fields;
        }
        foreach ($keys as $i => $rawKey) {
            $key = trim((string) $rawKey);
            if ($key === '' || !preg_match('/^[a-z0-9_]{1,80}$/i', $key)) {
                continue;
            }
            $type = (string) ($in['field_type'][$i] ?? 'text');
            if (!in_array($type, PaymentFieldTypes::ALL, true)) {
                $type = 'text';
            }

            $field = new PaymentFormField();
            $field->key = $key;
            $field->label = trim((string) ($in['field_label'][$i] ?? ''));
            $field->type = $type;
            $field->required = !empty($in['field_required'][$i]);
            $field->placeholder = self::thisNull($in['field_placeholder'][$i] ?? null);
            $field->defaultValue = self::thisNull($in['field_default'][$i] ?? null);

            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $rawOptions = $in['field_options'][$i] ?? '';
                $field->options = self::parseOptionList($rawOptions);
            }
            if ($type === 'number') {
                $field->validation = ['numeric' => true];
            }

            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * Build selectable amount options from POST.
     *
     * @return int[]
     */
    public static function buildAmountsFromPost(array $in): array
    {
        $amounts = [];
        $raw = $in['amount_options'] ?? [];
        if (!is_array($raw)) {
            return $amounts;
        }
        foreach ($raw as $v) {
            $n = PaymentMoney::normalize($v);
            if ($n > 0) {
                $amounts[] = $n;
            }
        }
        $amounts = array_values(array_unique($amounts));
        sort($amounts);
        return $amounts;
    }

    /**
     * @return string[]
     */
    public static function parseOptionList($raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } else {
            $items = preg_split('/[\r\n,]+/', (string) $raw);
        }
        $out = [];
        foreach ((array) $items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = mb_substr($item, 0, 255, 'UTF-8');
            }
        }
        return array_values(array_unique($out));
    }

    private static function isSafeUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https'], true);
    }

    private static function isValidDate(string $value): bool
    {
        $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i'];
        foreach ($formats as $fmt) {
            $d = DateTimeImmutable::createFromFormat($fmt, $value);
            if ($d instanceof DateTimeImmutable) {
                return true;
            }
        }
        return false;
    }

    private static function thisNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }
}
