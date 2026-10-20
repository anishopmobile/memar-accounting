<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

$perPage = 5;

/*
 * فقط کالاهایی که موجودی آنها کمتر یا مساوی حداقل موجودی تعیین‌شده است.
 *
 * stock <= min_stock
 */

$countSql = "
    SELECT COUNT(*) AS total
    FROM products
    WHERE type = 'product'
      AND stock <= min_stock 
";

$countResult = $mysqli->query($countSql);

if (!$countResult) {
    echo json_encode([
        'success' => false,
        'message' => 'خطا در دریافت تعداد کالاهای کم‌موجودی.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$countRow = $countResult->fetch_assoc();
$total = (int) ($countRow['total'] ?? 0);
$countResult->free();

$totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

$stmt = $mysqli->prepare("
    SELECT id, name, unit, stock, min_stock
    FROM products
    WHERE type = 'product'
      AND stock <= min_stock AND name NOT LIKE 'بدهی%'
    ORDER BY stock ASC, id DESC
    LIMIT ?, ?
");

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'خطا در آماده‌سازی درخواست کالاهای کم‌موجودی.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param('ii', $offset, $perPage);
$stmt->execute();

$result = $stmt->get_result();

$tableBody = '';

if ($result && $result->num_rows > 0) {

    while ($row = $result->fetch_assoc()) {

        $name = htmlspecialchars(
            (string) ($row['name'] ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );

        $stockVal = (float)($row['stock'] ?? 0);
		$stock = htmlspecialchars(
			rtrim(rtrim(number_format($stockVal, 2, '.', ''), '0'), '.'),
			ENT_QUOTES,
			'UTF-8'
		);
        $unit = htmlspecialchars(
            (string) ($row['unit'] ?? 'عدد'),
            ENT_QUOTES,
            'UTF-8'
        );

        $tableBody .= '<tr>';
        $tableBody .= '<td>' . $name . '</td>';
        $tableBody .= '<td>' . $stock . ' ' . $unit . '</td>';
        $tableBody .= '</tr>';
    }

} else {

    $tableBody = '
        <tr>
            <td colspan="2" class="empty-state">
                کالای کم‌موجودی وجود ندارد
            </td>
        </tr>
    ';
}

$stmt->close();

/*
 * ساخت صفحه‌بندی
 */
$pagination = '';

if ($total > $perPage) {

    $pagination .= '<div class="dashboard-pagination" aria-label="صفحه‌بندی کالاهای کم‌موجودی">';

    /*
     * دکمه قبلی
     */
    if ($page > 1) {
        $pagination .= '
            <button
                type="button"
                class="dashboard-page-button dashboard-page-prev"
                data-page="' . ($page - 1) . '"
                aria-label="صفحه قبلی">
                قبلی
            </button>
        ';
    } else {
        $pagination .= '
            <button
                type="button"
                class="dashboard-page-button dashboard-page-prev"
                disabled
                aria-label="صفحه قبلی">
                قبلی
            </button>
        ';
    }

    /*
     * شماره صفحات
     */
    for ($i = 1; $i <= $totalPages; $i++) {

        $activeClass = ($i === $page)
            ? ' active'
            : '';

        $pagination .= '
            <button
                type="button"
                class="dashboard-page-button' . $activeClass . '"
                data-page="' . $i . '">
                ' . $i . '
            </button>
        ';
    }

    /*
     * دکمه بعدی
     */
    if ($page < $totalPages) {
        $pagination .= '
            <button
                type="button"
                class="dashboard-page-button dashboard-page-next"
                data-page="' . ($page + 1) . '"
                aria-label="صفحه بعدی">
                بعدی
            </button>
        ';
    } else {
        $pagination .= '
            <button
                type="button"
                class="dashboard-page-button dashboard-page-next"
                disabled
                aria-label="صفحه بعدی">
                بعدی
            </button>
        ';
    }

    $pagination .= '</div>';
}

echo json_encode([
    'success' => true,
    'data' => [
        'tableBody' => $tableBody,
        'pagination' => $pagination,
        'total' => $total,
        'currentPage' => $page,
        'totalPages' => $totalPages
    ]
], JSON_UNESCAPED_UNICODE);