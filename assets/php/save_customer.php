<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$customer_id = trim($_POST['customer_id'] ?? '');
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$national_code = trim($_POST['national_code'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$economic_code = trim($_POST['economic_code'] ?? '');
$registration_number = trim($_POST['registration_number'] ?? '');
$address = trim($_POST['address'] ?? '');
$postal_code = trim($_POST['postal_code'] ?? '');
$note = trim($_POST['note'] ?? '');

if ($first_name === '' || $last_name === '' || $phone === '') {
    echo json_encode(['success' => false, 'message' => 'نام، نام خانوادگی و تلفن اجباری هستند.']);
    exit;
}

if ($customer_id !== '') {
    if (!ctype_digit($customer_id)) {
        echo json_encode(['success' => false, 'message' => 'شناسه مشتری نامعتبر است.']);
        exit;
    }

    $stmt = $mysqli->prepare('UPDATE customers SET first_name = ?, last_name = ?, national_code = ?, phone = ?, economic_code = ?, registration_number = ?, address = ?, postal_code = ?, note = ? WHERE id = ?');
    $stmt->bind_param('sssssssssi', $first_name, $last_name, $national_code, $phone, $economic_code, $registration_number, $address, $postal_code, $note, $customer_id);
    if ($stmt->execute()) {
        $id = (int) $customer_id;
        $stmt->close();

        $result = $mysqli->prepare('SELECT total_spent, debt FROM customers WHERE id = ?');
        $result->bind_param('i', $id);
        $result->execute();
        $result->bind_result($total_spent, $debt);
        $result->fetch();
        $result->close();

        $customer = [
            'id' => $id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'full_name' => htmlspecialchars($first_name . ' ' . $last_name, ENT_QUOTES, 'UTF-8'),
            'national_code' => htmlspecialchars($national_code, ENT_QUOTES, 'UTF-8'),
            'phone' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
            'economic_code' => htmlspecialchars($economic_code, ENT_QUOTES, 'UTF-8'),
            'registration_number' => htmlspecialchars($registration_number, ENT_QUOTES, 'UTF-8'),
            'address' => $address,
            'postal_code' => htmlspecialchars($postal_code, ENT_QUOTES, 'UTF-8'),
            'note' => $note,
            'total_spent' => number_format($total_spent ?? 0),
            'debt' => number_format($debt ?? 0),
        ];
        echo json_encode(['success' => true, 'customer' => $customer]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در به‌روزرسانی اطلاعات.']);
    }
} else {
    $stmt = $mysqli->prepare('INSERT INTO customers (first_name, last_name, national_code, phone, economic_code, registration_number, address, postal_code, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssssss', $first_name, $last_name, $national_code, $phone, $economic_code, $registration_number, $address, $postal_code, $note);
    if ($stmt->execute()) {
        $id = $stmt->insert_id;
        $customer = [
            'id' => $id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'full_name' => htmlspecialchars($first_name . ' ' . $last_name, ENT_QUOTES, 'UTF-8'),
            'national_code' => htmlspecialchars($national_code, ENT_QUOTES, 'UTF-8'),
            'phone' => htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'),
            'economic_code' => htmlspecialchars($economic_code, ENT_QUOTES, 'UTF-8'),
            'registration_number' => htmlspecialchars($registration_number, ENT_QUOTES, 'UTF-8'),
            'address' => $address,
            'postal_code' => htmlspecialchars($postal_code, ENT_QUOTES, 'UTF-8'),
            'note' => $note,
            'total_spent' => number_format(0),
            'debt' => number_format(0),
        ];
        echo json_encode(['success' => true, 'customer' => $customer]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی اطلاعات.']);
    }
    $stmt->close();
}
