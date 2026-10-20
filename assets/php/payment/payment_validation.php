<?php
/**
 * payment_validation.php — Strict server-side validation for the payment domain.
 *
 * Never trusts the client. Resolves the authoritative amount from the stored
 * form definition (fixed / min-max custom / selectable whitelist) instead of
 * echoing back whatever the browser submitted.
 */
declare(strict_types=1);

final class PaymentValidator
{
    /**
     * Resolve and validate the payable amount using the stored form definition.
     *
     * @return array{ok:bool, message:string, amount:int}
     */
    public static function resolveAmount(PaymentForm $form, $submitted): array
    {
        $amount = PaymentMoney::normalize($submitted);

        if ($form->isFixed()) {
            // Fixed forms ignore the client entirely.
            $amount = $form->authoritativeAmount();
            if (!PaymentMoney::isPositive($amount)) {
                return ['ok' => false, 'message' => 'مبلغ پرداخت در فرم تعیین نشده است.', 'amount' => 0];
            }
            return ['ok' => true, 'message' => '', 'amount' => $amount];
        }

        if ($form->isCustom()) {
            if (!PaymentMoney::isPositive($amount)) {
                return ['ok' => false, 'message' => 'مبلغ پرداخت نامعتبر است.', 'amount' => 0];
            }
            if ($form->minAmount > 0 && $amount < $form->minAmount) {
                return ['ok' => false, 'message' => 'مبلغ کمتر از حداقل مجاز است.', 'amount' => 0];
            }
            if ($form->maxAmount > 0 && $amount > $form->maxAmount) {
                return ['ok' => false, 'message' => 'مبلغ بیشتر از حداکثر مجاز است.', 'amount' => 0];
            }
            return ['ok' => true, 'message' => '', 'amount' => $amount];
        }

        // Selectable: amount must be one of the form's stored options.
        if (!in_array($amount, $form->amountOptions, true)) {
            return ['ok' => false, 'message' => 'مبلغ انتخابی مجاز نیست.', 'amount' => 0];
        }
        return ['ok' => true, 'message' => '', 'amount' => $amount];
    }

    /**
     * Validate dynamic form field submissions against the stored field defs.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool, message:string, data:array}
     */
    public static function validateFields(PaymentForm $form, array $input): array
    {
        $data = [];
        foreach ($form->fields as $field) {
            $raw = $input[$field->key] ?? '';
            $value = self::normalizeFieldValue($field, $raw);

            if ($field->required && ($value === '' || $value === null)) {
                return ['ok' => false, 'message' => 'فیلد «' . $field->label . '» الزامی است.', 'data' => $data];
            }
            if ($value !== '' && $value !== null) {
                $check = self::validateFieldValue($field, $value);
                if (!$check['ok']) {
                    return ['ok' => false, 'message' => $check['message'], 'data' => $data];
                }
            }
            $data[$field->key] = $value === '' ? null : $value;
        }
        return ['ok' => true, 'message' => '', 'data' => $data];
    }

    private static function normalizeFieldValue(PaymentFormField $field, $raw)
    {
        if (is_array($raw)) {
            return $raw; // checkbox may submit an array
        }
        return trim((string) $raw);
    }

    /**
     * @return array{ok:bool, message:string}
     */
    private static function validateFieldValue(PaymentFormField $field, $value): array
    {
        $type = $field->type;
        switch ($type) {
            case 'email':
                if (mb_strlen((string) $value, 'UTF-8') > 255) {
                    return ['ok' => false, 'message' => 'ایمیل بیش از حد مجاز است.'];
                }
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ['ok' => false, 'message' => 'ایمیل نامعتبر است.'];
                }
                break;
            case 'mobile':
                $s = (string) $value;
                if (!preg_match('/^09\d{9}$/', $s)) {
                    return ['ok' => false, 'message' => 'شماره موبایل نامعتبر است.'];
                }
                break;
            case 'number':
                $normalized = PaymentMoney::normalize($value);
                if ($normalized <= 0) {
                    return ['ok' => false, 'message' => 'عدد وارد شده نامعتبر است.'];
                }
                break;
            case 'select':
            case 'radio':
                if ($field->options !== [] && !in_array((string) $value, $field->options, true)) {
                    return ['ok' => false, 'message' => 'گزینه انتخابی مجاز نیست.'];
                }
                break;
            case 'checkbox':
                if (is_array($value)) {
                    foreach ($value as $v) {
                        if ($field->options !== [] && !in_array((string) $v, $field->options, true)) {
                            return ['ok' => false, 'message' => 'گزینه انتخابی مجاز نیست.'];
                        }
                    }
                }
                break;
            case 'text':
            case 'textarea':
            case 'name':
            case 'description':
            default:
                if (mb_strlen((string) $value, 'UTF-8') > 5000) {
                    return ['ok' => false, 'message' => 'متن بیش از حد مجاز است.'];
                }
                break;
        }

        $max = $field->validation['max_length'] ?? null;
        if ($max !== null && mb_strlen((string) $value, 'UTF-8') > (int) $max) {
            return ['ok' => false, 'message' => 'فیلد «' . $field->label . '» بیش از حد مجاز است.'];
        }
        return ['ok' => true, 'message' => ''];
    }

    /** Validate a transaction number format. */
    public static function isValidNumber(string $number): bool
    {
        return $number !== '' && preg_match('/^[A-Z0-9-]+$/', $number) === 1;
    }
}