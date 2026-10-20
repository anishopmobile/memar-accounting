<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$supplier_id = trim($_POST['supplier_id'] ?? '');
if ($supplier_id === '' || !ctype_digit($supplier_id)) {
    echo json_encode(['success' => false, 'message' => 'شناسه تامین‌کننده نامعتبر است.']);
    exit;
}

// اگر تامین‌کننده دارای فاکتور باشد (سطر ON DELETE RESTRICT) امکان حذف وجود ندارد.
$stmt = $mysqli->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?');
$stmt->bind_param('i', $supplier_id);
$stmt->execute();
$stmt->bind_result($invoiceCount);
$stmt->fetch();
$stmt->close();

if ($invoiceCount > 0) {
    echo json_encode(['success' => false, 'message' => 'این تامین‌کننده دارای فاکتور است و قابل حذف نیست.']);
    exit;
}

$stmt = $mysqli->prepare('DELETE FROM suppliers WHERE id = ?');
$stmt->bind_param('i', $supplier_id);
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'action' => 'deleted', 'supplier_id' => (int) $supplier_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'خطا در حذف تامین‌کننده.']);
}
$stmt->close();
