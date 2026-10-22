<?php
/** Resolve abuse-prevention IP without allowing forged forwarding headers.
 * Configure MEMAR_TRUSTED_PROXIES as comma-separated IPs only if the web
 * server is behind known, controlled reverse proxies that overwrite XFF.
 */
declare(strict_types=1);

function memar_client_ip(): string
{
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($peer, FILTER_VALIDATE_IP)) return 'unknown';

    $trusted = array_values(array_filter(array_map('trim',
        explode(',', (string)(getenv('MEMAR_TRUSTED_PROXIES') ?: ''))),
        static fn($ip) => (bool) filter_var($ip, FILTER_VALIDATE_IP)));
    if (!in_array($peer, $trusted, true)) return $peer;

    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded === '' || strlen($forwarded) > 1024) return $peer;

    $chain = array_map('trim', explode(',', $forwarded));
    // Walk from the trusted ingress backwards, stopping at the first
    // untrusted valid IP. A user-controlled leftmost header is not trusted.
    for ($i = count($chain)-1; $i >= 0; $i--) {
        $ip = $chain[$i];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return $peer;
        if (!in_array($ip, $trusted, true)) return $ip;
    }
    return $peer;
}
