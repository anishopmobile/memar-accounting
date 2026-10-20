<?php
/**
 * sales_return_list.php — لیست سندهای برگشت فروش (GET بدون CSRF)
 * پارامترها: search (شماره برگشت/فاکتور/مشتری/تلفن)، page
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_return_common.php';

$filter = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'page'   => (int) ($_GET['page'] ?? 1),
];

$data = listSalesReturns($mysqli, $filter);
respond_json(true, '', $data);