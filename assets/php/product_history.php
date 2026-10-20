<?php
/**
 * product_history.php — تاریخچه قیمت و فروش یک کالا (GET)
 *
 * ورودی: id شناسه کالا (عدد صحیح مثبت)
 *
 * ترتیب بارگذاری: boot.php → db.php → product_history_common.php
 *
 * این Endpoint فقط خواندنی است (GET) و هیچ تغییری در دیتابیس ایجاد نمی‌کند؛
 * بر همین اساس CSRF لازم ندارد (مانند دیگر Endpointهای GET پروژه).
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_history_common.php';

$idRaw = trim((string) ($_GET['id'] ?? ''));
if ($idRaw === '' || !ctype_digit($idRaw) || (int) $idRaw <= 0) {
    respond_error('شناسه کالا نامعتبر است.');
}

$data = buildProductHistory($mysqli, (int) $idRaw);
if ($data === null) {
    respond_error('کالای موردنظر یافت نشد.', 404);
}

respond_json(true, '', $data);