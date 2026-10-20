<?php
/**
 * auth_rate_limiter.php — محافظت پایدار (DB) در برابر حمله‌ی جستجوی رمز
 *
 * برخلاف محدودسازی مبتنی بر session، این سوابق در دیتابیس نگهداری می‌شوند؛
 * پاک کردن کوکی/سشن توسط مهاجم هیچ تأثیری در محافظت ندارد.
 *
 * سیاست (تعداد مجاز، پنجره، مدت قفل) از auth_settings قابل پیکربندی است.
 */

declare(strict_types=1);

/** سیاست جاری ضد جستجوی رمز — از تنظیمات برنامه خوانده می‌شود. */
function auth_rate_policy(?mysqli $db = null): array
{
    $s = auth_settings_get($db);
    return [
        'max_attempts'    => max(1, (int) ($s['brute_max_attempts'] ?? 5)),
        'window_seconds'  => max(1, (int) ($s['brute_window_minutes'] ?? 15)) * 60,
        'lockout_seconds' => max(0, (int) ($s['brute_lockout_minutes'] ?? 15)) * 60,
    ];
}

/** ثبت یک تلاش (fail یا success) برای bucket/key مشخص. */
function auth_rate_record(string $bucket, string $bucketKey, string $outcome = 'fail', ?mysqli $db = null): void
{
    $db = $db ?? $GLOBALS['mysqli'];
    if ($bucket === '' || $bucketKey === '') {
        return;
    }
    $stmt = $db->prepare('INSERT INTO auth_attempts (bucket, bucket_key, outcome) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $bucket, $bucketKey, $outcome);
    $stmt->execute();
    $stmt->close();
}

/** شمارش تلاش‌های (اختیاری: fail/success) یک bucket در پنجره‌ی زمانی. */
function auth_rate_count(string $bucket, string $bucketKey, int $withinSeconds, string $outcome = 'fail', ?mysqli $db = null): int
{
    $db = $db ?? $GLOBALS['mysqli'];
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM auth_attempts
         WHERE bucket = ? AND bucket_key = ? AND outcome = ?
           AND attempted_at >= (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->bind_param('sssi', $bucket, $bucketKey, $outcome, $withinSeconds);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (int) $row['c'] : 0;
}

/**
 * وضعیت قفل یک bucket.
 *
 * اگر تعداد تلاش‌های ناموفق در پنجره به آستانه برسد و مدت قفل هنوز تمام نشده
 * باشد، قفل فعال است. خروجی: ['locked' => bool, 'remaining_seconds' => int]
 */
function auth_rate_lock_status(string $bucket, string $bucketKey, ?mysqli $db = null): array
{
    $db = $db ?? $GLOBALS['mysqli'];
    $policy = auth_rate_policy($db);

    $failOutcome = 'fail';
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c, MAX(attempted_at) AS newest
         FROM auth_attempts
         WHERE bucket = ? AND bucket_key = ? AND outcome = ?
           AND attempted_at >= (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->bind_param('sssi', $bucket, $bucketKey, $failOutcome, $policy['window_seconds']);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $count = $row ? (int) ($row['c'] ?? 0) : 0;
    if ($count < $policy['max_attempts']) {
        return ['locked' => false, 'remaining_seconds' => 0];
    }

    $newest = $row ? strtotime((string) ($row['newest'] ?? '')) : 0;
    if ($newest <= 0) {
        return ['locked' => false, 'remaining_seconds' => 0];
    }

    $lockUntil = $newest + $policy['lockout_seconds'];
    $now = time();
    if ($now >= $lockUntil) {
        return ['locked' => false, 'remaining_seconds' => 0];
    }

    return ['locked' => true, 'remaining_seconds' => max(1, $lockUntil - $now)];
}

/** پاک کردن سوابق یک bucket (مثلاً بعد از ورود موفق همان حساب). */
function auth_rate_clear(string $bucket, string $bucketKey, ?mysqli $db = null): void
{
    $db = $db ?? $GLOBALS['mysqli'];
    $stmt = $db->prepare('DELETE FROM auth_attempts WHERE bucket = ? AND bucket_key = ?');
    $stmt->bind_param('ss', $bucket, $bucketKey);
    $stmt->execute();
    $stmt->close();
}

/** پاکسازی آرام رکوردهای قدیمی تا جدول بی‌نهایت رشد نکند. */
function auth_rate_cleanup(int $keepDays = 7, ?mysqli $db = null): void
{
    $db = $db ?? $GLOBALS['mysqli'];
    $stmt = $db->prepare('DELETE FROM auth_attempts WHERE attempted_at < (NOW() - INTERVAL ? DAY)');
    $stmt->bind_param('i', $keepDays);
    $stmt->execute();
    $stmt->close();
}
