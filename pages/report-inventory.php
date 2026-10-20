<?php
require_once __DIR__ . '/../assets/php/product_common.php';

$rows = [];
$result = $mysqli->query("
    SELECT p.id, p.code, p.name, p.category_id, p.type,
           COALESCE(pu.name, p.unit) AS unit_name,
           COALESCE(pu.purchase_price, p.purchase_price) AS purchase_price,
           COALESCE(pu.sale_price, p.sale_price) AS sale_price,
           COALESCE(pu.barcode, p.code) AS unit_barcode,
           ROUND(p.stock / COALESCE(pu.conversion_factor, 1), 2) AS unit_stock,
           pc.name AS category_name
    FROM products p
    LEFT JOIN product_categories pc ON pc.id = p.category_id
    LEFT JOIN product_units pu ON pu.product_id = p.id
    ORDER BY p.name, COALESCE(pu.sort_order, 0)
");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
}
?>
<div class="card">
    <div class="page-actions">
        <a href="assets/php/report_inventory_export.php" class="button-secondary" id="exportInventoryBtn">خروجی اکسل</a>
        <div class="filter-panel">
            <input type="search" id="inventorySearch" placeholder="جستجو بر اساس نام کالا، دسته‌بندی، نوع یا بارکد...">
            <select id="inventoryCategoryFilter">
                <?php echo buildCategoryFilterOptionsHtml($mysqli); ?>
            </select>
            <select id="inventoryTypeFilter">
                <option value="">همه انواع</option>
                <option value="product">محصول</option>
                <option value="service">خدمت</option>
            </select>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="action-table" id="inventoryReportTable">
            <thead>
                <tr>
                    <th><button type="button" class="th-sort" data-sort="name">نام کالا</button></th>
                    <th><button type="button" class="th-sort" data-sort="category">نام دسته‌بندی</button></th>
                    <th><button type="button" class="th-sort" data-sort="type">نوع</button></th>
                    <th><button type="button" class="th-sort" data-sort="unit">واحد</button></th>
                    <th><button type="button" class="th-sort" data-sort="purchase_price">قیمت خرید</button></th>
                    <th><button type="button" class="th-sort" data-sort="sale_price">قیمت فروش</button></th>
                    <th><button type="button" class="th-sort" data-sort="stock">موجودی</button></th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr class="empty-state-row"><td colspan="7" class="empty-state">کالایی یافت نشد</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php $cat = $r['category_name'] !== null ? $r['category_name'] : '-'; ?>
                        <tr class="inventory-report-row"
                            data-code="<?php echo htmlspecialchars($r['code'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-name="<?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-category="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"
                            data-category-id="<?php echo $r['category_id'] !== null ? $r['category_id'] : ''; ?>"
                            data-type="<?php echo $r['type']; ?>"
                            data-barcode="<?php echo htmlspecialchars($r['unit_barcode'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-unit="<?php echo htmlspecialchars($r['unit_name'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-purchase-price="<?php echo htmlspecialchars($r['purchase_price'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-sale-price="<?php echo htmlspecialchars($r['sale_price'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-stock="<?php echo htmlspecialchars($r['unit_stock'], ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $r['type'] === 'service' ? 'خدمت' : 'محصول'; ?></td>
                            <td><?php echo htmlspecialchars($r['unit_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo number_format((float) $r['purchase_price']); ?></td>
                            <td><?php echo number_format((float) $r['sale_price']); ?></td>
                            <td><?php echo rtrim(rtrim(number_format((float) $r['unit_stock'], 2, '.', ''), '0'), '.'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>