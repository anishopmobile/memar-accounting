<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$customer_id = trim($_POST['customer_id'] ?? '');
if ($customer_id === '' || !ctype_digit($customer_id)) {
    echo json_encode(['success' => false, 'message' => 'شناسه مشتری نامعتبر است.']);
    exit;
}

// اگر مشتری دارای فاکتور باشد (سطر ON DELETE RESTRICT) امکان حذف وجود ندارد.
$stmt = $mysqli->prepare('SELECT COUNT(*) FROM invoices WHERE customer_id = ?');
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$stmt->bind_result($invoiceCount);
$stmt->fetch();
$stmt->close();

if ($invoiceCount > 0) {
    echo json_encode(['success' => false, 'message' => 'این مشتری دارای فاکتور است و قابل حذف نیست.']);
    exit;
}

$stmt = $mysqli->prepare('DELETE FROM customers WHERE id = ?');
$stmt->bind_param('i', $customer_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'action' => 'deleted', 'customer_id' => (int) $customer_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'خطا در حذف مشتری.']);
}
$stmt->close();
