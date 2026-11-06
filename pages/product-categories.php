<?php
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/media_common.php';

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
?>
<div class="card category-page">
    <div class="page-actions">
        <button class="button" id="openCategoryModal">اضافه کردن دسته بندی</button>
        <a href="assets/php/export_categories.php" class="button-secondary">خروجی اکسل</a>
        <button type="button" class="button-secondary" id="openCategoryImportModal">ورود از اکسل</button>
        <div class="filter-panel">
            <input type="search" id="categorySearch" placeholder="جستجو...">
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="categoriesTable">
            <thead>
                <tr>
                    <?php if ($categoryImagesEnabled): ?><th>تصویر</th><?php endif; ?>
                    <th>کد گروه</th>
                    <th>نام گروه</th>
                    <th>زیر گروه</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($categories) === 0): ?>
                    <tr>
                        <td colspan="<?php echo $categoryImagesEnabled ? 5 : 4; ?>" class="empty-state">دسته‌بندی‌ای یافت نشد</td>
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
                    <?php if ($categoryImagesEnabled): ?>
                    <div class="form-row">
                      <label>تصویر دسته‌بندی</label>
                      <input type="hidden" id="categoryMediaId" value="0">
                      <div class="media-actions">
                        <img id="categoryMediaPreview" src="assets/img/product-placeholder.svg" width="70" height="70" alt="پیش‌نمایش تصویر" class="media-thumb">
                        <button type="button" id="categoryMediaButton" class="button-secondary">انتخاب از کتابخانه</button>
                        <button type="button" id="categoryMediaClear" class="button-secondary">حذف ارتباط تصویر</button>
                      </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-actions modal-actions">
                        <button type="submit" class="button" id="saveCategoryButton">ذخیره</button>
                        <button type="button" class="button-secondary" id="cancelCategoryModal">لغو</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Same modal/preview styling as the product Excel import -->
<div class="modal" id="categoryImportModal">
    <div class="modal-backdrop"></div>
    <div class="modal-content modal-content-wide">
        <div class="modal-header">
            <h2>ورود دسته‌بندی‌ها از اکسل</h2>
            <button type="button" class="modal-close" id="closeCategoryImportModal">×</button>
        </div>
        <div class="form-grid">
            <div class="form-row" style="grid-column:span 2">
                <label for="categoryImportFile">فایل اکسل (.xlsx یا .xls)</label>
                <input type="file" id="categoryImportFile" accept=".xlsx,.xls">
                <span class="import-help">ستون‌های کد گروه و نام گروه الزامی هستند؛ کد گروه مادر اختیاری است. فایل خروجی همین صفحه، قالب استاندارد ورود است.</span>
            </div>
            <div class="form-row" style="grid-column:span 2">
                <label>دسته‌بندی‌های تکراری (بر اساس کد گروه)</label>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="radio" name="category_import_mode" value="skip_duplicates" checked>
                        صرف‌نظر از دسته‌بندی‌های تکراری
                    </label>
                    <label style="display:flex;gap:6px;align-items:center">
                        <input type="radio" name="category_import_mode" value="update_existing">
                        به‌روزرسانی اطلاعات
                    </label>
                </div>
                <span class="import-help">در به‌روزرسانی فقط نام دسته‌بندی تغییر می‌کند؛ کد گروه، شناسه، گروه مادر و ارتباط با کالاها ثابت می‌مانند.</span>
            </div>
        </div>
        <div class="import-actions">
            <button type="button" class="button" id="categoryImportPreview" disabled>خواندن و پیش‌نمایش</button>
            <button type="button" class="button button-success" id="categoryImportSubmit" disabled>ثبت نهایی</button>
            <span class="import-spinner" id="categoryImportSpinner" style="display:none">در حال پردازش...</span>
        </div>
        <div class="import-result" id="categoryImportResult" style="display:none"></div>
        <div class="import-summary" id="categoryImportSummary" style="display:none"></div>
        <div class="import-errors-wrap" id="categoryImportErrorsWrap" style="display:none">
            <div class="import-sub-title">خطاهای فایل:</div>
            <ul class="import-errors" id="categoryImportErrors"></ul>
        </div>
        <div class="import-preview-wrap" id="categoryImportPreviewWrap" style="display:none">
            <div class="import-sub-title">پیش‌نمایش دسته‌بندی‌ها:</div>
            <div class="import-preview-scroll">
                <table class="action-table import-preview-table" id="categoryImportPreviewTable">
                    <thead><tr><th>ردیف</th><th>کد گروه</th><th>نام گروه</th><th>کد گروه مادر</th><th>وضعیت</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="form-actions modal-actions">
            <button type="button" class="button-secondary" id="cancelCategoryImportModal">بستن</button>
        </div>
    </div>
</div>
