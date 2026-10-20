<?php
/**
 * defective_goods_report.php — داده JSON گزارش کالاهای معیوب (GET + احراز هویت)
 *
 * بدون CSRF (صرفاً خواندن). همان فیلتر تاریخ و صفحه‌بندی صفحه گزارش را
 * بازمی‌گرداند تا جدول/شمارنده‌ها پس از ثبت یا برگشت کالا بدون رفرش کامل
 * به‌روز شوند؛ سرور همچنان منبع حقیقت است.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/defective_goods_common.php';

$filters = dg_extract_date_filters($_GET);
$page    = max(1, (int) ($_GET['pageno'] ?? 1));

respond_json(true, '', dg_report_data($mysqli, $filters, $page, 25));