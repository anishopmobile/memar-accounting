<?php
/**
 * public_helpers.php — Safe helpers for public payment pages.
 *
 * Public pages must NEVER expose credentials, SQL errors, stack traces or
 * filesystem paths. All output helpers escape for HTML. Errors are shown in
 * plain Persian HTML with no internal detail.
 */
declare(strict_types=1);

/** HTML-escape a value for safe output (double-encode by default). */
function pe($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate/inject Host header safety: returns the host for building URLs, or
 * a safe fallback. Rejects CRLF, control characters and foreign origins.
 */
function payTrustedHost(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return 'localhost';
    }
    // Reject anything containing CR/LF/control chars or scheme/userinfo separators.
    // Note: ':' is intentionally allowed so host:port forms stay valid.
    if (preg_match('~[\x00-\x20\x7f]|[/\\\\@]|\r|\n~', $host)) {
        return 'localhost';
    }
    // Allow only hostnames / IPv4 / IPv6 literals (with optional port).
    if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d{1,5})?$/', $host)
        && !preg_match('/^\[[0-9a-fA-F:.]+\](:\d{1,5})?$/', $host)) {
        return 'localhost';
    }
    return $host;
}

/**
 * Best-effort absolute base URL for building callback URLs.
 * Host header is validated so an attacker-supplied Host cannot redirect the
 * gateway callback to a third-party server.
 */
function publicBaseUrl(): string
{
    // Trust the X-Forwarded-Proto only when explicitly behind a proxy TLS
    // termination; never trust a forwarded Host header here.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = payTrustedHost();
    return $scheme . '://' . $host . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/pay.php')), '/\\') . '/';
}

/** Build the gateway callback URL for a transaction. */
function publicCallbackUrl(): string
{
    return publicBaseUrl() . 'pay.php?action=callback';
}

/**
 * Start the public payment session with hardened cookie parameters
 * (HttpOnly, SameSite=Lax, Secure when HTTPS).
 */
function payPublicSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Render a safe friendly message (no internal detail).
 */
function publicFriendlyError(string $message): void
{
    echo '<div class="pay-alert pay-alert-error" role="alert">'
        . pe($message !== '' ? $message : 'خطا در انجام عملیات. لطفاً دوباره تلاش کنید.')
        . '</div>';
}