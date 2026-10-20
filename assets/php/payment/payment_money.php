<?php
/**
 * payment_money.php — integer-only monetary helpers for the payment module.
 *
 * The application's established monetary unit is IRR (ریال).
 * All stored amounts are integers. No floating-point arithmetic is used
 * anywhere in the payment domain. Any unit conversion to a gateway's
 * expected currency happens only at the gateway boundary through
 * toGatewayToman() and is isolated here so the unit can be re-verified
 * in one place during the ZarinPal integration phase.
 */
declare(strict_types=1);

final class PaymentMoney
{
    /** Maximum supported magnitude (15 digits) — far beyond realistic ریال amounts. */
    public const MAX_DIGITS = 15;

    /** Minimum accepted positive payment amount in ریال. */
    public const MIN_AMOUNT = 1000;

    private const PERSIAN_DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Normalize a raw client value into a strict integer.
     * Accepts Persian/Arabic digits and thousands separators.
     * Returns 0 for anything that is not a plain integer string.
     */
    public static function normalize($value): int
    {
        if (is_int($value)) {
            return $value > 0 && abs($value) <= (10 ** self::MAX_DIGITS) ? $value : 0;
        }
        if (is_float($value)) {
            return 0; // floats are never accepted
        }
        $s = trim((string) $value);
        $s = strtr($s, self::PERSIAN_DIGITS);
        $s = str_replace([',', '،', "\u{200C}", ' '], '', $s);
        if ($s === '' || !preg_match('/^\d+$/', $s)) {
            return 0;
        }
        if (strlen($s) > self::MAX_DIGITS) {
            return 0;
        }
        return (int) $s;
    }

    /** Strict positive-integer check (used by validation). */
    public static function isPositive(int $amount): bool
    {
        return $amount > 0;
    }

    /** Format an integer amount for Persian RTL display. */
    public static function format(int $amount): string
    {
        return number_format($amount) . ' ریال';
    }

    /**
     * Gateway-boundary conversion: IRR (ریال) → gateway Toman.
     * Integer division preserves exactness (no floats).
     *
     * NOTE: the current ZarinPal REST API unit (Toman vs Rial) must be
     * re-verified in the gateway phase; this single conversion point is
     * where any adjustment is applied.
     */
    public static function toGatewayToman(int $amountRial): int
    {
        return intdiv($amountRial, 10);
    }
}