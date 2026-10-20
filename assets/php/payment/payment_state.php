<?php
/**
 * payment_state.php — Payment transaction state machine.
 *
 * Callback arrival is NOT payment success. A transaction only becomes
 * "paid" after server-side gateway verification, and only once — the
 * repository enforces guarded transitions (idempotency at the row level).
 */
declare(strict_types=1);

final class PaymentStateMachine
{
    public const CREATED = 'created';
    public const PENDING = 'pending';
    public const REDIRECTED = 'redirected';
    public const CALLBACK_RECEIVED = 'callback_received';
    public const VERIFYING = 'verifying';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public const STATUSES = [
        self::CREATED,
        self::PENDING,
        self::REDIRECTED,
        self::CALLBACK_RECEIVED,
        self::VERIFYING,
        self::PAID,
        self::FAILED,
        self::CANCELLED,
        self::EXPIRED,
    ];

    /**
     * Allowed transitions. Terminal states (paid/failed/cancelled/expired)
     * have no outgoing transitions, so a paid transaction can never change.
     */
    private const TRANSITIONS = [
        self::CREATED           => [self::PENDING, self::CANCELLED, self::EXPIRED],
        self::PENDING           => [self::REDIRECTED, self::CALLBACK_RECEIVED, self::CANCELLED, self::EXPIRED],
        self::REDIRECTED        => [self::CALLBACK_RECEIVED, self::VERIFYING, self::EXPIRED],
        self::CALLBACK_RECEIVED => [self::VERIFYING],
        self::VERIFYING         => [self::PAID, self::FAILED, self::EXPIRED],
        self::PAID              => [],
        self::FAILED            => [],
        self::CANCELLED         => [],
        self::EXPIRED           => [],
    ];

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function label(string $status): string
    {
        $labels = [
            self::CREATED           => 'ایجاد شده',
            self::PENDING           => 'در انتظار پرداخت',
            self::REDIRECTED        => 'ارجاع به درگاه',
            self::CALLBACK_RECEIVED => 'بازگشت از درگاه',
            self::VERIFYING         => 'در حال بررسی',
            self::PAID              => 'پرداخت شده',
            self::FAILED            => 'ناموفق',
            self::CANCELLED         => 'لغو شده',
            self::EXPIRED           => 'منقضی شده',
        ];
        return $labels[$status] ?? $status;
    }
}