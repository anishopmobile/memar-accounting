<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=UTF-8');

$mode = $_POST['mode'] ?? 'add-root';
$category_id = trim($_POST['category_id'] ?? '');
$code = trim($_POST['code'] ?? '');
$name = trim($_POST['name'] ?? '');
$parent_id = trim($_POST['parent_id'] ?? '');

if ($mode !== 'delete' && $name === '') {
    echo json_encode(['success' => false, 'message' => 'نام گروه اجباری است.']);
    exit;
}

if ($parent_id === '') {
    $parent_id = null;
}

function generateCategoryCode($mysqli, $parentId = null)
{
    if ($parentId === null) {
        $result = $mysqli->query('SELECT code FROM product_categories WHERE parent_id IS NULL ORDER BY CAST(code AS UNSIGNED) DESC LIMIT 1');
        if ($result && $row = $result->fetch_assoc()) {
            return (string) ((int)$row['code'] + 1);
        }
        return '1';
    }

    $stmt = $mysqli->prepare('SELECT code FROM product_categories WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $parentId);
    $stmt->execute();
    $stmt->bind_result($parentCode);
    if (!$stmt->fetch()) {
        $stmt->close();
        return (string) $parentId . '1';
    }
    $stmt->close();

    $stmt = $mysqli->prepare('SELECT code FROM product_categories WHERE parent_id = ?');
    $stmt->bind_param('i', $parentId);
    $stmt->execute();
    $stmt->bind_result($childCode);
    $maxSuffix = 0;
    while ($stmt->fetch()) {
        if (strpos($childCode, $parentCode) === 0) {
            $suffix = substr($childCode, strlen($parentCode));
            if ($suffix !== '' && ctype_digit($suffix)) {
                $maxSuffix = max($maxSuffix, (int)$suffix);
            }
        }
    }
    $stmt->close();

    return $parentCode . ((string) ($maxSuffix + 1));
}

function hasChildCategories($mysqli, $categoryId)
{
    $stmt = $mysqli->prepare('SELECT 1 FROM product_categories WHERE parent_id = ? LIMIT 1');
    $stmt->bind_param('i', $categoryId);
    $stmt->execute();
    $stmt->store_result();
    $hasChild = $stmt->num_rows > 0;
    $stmt->close();
    return $hasChild;
}

function countAssignedProducts($mysqli, $categoryId)
{
    $assigned = 0;
    $tableCandidates = ['products', 'goods', 'items', 'services'];
    $columnCandidates = ['category_id', 'product_category_id', 'category_code', 'categoryId'];
    foreach ($tableCandidates as $table) {
        $result = $mysqli->query("SHOW TABLES LIKE '{$table}'");
        if (!$result || $result->num_rows === 0) {
            continue;
        }
        foreach ($columnCandidates as $column) {
            $colResult = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if (!$colResult || $colResult->num_rows === 0) {
                continue;
            }
            if ($column === 'category_code') {
                $stmt = $mysqli->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = (SELECT code FROM product_categories WHERE id = ? LIMIT 1)");
                $stmt->bind_param('i', $categoryId);
            } else {
                $stmt = $mysqli->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?");
                $stmt->bind_param('i', $categoryId);
            }
            $stmt->execute();
            $stmt->bind_result($count);
            if ($stmt->fetch()) {
                $assigned += (int)$count;
            }
            $stmt->close();
            if ($assigned > 0) {
                return $assigned;
            }
        }
    }
    return $assigned;
}

switch ($mode) {
    case 'add-root':
        $code = generateCategoryCode($mysqli, null);
        $stmt = $mysqli->prepare('INSERT INTO product_categories (code, name, parent_id) VALUES (?, ?, NULL)');
        $stmt->bind_param('ss', $code, $name);
        if ($stmt->execute()) {
            $id = $stmt->insert_id;
            echo json_encode(['success' => true, 'action' => 'added', 'category' => ['id' => $id, 'code' => $code, 'name' => $name, 'parent_id' => null]]);
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی دسته‌بندی.']);
        }
        $stmt->close();
        break;
    case 'add-sub':
        if ($parent_id === null) {
            echo json_encode(['success' => false, 'message' => 'گروه اصلی برای زیرگروه انتخاب نشده است.']);
            exit;
        }
        $code = generateCategoryCode($mysqli, $parent_id);
        $stmt = $mysqli->prepare('INSERT INTO product_categories (code, name, parent_id) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $code, $name, $parent_id);
        if ($stmt->execute()) {
            $id = $stmt->insert_id;
            echo json_encode(['success' => true, 'action' => 'added', 'category' => ['id' => $id, 'code' => $code, 'name' => $name, 'parent_id' => $parent_id]]);
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی دسته‌بندی.']);
        }
        $stmt->close();
        break;
    case 'edit':
        if ($category_id === '' || !ctype_digit($category_id)) {
            echo json_encode(['success' => false, 'message' => 'شناسه دسته بندی نامعتبر است.']);
            exit;
        }
        if ($parent_id !== null && $parent_id === $category_id) {
            echo json_encode(['success' => false, 'message' => 'یک گروه نمی‌تواند خودش را به عنوان گروه اصلی انتخاب کند.']);
            exit;
        }
        if ($parent_id !== null) {
            $stmt = $mysqli->prepare('SELECT id FROM product_categories WHERE parent_id = ? AND id = ?');
            $stmt->bind_param('ii', $category_id, $parent_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'گروه انتخاب شده نمی‌تواند به زیر گروه خودش تبدیل شود.']);
                $stmt->close();
                exit;
            }
            $stmt->close();
        }

        $codeToUse = $code;
        if ($parent_id !== null) {
            if (strpos($code, (string)$parent_id) !== 0) {
                $codeToUse = generateCategoryCode($mysqli, $parent_id);
            }
        }

        if ($parent_id === null) {
            $stmt = $mysqli->prepare('UPDATE product_categories SET code = ?, name = ?, parent_id = NULL WHERE id = ?');
            $stmt->bind_param('ssi', $codeToUse, $name, $category_id);
        } else {
            $stmt = $mysqli->prepare('UPDATE product_categories SET code = ?, name = ?, parent_id = ? WHERE id = ?');
            $stmt->bind_param('ssii', $codeToUse, $name, $parent_id, $category_id);
        }
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'action' => 'edited', 'category' => ['id' => $category_id, 'code' => $codeToUse, 'name' => $name, 'parent_id' => $parent_id]]);
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در ویرایش دسته‌بندی.']);
        }
        $stmt->close();
        break;
    case 'delete':
        if ($category_id === '' || !ctype_digit($category_id)) {
            echo json_encode(['success' => false, 'message' => 'شناسه دسته بندی نامعتبر است.']);
            exit;
        }
        if (hasChildCategories($mysqli, $category_id)) {
            echo json_encode(['success' => false, 'message' => 'قبل از حذف، ابتدا زیرگروه‌های این دسته را حذف یا انتقال دهید.']);
            exit;
        }
        $assignedProducts = countAssignedProducts($mysqli, $category_id);
        if ($assignedProducts > 0) {
            echo json_encode(['success' => false, 'message' => 'این دسته‌بندی به کالاهایی اختصاص داده شده و قابل حذف نیست.']);
            exit;
        }
        $stmt = $mysqli->prepare('DELETE FROM product_categories WHERE id = ?');
        $stmt->bind_param('i', $category_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'action' => 'deleted', 'category_id' => $category_id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در حذف دسته‌بندی.']);
        }
        $stmt->close();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر است.']);
        break;
}
