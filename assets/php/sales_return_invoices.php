<?php
/**
 * sales_return_invoices.php — فاکتورهای فروش قطعی قابل‌انتخاب برای برگشت (GET بدون CSRF)
 * فقط فاکتورهای sales_invoice (قطعی) بازگردانده می‌شوند؛ پیش‌فاکتور هرگز.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sales_return_common.php';

$search = trim((string) ($_GET['search'] ?? ''));
$rows = sr_eligible_invoices($mysqli, $search);
respond_json(true, '', $rows);