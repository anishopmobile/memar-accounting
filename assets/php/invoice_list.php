<?php
/**
 * invoice_list.php — لیست فاکتورها (فروش/خرید) — GET بدون CSRF
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_common.php';

$filter = [
    'type_group'      => trim((string) ($_GET['type_group'] ?? '')),
    'search'          => trim((string) ($_GET['search'] ?? '')),
    'payment_status'  => trim((string) ($_GET['payment_status'] ?? '')),
    'type'            => trim((string) ($_GET['type'] ?? '')),
    'page'            => (int) ($_GET['page'] ?? 1),
    'per_page'        => (int) ($_GET['per_page'] ?? 15),
];

$data = listInvoices($mysqli, $filter);
respond_json(true, '', $data);
