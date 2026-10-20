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
$result = $mysqli->query('SELECT * FROM invoice_settings WHERE id = 1 LIMIT 1');
if ($result) {
    $settings = $result->fetch_assoc();
    $result->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(true, '', ['settings' => $settings ?: []]);
}

$unofficial_invoice_desc = trim($_POST['unofficial_invoice_desc'] ?? '');
$official_invoice_desc = trim($_POST['official_invoice_desc'] ?? '');
$proforma_desc = trim($_POST['proforma_desc'] ?? '');
$proforma_title = trim($_POST['proforma_title'] ?? '');
$invoice_template_color = trim($_POST['invoice_template_color'] ?? '');
$official_invoice_direction = trim($_POST['official_invoice_direction'] ?? 'vertical');
$unofficial_invoice_direction = trim($_POST['unofficial_invoice_direction'] ?? 'vertical');

$official_invoice_direction = in_array($official_invoice_direction, ['vertical', 'horizontal'], true) ? $official_invoice_direction : 'vertical';
$unofficial_invoice_direction = in_array($unofficial_invoice_direction, ['vertical', 'horizontal'], true) ? $unofficial_invoice_direction : 'vertical';

if ($settings) {
    $stmt = $mysqli->prepare('UPDATE invoice_settings SET unofficial_invoice_desc = ?, official_invoice_desc = ?, proforma_desc = ?, proforma_title = ?, invoice_template_color = ?, official_invoice_direction = ?, unofficial_invoice_direction = ? WHERE id = 1');
    $stmt->bind_param('sssssss', $unofficial_invoice_desc, $official_invoice_desc, $proforma_desc, $proforma_title, $invoice_template_color, $official_invoice_direction, $unofficial_invoice_direction);
    if ($stmt->execute()) {
        $stmt->close();
        respond(true, 'تنظیمات فاکتور با موفقیت ذخیره شد.', [
            'unofficial_invoice_desc' => $unofficial_invoice_desc,
            'official_invoice_desc' => $official_invoice_desc,
            'proforma_desc' => $proforma_desc,
            'proforma_title' => $proforma_title,
            'invoice_template_color' => $invoice_template_color,
            'official_invoice_direction' => $official_invoice_direction,
            'unofficial_invoice_direction' => $unofficial_invoice_direction,
        ]);
    }
    respond(false, 'خطا در ذخیره‌سازی تنظیمات فاکتور.');
}

$stmt = $mysqli->prepare('INSERT INTO invoice_settings (id, unofficial_invoice_desc, official_invoice_desc, proforma_desc, proforma_title, invoice_template_color, official_invoice_direction, unofficial_invoice_direction) VALUES (1, ?, ?, ?, ?, ?, ?, ?)');
$stmt->bind_param('sssssss', $unofficial_invoice_desc, $official_invoice_desc, $proforma_desc, $proforma_title, $invoice_template_color, $official_invoice_direction, $unofficial_invoice_direction);
if ($stmt->execute()) {
    $stmt->close();
    respond(true, 'تنظیمات فاکتور با موفقیت ذخیره شد.', [
        'unofficial_invoice_desc' => $unofficial_invoice_desc,
        'official_invoice_desc' => $official_invoice_desc,
        'proforma_desc' => $proforma_desc,
        'proforma_title' => $proforma_title,
        'invoice_template_color' => $invoice_template_color,
        'official_invoice_direction' => $official_invoice_direction,
        'unofficial_invoice_direction' => $unofficial_invoice_direction,
    ]);
}

respond(false, 'خطا در ذخیره‌سازی تنظیمات فاکتور.');