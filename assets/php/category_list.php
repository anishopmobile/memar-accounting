<?php
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/media_common.php';
header('Content-Type: application/json; charset=UTF-8');

$categoryImagesEnabled = (int)(media_settings($mysqli)['category_images'] ?? 1) === 1;
$categoryImages = [];
if ($categoryImagesEnabled) {
    $resultImages = $mysqli->query('SELECT category_id,media_id FROM category_media');
    while ($resultImages && ($image = $resultImages->fetch_assoc())) {
        $categoryImages[(int)$image['category_id']] = (int)$image['media_id'];
    }
}
$categories = [];
$result = $mysqli->query('SELECT * FROM product_categories ORDER BY parent_id IS NULL DESC, parent_id, CAST(code AS UNSIGNED)');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['parent_id'] !== null) {
            $row['parent_name'] = '';
        }
        $categories[] = $row;
    }
    $result->free();
}

function buildCategoryTree(array $items): array
{
    $tree = [];
    $children = [];
    foreach ($items as $item) {
        if ($item['parent_id'] === null) {
            $tree[$item['id']] = $item;
            $tree[$item['id']]['children'] = [];
        } else {
            $children[$item['parent_id']][] = $item;
        }
    }

    foreach ($tree as &$node) {
        attachChildren($node, $children);
    }
    return array_values($tree);
}

function attachChildren(array &$node, array &$children): void
{
    if (!isset($children[$node['id']])) {
        return;
    }
    foreach ($children[$node['id']] as $child) {
        $child['children'] = [];
        if (!empty($node['name'])) {
            $child['parent_name'] = $node['name'];
        }
        attachChildren($child, $children);
        $node['children'][] = $child;
    }
}

function printCategoryRows(array $nodes, int $level = 0): void
{
    global $categoryImagesEnabled, $categoryImages;
    foreach ($nodes as $node) {
        $indent = $level > 0 ? 'padding-right: ' . ($level * 18) . 'px;' : '';
        $parentName = $node['parent_name'] ?? 'اصلی';
        echo '<tr class="category-row" data-id="' . $node['id'] . '" data-code="' . htmlspecialchars($node['code'], ENT_QUOTES, 'UTF-8') . '" data-name="' . htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8') . '" data-parent-id="' . ($node['parent_id'] !== null ? htmlspecialchars($node['parent_id'], ENT_QUOTES, 'UTF-8') : '') . '" data-level="' . $level . '">';
        if ($categoryImagesEnabled) {
            $imageId = (int)($categoryImages[(int)$node['id']] ?? 0);
            $url = $imageId ? 'assets/php/media_file.php?id=' . $imageId . '&size=thumb' : 'assets/img/product-placeholder.svg';
            echo '<td class="product-thumb-cell"><img class="media-thumb" width="44" height="44" alt="" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></td>';
        }
        echo '<td>' . htmlspecialchars($node['code']) . '</td>';
        echo '<td><span class="tree-label" style="' . $indent . '">' . htmlspecialchars($node['name']) . '</span></td>';
        echo '<td>' . htmlspecialchars($parentName) . '</td>';
        echo '<td class="action-buttons-row"><button type="button" class="button-secondary small category-row-edit">ویرایش</button> <button type="button" class="button-secondary small category-row-delete">حذف</button></td>';
        echo '</tr>';
        if (!empty($node['children'])) {
            printCategoryRows($node['children'], $level + 1);
        }
    }
}

function renderModalTree(array $nodes, int $level = 0): void
{
    foreach ($nodes as $node) {
        $padding = 12 + ($level * 16);
        echo '<div class="category-tree-item" data-id="' . $node['id'] . '" data-code="' . htmlspecialchars($node['code'], ENT_QUOTES, 'UTF-8') . '" data-name="' . htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8') . '" data-parent-id="' . ($node['parent_id'] !== null ? htmlspecialchars($node['parent_id'], ENT_QUOTES, 'UTF-8') : '') . '" style="padding-right: ' . $padding . 'px;">';
        echo '<span class="category-node-label">' . htmlspecialchars($node['code']) . ' - ' . htmlspecialchars($node['name']) . '</span>';
        echo '</div>';
        if (!empty($node['children'])) {
            renderModalTree($node['children'], $level + 1);
        }
    }
}

function renderParentOptions(array $nodes, int $level = 0): void
{
    foreach ($nodes as $node) {
        $prefix = str_repeat('—', $level);
        echo '<option value="' . $node['id'] . '">' . $prefix . ' ' . htmlspecialchars($node['name']) . '</option>';
        if (!empty($node['children'])) {
            renderParentOptions($node['children'], $level + 1);
        }
    }
}

$tree = buildCategoryTree($categories);
ob_start();
if (count($categories) === 0) {
    echo '<tr><td colspan="' . ($categoryImagesEnabled ? 5 : 4) . '" class="empty-state">دسته‌بندی‌ای یافت نشد</td></tr>';
} else {
    printCategoryRows($tree);
}
$tableBody = ob_get_clean();

ob_start();
renderModalTree($tree);
$modalTree = ob_get_clean();

ob_start();
echo '<option value="">بدون سرگروه</option>';
renderParentOptions($tree);
$parentOptions = ob_get_clean();

echo json_encode([
    'success' => true,
    'tableBody' => $tableBody,
    'modalTree' => $modalTree,
    'parentOptions' => $parentOptions,
]);
