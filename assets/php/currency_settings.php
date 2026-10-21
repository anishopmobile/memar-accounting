<?php
/** Owner-protected currency configuration endpoint for accounting only; Payment stays IRR. */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/currency.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(true, '', ['currency' => getCurrentCurrency()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond_error('روش درخواست نامعتبر است.', 405);
require_csrf_or_fail();
$user = current_user();
// Existing users table has no permission/role column; only first registered owner may edit.
$ownerResult = $mysqli->query('SELECT MIN(id) AS id FROM users');
$owner = $ownerResult ? $ownerResult->fetch_assoc() : null;
if ($ownerResult) $ownerResult->free();
if ($user === null || (int) ($user['is_active'] ?? 0) !== 1 ||
    (int) ($user['id'] ?? 0) !== (int) ($owner['id'] ?? 0)) {
    respond_error('مجوز مدیریت تنظیمات را ندارید.', 403);
}
$code = $_POST['currency_code'] ?? null;
if (!is_string($code) || !in_array($code, ['TOMAN', 'IRR'], true)) {
    respond_error('واحد پولی نامعتبر است.', 422);
}
$stmt = $mysqli->prepare('INSERT INTO currency_settings (id, currency_code) VALUES (1, ?) ON DUPLICATE KEY UPDATE currency_code = VALUES(currency_code)');
$stmt->bind_param('s', $code);
if (!$stmt->execute()) respond_error('ذخیره واحد پولی ناموفق بود.', 500);
$stmt->close();
respond_json(true, 'واحد پولی ذخیره شد.', ['currency' => $code]);
