<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$supplier_id = trim($_POST['supplier_id'] ?? '');
$company_name = trim($_POST['company_name'] ?? '');
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$national_code = trim($_POST['national_code'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$economic_code = trim($_POST['economic_code'] ?? '');
$registration_number = trim($_POST['registration_number'] ?? '');
$address = trim($_POST['address'] ?? '');
$postal_code = trim($_POST['postal_code'] ?? '');
$note = trim($_POST['note'] ?? '');

if ($phone === '') {
    echo json_encode(['success' => false, 'message' => 'شماره تلفن اجباری است.']);
    exit;
}
if ($company_name === '' && $first_name === '') {
    echo json_encode(['success' => false, 'message' => 'نام شرکت یا نام فرد باید وارد شود.']);
    exit;
}

$fullName = $company_name !== '' ? $company_name : trim($first_name . ' ' . $last_name);

if ($supplier_id !== '') {
    if (!ctype_digit($supplier_id)) {
        echo json_encode(['success' => false, 'message' => 'شناسه تامین‌کننده نامعتبر است.']);
        exit;
    }

    $stmt = $mysqli->prepare('UPDATE suppliers SET company_name = ?, first_name = ?, last_name = ?, national_code = ?, phone = ?, economic_code = ?, registration_number = ?, address = ?, postal_code = ?, note = ? WHERE id = ?');
    $stmt->bind_param('ssssssssssi', $company_name, $first_name, $last_name, $national_code, $phone, $economic_code, $registration_number, $address, $postal_code, $note, $supplier_id);
    if ($stmt->execute()) {
        $stmt->close();

        $totalStmt = $mysqli->prepare("SELECT
            COALESCE(SUM(CASE WHEN i.type = 'purchase_invoice' THEN i.payable_amount ELSE 0 END), 0) AS total_purchases,
            COALESCE(SUM(CASE WHEN i.type = 'purchase_invoice' AND i.payment_status IN ('unpaid', 'partial') THEN i.payable_amount ELSE 0 END), 0) AS unpaid
            FROM invoices i WHERE i.supplier_id = ?");
        $totalStmt->bind_param('i', $supplier_id);
        $totalStmt->execute();
        $totalStmt->bind_result($totalPurchases, $unpaid);
        $totalStmt->fetch();
        $totalStmt->close();

        $supplier = [
            'id' => (int) $supplier_id,
            'company_name' => htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8'),
            'first_name' => htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8'),
            'last_name' => htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8'),
            'full_name' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
            'national_code' => htmlspecialchars($national_code, ENT_QUOTES, 'UTF-8'),
            'phone' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
            'economic_code' => htmlspecialchars($economic_code, ENT_QUOTES, 'UTF-8'),
            'registration_number' => htmlspecialchars($registration_number, ENT_QUOTES, 'UTF-8'),
            'address' => $address,
            'postal_code' => htmlspecialchars($postal_code, ENT_QUOTES, 'UTF-8'),
            'note' => $note,
            'total_purchases' => number_format($totalPurchases ?? 0),
            'unpaid' => number_format($unpaid ?? 0),
        ];
        echo json_encode(['success' => true, 'supplier' => $supplier]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در به‌روزرسانی اطلاعات.']);
    }
} else {
    $stmt = $mysqli->prepare('INSERT INTO suppliers (company_name, first_name, last_name, national_code, phone, economic_code, registration_number, address, postal_code, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ssssssssss', $company_name, $first_name, $last_name, $national_code, $phone, $economic_code, $registration_number, $address, $postal_code, $note);
    if ($stmt->execute()) {
        $id = $stmt->insert_id;

        $totalStmt = $mysqli->prepare("SELECT
            COALESCE(SUM(CASE WHEN i.type = 'purchase_invoice' THEN i.payable_amount ELSE 0 END), 0) AS total_purchases,
            COALESCE(SUM(CASE WHEN i.type = 'purchase_invoice' AND i.payment_status IN ('unpaid', 'partial') THEN i.payable_amount ELSE 0 END), 0) AS unpaid
            FROM invoices i WHERE i.supplier_id = ?");
        $totalStmt->bind_param('i', $id);
        $totalStmt->execute();
        $totalStmt->bind_result($totalPurchases, $unpaid);
        $totalStmt->fetch();
        $totalStmt->close();

        $supplier = [
            'id' => $id,
            'company_name' => htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8'),
            'first_name' => htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8'),
            'last_name' => htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8'),
            'full_name' => htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
            'national_code' => htmlspecialchars($national_code, ENT_QUOTES, 'UTF-8'),
            'phone' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
            'economic_code' => htmlspecialchars($economic_code, ENT_QUOTES, 'UTF-8'),
            'registration_number' => htmlspecialchars($registration_number, ENT_QUOTES, 'UTF-8'),
            'address' => $address,
            'postal_code' => htmlspecialchars($postal_code, ENT_QUOTES, 'UTF-8'),
            'note' => $note,
            'total_purchases' => number_format($totalPurchases ?? 0),
            'unpaid' => number_format($unpaid ?? 0),
        ];
        echo json_encode(['success' => true, 'supplier' => $supplier]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی اطلاعات.']);
    }
    $stmt->close();
}