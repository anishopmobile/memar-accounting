<?php
/**
 * payment_rate_limiter.php — Client-side abuse throttle for public endpoints.
 *
 * Protects public payment submission from unbounded transaction/gateway spam.
 * Uses a server-side row keyed by scope (e.g. client IP) with a sliding window.
 * All SQL is prepared; the scope key is always derived server-side.
 */
declare(strict_types=1);

final class PaymentRateLimiter
{
    private \mysqli $db;

    /** @var array<string,array{limit:int,window:int}> default configs */
    private array $windows = [
        'submit' => ['limit' => 10, 'window' => 3600],   // 10 submits / hour / IP
        'callback' => ['limit' => 60, 'window' => 3600], // duplicate-callback abuse guard
    ];

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * True if the scope is allowed to proceed; false if the limit is exceeded.
     * The window check and counter update happen atomically in a single MySQL
     * statement (using the server clock) so PHP/MySQL timezone skew is irrelevant.
     */
    public function allow(string $scope, string $bucket = 'submit'): bool
    {
        $cfg = $this->windows[$bucket] ?? $this->windows['submit'];
        $limit = $cfg['limit'];
        $windowSeconds = $cfg['window'];

        $key = $this->hashScope($scope);

        try {
            // Atomic upsert: reset the window on expiry, otherwise increment.
            $stmt = $this->db->prepare(
                "INSERT INTO payment_rate_limits (scope_key, window_start, attempt_count)
                 VALUES (?, NOW(), 1)
                 ON DUPLICATE KEY UPDATE
                    attempt_count = IF(window_start <= (NOW() - INTERVAL ? SECOND), 1, attempt_count + 1),
                    window_start  = IF(window_start <= (NOW() - INTERVAL ? SECOND), NOW(), window_start)"
            );
            $stmt->bind_param('sii', $key, $windowSeconds, $windowSeconds);
            $stmt->execute();
            $stmt->close();

            // Read back the effective counter for this scope.
            $sel = $this->db->prepare('SELECT attempt_count FROM payment_rate_limits WHERE scope_key = ? LIMIT 1');
            $sel->bind_param('s', $key);
            $sel->execute();
            $res = $sel->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $sel->close();

            $count = $row ? (int) $row['attempt_count'] : 1;
            return $count <= $limit;
        } catch (\mysqli_sql_exception $e) {
            // Fail-open so a rate-limit error never blocks a legitimate payment.
            return true;
        }
    }

    /** Derive a stable, bounded scope key from client IP. */
    private function hashScope(string $scope): string
    {
        return substr(hash('sha256', $scope), 0, 64);
    }

    public function clientIp(): string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', (string) $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}