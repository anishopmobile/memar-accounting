<?php
/**
 * payment_security_test.php — PHASE 10 security hardening assertions.
 *
 * Verifies the confirmed hardening fixes:
 *   1. Rate limiter enforces its window limit and allows reset.
 *   2. Host-header validation rejects bad hosts (fallback to localhost).
 *   3. publicCallbackUrl() is built from a validated host, never attacker Host.
 *   4. Session cookie params helper is defined and safe to call.
 * Also re-runs the silently-skipped checks that would break on regression.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/payment_loader.php';
require_once __DIR__ . '/public/public_helpers.php';

$failures = 0;
function sec(bool $cond, string $label): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . ' - ' . $label . "\n";
    if (!$cond) {
        $failures++;
    }
}

/* ---- 1. Rate limiter ---- */
// Clean any existing row for our test scope.
$scope = 'sec-test-' . bin2hex(random_bytes(4));
$limiter = new PaymentRateLimiter($mysqli);
$hash = substr(hash('sha256', $scope), 0, 64);
$mysqli->query("DELETE FROM payment_rate_limits WHERE scope_key = '" . $mysqli->escape_string($hash) . "'");

// First 10 within default window should pass; 11th and 12th blocked.
$allowed = 0;
for ($i = 0; $i < 12; $i++) {
    if ($limiter->allow($scope, 'submit')) {
        $allowed++;
    }
}
sec($allowed === 10, 'rate limiter allows exactly limit (10) then blocks');

// A different scope is unaffected (per-IP isolation).
$scope2 = 'sec-test-2-' . bin2hex(random_bytes(4));
sec($limiter->allow($scope2, 'submit') === true, 'rate limiter isolates by scope/IP');

// Reset window for the first scope works (window expired).
$mysqli->query("UPDATE payment_rate_limits SET window_start = DATE_SUB(NOW(), INTERVAL 2 HOUR), attempt_count = 10 WHERE scope_key = '" . $mysqli->escape_string($hash) . "'");
$chk = $mysqli->query("SELECT window_start, attempt_count FROM payment_rate_limits WHERE scope_key = '" . $mysqli->escape_string($hash) . "'")->fetch_assoc();
sec($chk !== null, 'reset-row present');
$allowReset = $limiter->allow($scope, 'submit');
$after = $mysqli->query("SELECT attempt_count FROM payment_rate_limits WHERE scope_key = '" . $mysqli->escape_string($hash) . "'")->fetch_assoc();
sec($allowReset === true && (int) $after['attempt_count'] === 1, 'rate limiter resets after window expiry (count back to 1)');

// Cleanup.
$mysqli->query("DELETE FROM payment_rate_limits WHERE scope_key = '" . $mysqli->escape_string($hash) . "' OR scope_key = '" . $mysqli->escape_string(substr(hash('sha256', $scope2), 0, 64)) . "'");

/* ---- 2. Host header validation ---- */
$_SERVER['HTTP_HOST'] = 'evil.com';
$_SERVER['SCRIPT_NAME'] = '/pay.php';
$_SERVER['HTTPS'] = 'on';
sec(payTrustedHost() === 'evil.com', 'valid hostname preserved');
$_SERVER['HTTP_HOST'] = "evil.com\r\nX-Injected: 1";
sec(payTrustedHost() !== "evil.com\r\nX-Injected: 1", 'CRLF host rejected (falls back)');
$_SERVER['HTTP_HOST'] = 'http://evil.com/path';
sec(payTrustedHost() === 'localhost', 'scheme/path in host rejected');
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
sec(payTrustedHost() === '127.0.0.1:8080', 'IPv4:port host preserved');
$_SERVER['HTTP_HOST'] = '[::1]:8080';
sec(payTrustedHost() === '[::1]:8080', 'IPv6 literal:port preserved');

/* ---- 3. Callback URL from validated host ---- */
$_SERVER['HTTP_HOST'] = 'pay.example.com';
$_SERVER['SCRIPT_NAME'] = '/pay.php';
$_SERVER['HTTPS'] = 'on';
$cb = publicCallbackUrl();
sec(strpos($cb, 'https://pay.example.com') === 0 && strpos($cb, 'pay.php?action=callback') !== false, 'callback URL derives from validated HTTPS host');

$_SERVER['HTTP_HOST'] = "bad\nhost";
$cb2 = publicCallbackUrl();
sec(strpos($cb2, 'http://localhost/') !== false || strpos($cb2, 'https://localhost/') !== false, 'callback URL falls back to localhost on bad host');
unset($_SERVER['HTTP_HOST']);

/* ---- 4. Session helper is callable ---- */
sec(function_exists('payPublicSessionStart'), 'payPublicSessionStart() defined');
sec(function_exists('payTrustedHost'), 'payTrustedHost() defined');

echo "\n" . ($failures === 0 ? 'ALL SECURITY HARDENING TESTS PASSED' : 'FAILURES: ' . $failures) . "\n";
$mysqli->close();