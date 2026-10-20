<?php
require_once __DIR__ . '/../assets/php/db.php';

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
    foreach ($nodes as $node) {
        $indent = $level > 0 ? 'padding-right: ' . ($level * 18) . 'px;' : '';
        $parentName = $node['parent_name'] ?? 'اصلی';
        echo '<tr class="category-row" data-id="' . $node['id'] . '" data-code="' . htmlspecialchars($node['code'], ENT_QUOTES, 'UTF-8') . '" data-name="' . htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8') . '" data-parent-id="' . ($node['parent_id'] !== null ? htmlspecialchars($node['parent_id'], ENT_QUOTES, 'UTF-8') : '') . '" data-level="' . $level . '">';
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
?>
<div class="card category-page">
    <div class="page-actions">
        <button class="button" id="openCategoryModal">اضافه کردن دسته بندی</button>
        <div class="filter-panel">
            <input type="search" id="categorySearch" placeholder="جستجو...">
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="categoriesTable">
            <thead>
                <tr>
                    <th>کد گروه</th>
                    <th>نام گروه</th>
                    <th>زیر گروه</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($categories) === 0): ?>
                    <tr>
                        <td colspan="3" class="empty-state">دسته‌بندی‌ای یافت نشد</td>
                    </tr>
                <?php else: ?>
                    <?php printCategoryRows($tree); ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="categoryModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>دسته‌بندی کالاها</h2>
            <button class="modal-close" id="closeCategoryModal" type="button">×</button>
        </div>
        <div class="category-modal-grid">
            <div class="category-sidebar">
                <div class="category-action-buttons">
                    <button type="button" class="button-secondary" id="addRootCategory">سرگروه</button>
                    <button type="button" class="button-secondary" id="addSubcategory">زیر گروه</button>
                    <button type="button" class="button-secondary" id="editCategory">ویرایش</button>
                    <button type="button" class="button-secondary" id="deleteCategory">حذف</button>
                    <button type="button" class="button-secondary" id="exitCategoryModal">خروج</button>
                </div>
                <div class="category-tree-list">
                    <div class="tree-list-title">لیست دسته‌بندی‌ها</div>
                    <div class="category-tree" id="modalCategoryTree">
                        <?php renderModalTree($tree); ?>
                    </div>
                </div>
            </div>
            <div class="category-form-area">
                <form id="categoryForm" method="post" action="assets/php/category_action.php">
                    <input type="hidden" name="category_id" id="categoryId" value="">
                    <input type="hidden" name="mode" id="categoryMode" value="add-root">
                    <div class="form-row"><label>کد گروه</label><input type="text" id="categoryCode" readonly disabled placeholder="پس از انتخاب نوع ثبت نمایش داده می‌شود"><input type="hidden" name="code" id="categoryCodeHidden" value=""></div>
                    <div class="form-row"><label>نام گروه</label><input type="text" name="name" id="categoryName" disabled required></div>
                    <div class="form-row"><label>گروه اصلی</label>
                        <select name="parent_id" id="categoryParent" disabled>
                            <option value="">بدون سرگروه</option>
                            <?php renderParentOptions($tree); ?>
                        </select>
                    </div>
                    <div class="form-actions modal-actions">
                        <button type="submit" class="button" id="saveCategoryButton">ذخیره</button>
                        <button type="button" class="button-secondary" id="cancelCategoryModal">لغو</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>