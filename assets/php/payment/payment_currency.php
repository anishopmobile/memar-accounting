<?php
/**
 * Currency adapter for the payment subsystem. IMPORTANT:
 *   accounting products/invoices are stored in TOMAN;
 *   payment_forms and payment_transactions are historically stored in IRR.
 * This adapter converts only at UI/input boundaries. It never migrates stored
 * payment rows or modifies ZarinPal request/verification semantics.
 */
declare(strict_types=1);

require_once __DIR__ . '/../currency.php';
require_once __DIR__ . '/payment_money.php';

final class PaymentDisplayCurrency
{
    public static function code(): string
    {
        return getCurrentCurrency();
    }

    /** Convert a historical IRR amount to the site's selected display unit. */
    public static function fromRial(int $amountRial, ?string $currency = null): string
    {
        $currency = currency_assert_code($currency ?? self::code());
        return $currency === 'TOMAN'
            ? currency_shift_decimal((string) $amountRial, -1)
            : (string) $amountRial;
    }

    public static function formatRial(int $amountRial, ?string $currency = null): string
    {
        $currency = currency_assert_code($currency ?? self::code());
        return currency_grouped(self::fromRial($amountRial, $currency)) . ' ' . getCurrencyLabel($currency);
    }

    /**
     * Strict input -> existing integer IRR domain. No float, truncation, or
     * double conversion. TOMAN accepts one decimal digit (0.1 TOMAN = 1 IRR).
     * Blank optional amount is zero; invalid populated input is rejected.
     */
    public static function toRial($amount, ?string $currency = null): int
    {
        $currency = currency_assert_code($currency ?? self::code());
        if (!is_string($amount) && !is_int($amount)) {
            throw new InvalidArgumentException('مبلغ پرداخت باید عدد معتبر باشد.');
        }
        $normalized = currency_decimal($amount === '' ? '0' : $amount);
        $rial = $currency === 'TOMAN' ? currency_shift_decimal($normalized, 1) : $normalized;
        if (!preg_match('/^\d{1,15}$/D', $rial)) {
            throw new InvalidArgumentException('مبلغ پرداخت باید عدد صحیح ریالی با حداکثر ۱۵ رقم باشد.');
        }
        return (int) $rial;
    }
}
