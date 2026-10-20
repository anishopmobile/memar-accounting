<?php

/**
 * sales_return_pdf_data.php — Data Provider فقط‌خواندنی برای PDF برگشت فروش
 *
 * منبع حقیقت: دیتابیس (سند برگشت، اقلام برگشت، مشتری، تنظیمات فروشگاه/فاکتور).
 * هیچ مقداری از ورودی کاربر به‌جز شناسه validateشده سند برگشت استفاده نمی‌شود.
 * helperهای نمایشی از invoice_pdf_common.php (معماری PDF موجود پروژه) بازاستفاده می‌شوند.
 */

declare(strict_types=1);

require_once __DIR__ . '/jdf.php';
require_once __DIR__ . '/invoice_pdf_common.php';

/**
 * بارگذاری همه داده‌های موردنیاز قالب PDF برگشت فروش.
 *
 * @return array ['ok'=>true, ...] | ['ok'=>false, 'error'=>...]
 */
function getSalesReturnPrintData(mysqli $mysqli, int $returnId): array
{
    /* --- سند برگشت + ارجاع به فاکتور اصلی --- */
    $stmt = $mysqli->prepare(
        'SELECT sr.*, inv.invoice_number AS invoice_number, inv.invoice_date AS invoice_date,
                inv.subtotal AS invoice_subtotal, inv.discount AS invoice_discount,
                inv.tax_amount AS invoice_tax, inv.payable_amount AS invoice_payable
         FROM sales_returns sr
         INNER JOIN invoices inv ON inv.id = sr.invoice_id
         WHERE sr.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $ret = $res ? $res->fetch_assoc() : null;
    if (!$ret) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    /* --- مشتری --- */
    $customer = null;
    $cid = (is_scalar($ret['customer_id'] ?? null) && (int) $ret['customer_id'] > 0)
        ? (int) $ret['customer_id']
        : 0;
    if ($cid > 0) {
        $stmt = $mysqli->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        $customer = $r ? $r->fetch_assoc() : null;
    }
    if (!$customer) {
        return ['ok' => false, 'error' => 'customer_not_found'];
    }

    /* --- اقلام برگشت (snapshot ذخیره‌شده) --- */
    $items = [];
    $stmt = $mysqli->prepare(
        'SELECT sri.*, p.code AS product_code, p.name AS product_name, p.type AS product_type
         FROM sales_return_items sri
         LEFT JOIN products p ON p.id = sri.product_id
         WHERE sri.return_id = ?
         ORDER BY sri.id ASC'
    );
    $stmt->bind_param('i', $returnId);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $items[] = $row;
        }
    }

    /* --- تنظیمات فروشگاه --- */
    $store = [];
    $q = $mysqli->query('SELECT * FROM store_settings WHERE id = 1 LIMIT 1');
    if ($q) {
        $store = $q->fetch_assoc() ?: [];
        $q->close();
    }

    /* --- تنظیمات فاکتور --- */
    $settings = [];
    $q = $mysqli->query('SELECT * FROM invoice_settings WHERE id = 1 LIMIT 1');
    if ($q) {
        $settings = $q->fetch_assoc() ?: [];
        $q->close();
    }

    $provinceName = pdf_province_name($store['province_id'] ?? null);
    $cityName = pdf_city_name($store['city_id'] ?? null);

    /* صفحه عمودی و فوتر ردیف سندهای رسمی (بازاستفاده از helper موجود) */
    $loc = pdf_direction_and_footer($settings, 'sales_invoice');

    $ret['return_date_shamsi'] = !empty($ret['return_date'])
        ? jdate('j F Y', strtotime((string) $ret['return_date']))
        : '';
    $ret['invoice_date_shamsi'] = !empty($ret['invoice_date'])
        ? jdate('j F Y', strtotime((string) $ret['invoice_date']))
        : '';

    return [
        'ok' => true,
        'error' => '',
        'ret' => $ret,
        'items' => $items,
        'customer' => $customer,
        'store' => $store,
        'settings' => $settings,
        'province_name' => $provinceName,
        'city_name' => $cityName,
        'footer_text' => $loc['footer'],
        'accent' => pdf_safe_color($settings['invoice_template_color'] ?? null),
    ];
}