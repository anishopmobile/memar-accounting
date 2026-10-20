<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

function respond($success, $message = '', $data = null)
{
    $payload = ['success' => $success];
    if ($message !== '') {
        $payload['message'] = $message;
    }
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload);
    exit;
}

$settings = null;
$result = $mysqli->query('SELECT * FROM tax_settings WHERE id = 1 LIMIT 1');
if ($result) {
    $settings = $result->fetch_assoc();
    $result->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(true, '', ['settings' => $settings ?: []]);
}

$tax_enabled = !empty($_POST['tax_enabled']) ? 1 : 0;
$tax_rate = trim($_POST['tax_rate'] ?? '0');

if ($tax_rate === '' || !is_numeric($tax_rate)) {
    respond(false, 'نرخ مالیات باید یک عدد معتبر باشد.');
}
if ($tax_rate < 0 || $tax_rate > 100) {
    respond(false, 'نرخ مالیات باید بین ۰ تا ۱۰۰ درصد باشد.');
}
$tax_rate = round((float) $tax_rate, 2);

if ($settings) {
    $stmt = $mysqli->prepare('UPDATE tax_settings SET tax_enabled = ?, tax_rate = ? WHERE id = 1');
    $stmt->bind_param('id', $tax_enabled, $tax_rate);
    if ($stmt->execute()) {
        $stmt->close();
        respond(true, 'تنظیمات مالیات با موفقیت ذخیره شد.', [
            'tax_enabled' => $tax_enabled,
            'tax_rate' => $tax_rate,
        ]);
    }
    respond(false, 'خطا در ذخیره‌سازی تنظیمات مالیات.');
}

$stmt = $mysqli->prepare('INSERT INTO tax_settings (id, tax_enabled, tax_rate) VALUES (1, ?, ?)');
$stmt->bind_param('id', $tax_enabled, $tax_rate);
if ($stmt->execute()) {
    $stmt->close();
    respond(true, 'تنظیمات مالیات با موفقیت ذخیره شد.', [
        'tax_enabled' => $tax_enabled,
        'tax_rate' => $tax_rate,
    ]);
}

respond(false, 'خطا در ذخیره‌سازی تنظیمات مالیات.');