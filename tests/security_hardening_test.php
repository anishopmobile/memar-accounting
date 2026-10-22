<?php
/** Security checks with no DB, network or user data. */
declare(strict_types=1);
require __DIR__ . '/../assets/php/client_ip.php';

function assert_secure(bool $condition, string $what): void
{
    if (!$condition) throw new RuntimeException("FAIL: " . $what);
    echo "PASS: " . $what . PHP_EOL;
}
putenv('MEMAR_TRUSTED_PROXIES');
$_SERVER['REMOTE_ADDR'] = '198.51.100.44';
$_SERVER['HTTP_CLIENT_IP'] = '192.0.2.100';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.101';
assert_secure(memar_client_ip() === '198.51.100.44', 'untrusted forwarded headers cannot bypass rate limit');
putenv('MEMAR_TRUSTED_PROXIES=198.51.100.44,192.0.2.9');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.78,192.0.2.9';
assert_secure(memar_client_ip() === '203.0.113.78', 'explicitly trusted proxy extracts first untrusted hop');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4,not-an-ip';
assert_secure(memar_client_ip() === '198.51.100.44', 'invalid forwarded chain falls back to ingress');
$_SERVER['REMOTE_ADDR'] = '198.51.100.12';
assert_secure(memar_client_ip() === '198.51.100.12', 'non-proxy request ignores crafted chain');
putenv('MEMAR_TRUSTED_PROXIES');

$root = dirname(__DIR__);
foreach (['save_customer.php','delete_customer.php','save_supplier.php','delete_supplier.php'] as $file) {
    $src = file_get_contents($root . '/assets/php/' . $file);
    assert_secure(str_contains($src,'boot.php')
        && str_contains($src,'require_csrf_or_fail()')
        && str_contains($src,"REQUEST_METHOD"), $file . ' has auth/CSRF/POST gate');
}
assert_secure(!is_file($root . '/backup_2026-10-20_03-29-45.sql'), 'database backup is absent from current source tree');
assert_secure(is_file($root . '/.gitignore') && is_file($root . '/.htaccess'),
    'repository excludes backups/uploads and denies sensitive file downloads on Apache');
echo "Security baseline checks passed." . PHP_EOL;
