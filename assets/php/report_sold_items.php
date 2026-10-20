<?php
/**
 * report_sold_items.php — ریز کالاهای فروخته شده (صفحه‌بندی سمت سرور)
 *
 * GET بدون CSRF
 *
 * پارامترها:
 *   date_from   (میلادی YYYY-MM-DD)
 *   date_to     (میلادی YYYY-MM-DD)
 *   item_search (جستجو در کد/نام/بارکد کالا)
 *   page        عدد صفحه (پیش‌فرض ۱)
 *   per_page    تعداد در هر صفحه (پیش‌فرض ۲۵، حداکثر ۲۵)
 *
 * پاسخ: { success, data: { rows, total, page, per_page, total_pages } }
 *
 * صفحه‌بندی بر اساس فاکتور انجام می‌شود (نه ردیف اقلام)؛
 * ابتدا تعداد فاکتورها، سپس شناسه فاکتورهای صفحه و در نهایت
 * اقلام کامل همان فاکتورها واکشی می‌شود تا یک فاکتور چندردیفه
 * هرگز بین دو صفحه تقسیم نشود.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jdf.php';

$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to   = trim((string) ($_GET['date_to'] ?? ''));
$search    = trim((string) ($_GET['item_search'] ?? ''));

/* اعتبارسنجی صفحه‌بندی سمت سرور */
$page    = (int) ($_GET['page'] ?? 1);
$perPage = (int) ($_GET['per_page'] ?? 25);
if ($page < 1) {
    $page = 1;
}
if ($perPage < 1) {
    $perPage = 1;
}
if ($perPage > 25) {
    $perPage = 25;
}

$where  = ["i.type IN ('sales_invoice','sales_proforma')"];
$types  = '';
$params = [];

if ($date_from !== '') {
    $where[] = 'i.invoice_date >= ?';
    $types  .= 's';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = 'i.invoice_date <= ?';
    $types  .= 's';
    $params[] = $date_to;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(p.code LIKE ? OR p.name LIKE ? OR pu.barcode LIKE ?)';
    $types  .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);
$joins = 'FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    JOIN invoice_items ii ON i.id = ii.invoice_id
    JOIN products p ON ii.product_id = p.id
    LEFT JOIN product_units pu ON ii.unit_id = pu.id';

/* ---------- ۱) تعداد کل فاکتورهای منطبق (همان فیلترهای داده) ---------- */
$countSql = 'SELECT COUNT(DISTINCT i.id) AS c ' . $joins . ' WHERE ' . $whereSql;
$total = 0;
$stmt = $mysqli->prepare($countSql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
if ($res && ($row = $res->fetch_assoc())) {
    $total = (int) $row['c'];
}

$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

/* ---------- ۲) شناسه فاکتورهای صفحه جاری (جدیدترین‌ها اول) ---------- */
$ids = [];
$pageSql = 'SELECT DISTINCT i.id ' . $joins . ' WHERE ' . $whereSql . ' ORDER BY i.id DESC LIMIT ? OFFSET ?';
$stmt = $mysqli->prepare($pageSql);
if ($types !== '') {
    $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
}

/* ---------- ۳) اقلام کامل فاکتورهای صفحه (بدون تقسیم فاکتور بین صفحات) ---------- */
$invoices = [];
if (count($ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $idTypes = str_repeat('i', count($ids));
    $detailSql = 'SELECT i.id, i.invoice_number, i.type, i.invoice_date, i.payment_status, i.payable_amount,
                         CONCAT(c.first_name, " ", c.last_name) AS customer_name,
                         ii.id AS item_id, ii.quantity, ii.unit_price, ii.discount, ii.line_total, ii.unit_name,
                         p.code AS product_code, p.name AS product_name, pu.barcode
                  ' . $joins . '
                  WHERE i.type IN (\'sales_invoice\',\'sales_proforma\')
                    AND i.id IN (' . $placeholders . ')
                  ORDER BY i.id DESC, ii.id ASC';
    $stmt = $mysqli->prepare($detailSql);
    $stmt->bind_param($idTypes, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $invId = (int) $row['id'];
            if (!isset($invoices[$invId])) {
                $invoices[$invId] = [
                    'id'                  => $invId,
                    'invoice_number'      => $row['invoice_number'],
                    'type'                => $row['type'],
                    'invoice_date'        => $row['invoice_date'],
                    'invoice_date_shamsi' => $row['invoice_date'] ? jdate('j F Y', strtotime($row['invoice_date'])) : '',
                    'payment_status'      => $row['payment_status'],
                    'payable_amount'      => $row['payable_amount'],
                    'customer_name'       => $row['customer_name'],
                    'items'               => [],
                ];
            }
            $invoices[$invId]['items'][] = [
                'product_code' => $row['product_code'],
                'product_name' => $row['product_name'],
                'barcode'      => $row['barcode'],
                'unit_name'    => $row['unit_name'],
                'quantity'     => $row['quantity'],
                'unit_price'   => $row['unit_price'],
                'discount'     => $row['discount'],
                'line_total'   => $row['line_total'],
            ];
        }
    }
}

respond_json(true, '', [
    'rows'        => array_values($invoices),
    'total'       => $total,
    'page'        => $page,
    'per_page'    => $perPage,
    'total_pages' => $totalPages,
]);
