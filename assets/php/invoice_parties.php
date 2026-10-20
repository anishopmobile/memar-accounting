<?php
/**
 * invoice_parties.php — جستجوی طرف معامله (مشتری/تامین‌کننده) — GET بدون CSRF
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';

$partyType = $_GET['party_type'] ?? 'customer';
$search = trim((string) ($_GET['search'] ?? ''));

$where = [];
$types = '';
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    if ($partyType === 'supplier') {
        $where[] = '(company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR national_code LIKE ?)';
        $types .= 'sssss';
    } else {
        $where[] = '(first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR national_code LIKE ?)';
        $types .= 'ssss';
    }
    for ($i = 0; $i < ($partyType === 'supplier' ? 5 : 4); $i++) {
        $params[] = $like;
    }
}

if ($partyType === 'supplier') {
    $sql = 'SELECT id,
                   IF(company_name IS NOT NULL AND company_name <> "", company_name,
                      CONCAT(COALESCE(first_name,"")," ",COALESCE(last_name,""))) AS name,
                   phone, national_code, company_name, first_name, last_name
            FROM suppliers';
} else {
    $sql = 'SELECT id, CONCAT(first_name," ",last_name) AS name, phone, national_code, first_name, last_name
            FROM customers';
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY id DESC LIMIT 50';

if ($types !== '') {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
} else {
    $res = $mysqli->query($sql);
}

$parties = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $parties[] = $row;
    }
}

respond_json(true, '', $parties);
