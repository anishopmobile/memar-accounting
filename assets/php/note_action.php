<?php

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * ارسال اطلاعات یک یادداشت
 */
function noteToArray(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'content' => $row['content'],
        'color' => $row['color'],
        'pos_x' => (int)$row['pos_x'],
        'pos_y' => (int)$row['pos_y'],
        'z_index' => (int)$row['z_index'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}


/*
 * GET
 * دریافت تمام یادداشت‌ها
 */
if ($method === 'GET') {

    $notes = [];

    $result = $mysqli->query(
        "SELECT id, title, content, color, pos_x, pos_y, z_index, created_at, updated_at
         FROM notes
         ORDER BY z_index ASC, id ASC"
    );

    if (!$result) {
        respond_error('خطا در دریافت یادداشت‌ها.', 500);
    }

    while ($row = $result->fetch_assoc()) {
        $notes[] = noteToArray($row);
    }

    $result->free();

    respond_json(
        true,
        '',
        ['notes' => $notes]
    );
}


/*
 * تمام عملیات POST نیازمند CSRF هستند.
 */
if ($method !== 'POST') {
    respond_error('روش درخواست نامعتبر است.', 405);
}

require_csrf_or_fail();

$action = trim($_POST['action'] ?? '');


/*
 * CREATE
 */
if ($action === 'create') {

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $color = trim($_POST['color'] ?? '#fff3a3');

    if (mb_strlen($title, 'UTF-8') > 150) {
        respond_error('عنوان یادداشت نمی‌تواند بیشتر از ۱۵۰ کاراکتر باشد.');
    }

    if (mb_strlen($content, 'UTF-8') > 5000) {
        respond_error('متن یادداشت نمی‌تواند بیشتر از ۵۰۰۰ کاراکتر باشد.');
    }

    $allowedColors = [
        '#fff3a3',
        '#ffd6a5',
        '#ffadad',
        '#caffbf',
        '#bde0fe',
        '#d9c2ff'
    ];

    if (!in_array($color, $allowedColors, true)) {
        $color = '#fff3a3';
    }

    $result = $mysqli->query(
        "SELECT COALESCE(MAX(z_index), 0) + 1 AS next_z FROM notes"
    );

    $nextZ = 1;

    if ($result && ($row = $result->fetch_assoc())) {
        $nextZ = max(1, (int)$row['next_z']);
        $result->free();
    }

    /*
     * یادداشت جدید در محل مناسبی قرار می‌گیرد.
     * کمی offset ایجاد می‌کنیم تا چند یادداشت روی هم قرار نگیرند.
     */
    $countResult = $mysqli->query("SELECT COUNT(*) AS total FROM notes");
    $total = 0;

    if ($countResult && ($row = $countResult->fetch_assoc())) {
        $total = (int)$row['total'];
        $countResult->free();
    }

    $posX = 30 + (($total % 5) * 35);
    $posY = 30 + ((($total * 2) % 5) * 35);

    $stmt = $mysqli->prepare(
        "INSERT INTO notes
            (title, content, color, pos_x, pos_y, z_index)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        respond_error('خطا در آماده‌سازی ذخیره یادداشت.', 500);
    }

    $stmt->bind_param(
        'sssiii',
        $title,
        $content,
        $color,
        $posX,
        $posY,
        $nextZ
    );

    if (!$stmt->execute()) {
        $stmt->close();
        respond_error('خطا در ذخیره یادداشت.', 500);
    }

    $noteId = $stmt->insert_id;
    $stmt->close();

    $stmt = $mysqli->prepare(
        "SELECT id, title, content, color, pos_x, pos_y, z_index, created_at, updated_at
         FROM notes
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->bind_param('i', $noteId);
    $stmt->execute();

    $result = $stmt->get_result();
    $note = $result->fetch_assoc();

    $stmt->close();

    respond_json(
        true,
        'یادداشت ایجاد شد.',
        ['note' => noteToArray($note)]
    );
}


/*
 * UPDATE
 */
if ($action === 'update') {

    $id = trim($_POST['id'] ?? '');

    if ($id === '' || !ctype_digit($id) || (int)$id <= 0) {
        respond_error('شناسه یادداشت نامعتبر است.');
    }

    $id = (int)$id;

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $color = trim($_POST['color'] ?? '#fff3a3');

    $posX = isset($_POST['pos_x']) && is_numeric($_POST['pos_x'])
        ? (int)$_POST['pos_x']
        : 30;

    $posY = isset($_POST['pos_y']) && is_numeric($_POST['pos_y'])
        ? (int)$_POST['pos_y']
        : 30;

    $zIndex = isset($_POST['z_index']) && is_numeric($_POST['z_index'])
        ? (int)$_POST['z_index']
        : 1;

    if (mb_strlen($title, 'UTF-8') > 150) {
        respond_error('عنوان یادداشت نمی‌تواند بیشتر از ۱۵۰ کاراکتر باشد.');
    }

    if (mb_strlen($content, 'UTF-8') > 5000) {
        respond_error('متن یادداشت نمی‌تواند بیشتر از ۵۰۰۰ کاراکتر باشد.');
    }

    $allowedColors = [
        '#fff3a3',
        '#ffd6a5',
        '#ffadad',
        '#caffbf',
        '#bde0fe',
        '#d9c2ff'
    ];

    if (!in_array($color, $allowedColors, true)) {
        $color = '#fff3a3';
    }

    $posX = max(0, min($posX, 5000));
    $posY = max(0, min($posY, 5000));
    $zIndex = max(1, min($zIndex, 1000000));

    $stmt = $mysqli->prepare(
        "UPDATE notes
         SET title = ?,
             content = ?,
             color = ?,
             pos_x = ?,
             pos_y = ?,
             z_index = ?
         WHERE id = ?"
    );

    if (!$stmt) {
        respond_error('خطا در آماده‌سازی ویرایش یادداشت.', 500);
    }

    $stmt->bind_param(
        'sssiiii',
        $title,
        $content,
        $color,
        $posX,
        $posY,
        $zIndex,
        $id
    );

    if (!$stmt->execute()) {
        $stmt->close();
        respond_error('خطا در ویرایش یادداشت.', 500);
    }

    $stmt->close();

    respond_json(
        true,
        'یادداشت ذخیره شد.'
    );
}


/*
 * DELETE
 */
if ($action === 'delete') {

    $id = trim($_POST['id'] ?? '');

    if ($id === '' || !ctype_digit($id) || (int)$id <= 0) {
        respond_error('شناسه یادداشت نامعتبر است.');
    }

    $id = (int)$id;

    $stmt = $mysqli->prepare(
        "DELETE FROM notes WHERE id = ?"
    );

    if (!$stmt) {
        respond_error('خطا در آماده‌سازی حذف یادداشت.', 500);
    }

    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) {
        $stmt->close();
        respond_error('خطا در حذف یادداشت.', 500);
    }

    $deleted = $stmt->affected_rows;
    $stmt->close();

    if ($deleted === 0) {
        respond_error('یادداشت موردنظر پیدا نشد.', 404);
    }

    respond_json(
        true,
        'یادداشت حذف شد.',
        ['id' => $id]
    );
}


respond_error('عملیات نامعتبر است.');
