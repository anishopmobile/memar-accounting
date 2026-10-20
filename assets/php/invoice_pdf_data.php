<?php

/**
 * invoice_pdf_data.php — Data Provider فقط‌خواندنی برای PDF فاکتور فروش
 *
 * منبع حقیقت: دیتابیس (فاکتور، اقلام، مشتری، تنظیمات فروشگاه/فاکتور).
 * هیچ مقداری از ورودی کاربر به‌جز شناسه validateشده فاکتور استفاده نمی‌شود.
 */

declare(strict_types=1);

require_once __DIR__ . '/jdf.php';
require_once __DIR__ . '/invoice_pdf_common.php';

/**
 * بارگذاری همه داده‌های موردنیاز قالب‌های فاکتور فروش.
 *
 * @return array ['ok'=>true, ...] یا ['ok'=>false, 'error'=>...]
 */
function getSalesInvoicePrintData(mysqli $mysqli, int $invoiceId): array
{
    /* --- فاکتور --- */
    $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $invoice = $res ? $res->fetch_assoc() : null;
    if (!$invoice) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $type = (string) $invoice['type'];
    if ($type !== 'sales_invoice' && $type !== 'sales_proforma') {
        // خرید و هر نوع نامعتبر دیگر هرگز از این endpoint رندر نمی‌شود
        return ['ok' => false, 'error' => 'invalid_type'];
    }

    /* --- مشتری (اطلاعات کامل از رکورد واقعی) --- */
    $customer = null;
    $cid = (is_scalar($invoice['customer_id'] ?? null) && (int) $invoice['customer_id'] > 0)
        ? (int) $invoice['customer_id']
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

    /* --- اقلام فاکتور (snapshot موجود) --- */
    $items = [];
    $stmt = $mysqli->prepare(
        'SELECT ii.*, p.code AS product_code, p.name AS product_name, p.type AS product_type
         FROM invoice_items ii
         LEFT JOIN products p ON p.id = ii.product_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.id ASC'
    );
    $stmt->bind_param('i', $invoiceId);
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
    $loc = pdf_direction_and_footer($settings, $type);

    $invoice['invoice_date_shamsi'] = !empty($invoice['invoice_date'])
        ? jdate('j F Y', strtotime((string) $invoice['invoice_date']))
        : '';

    $template = $type === 'sales_invoice' ? 'Sales-Invoice' : 'Sales-Invoice-Proforma';

    return [
        'ok' => true,
        'error' => '',
        'invoice' => $invoice,
        'items' => $items,
        'customer' => $customer,
        'store' => $store,
        'settings' => $settings,
        'province_name' => $provinceName,
        'city_name' => $cityName,
        'template' => $template,
        'direction' => $loc['direction'],
        'footer_text' => $loc['footer'],
        'template_color' => pdf_safe_color($settings['invoice_template_color'] ?? null),
    ];
}

/**
 * بارگذاری همه داده‌های موردنیاز قالب PDF فاکتور خرید (فاکتور قطعی/پیش‌فاکتور).
 *
 * فقط رکوردهای واقعی ماژول خرید (purchase_invoice / purchase_proforma) پذیرفته
 * می‌شود؛ هر نوع دیگر (از جمله فاکتور فروش) رد می‌شود.
 *
 * @return array ['ok'=>true, ...] یا ['ok'=>false, 'error'=>...]
 */
function getPurchaseInvoicePrintData(mysqli $mysqli, int $invoiceId): array
{
    /* --- فاکتور --- */
    $stmt = $mysqli->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $invoice = $res ? $res->fetch_assoc() : null;
    if (!$invoice) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    $type = (string) $invoice['type'];
    if ($type !== 'purchase_invoice' && $type !== 'purchase_proforma') {
        // فروش و هر نوع نامعتبر دیگر هرگز از این مسیر رندر نمی‌شود
        return ['ok' => false, 'error' => 'invalid_type'];
    }

    /* --- تامین‌کننده (طرف فروشنده در معامله خرید) — اطلاعات کامل از رکورد واقعی --- */
    $supplier = null;
    $sid = (is_scalar($invoice['supplier_id'] ?? null) && (int) $invoice['supplier_id'] > 0)
        ? (int) $invoice['supplier_id']
        : 0;
    if ($sid > 0) {
        $stmt = $mysqli->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $sid);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        $supplier = $r ? $r->fetch_assoc() : null;
    }
    if (!$supplier) {
        return ['ok' => false, 'error' => 'supplier_not_found'];
    }

    /* --- اقلام فاکتور (snapshot موجود) --- */
    $items = [];
    $stmt = $mysqli->prepare(
        'SELECT ii.*, p.code AS product_code, p.name AS product_name, p.type AS product_type
         FROM invoice_items ii
         LEFT JOIN products p ON p.id = ii.product_id
         WHERE ii.invoice_id = ?
         ORDER BY ii.id ASC'
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $items[] = $row;
        }
    }

    /* --- تنظیمات فروشگاه (خریدار) و فاکتور (جداکننده مشترک با فاکتور فروش) --- */
    $store = [];
    $q = $mysqli->query('SELECT * FROM store_settings WHERE id = 1 LIMIT 1');
    if ($q) {
        $store = $q->fetch_assoc() ?: [];
        $q->close();
    }

    $settings = [];
    $q = $mysqli->query('SELECT * FROM invoice_settings WHERE id = 1 LIMIT 1');
    if ($q) {
        $settings = $q->fetch_assoc() ?: [];
        $q->close();
    }

    $provinceName = pdf_province_name($store['province_id'] ?? null);
    $cityName = pdf_city_name($store['city_id'] ?? null);
    $loc = pdf_direction_and_footer($settings, $type);

    $invoice['invoice_date_shamsi'] = !empty($invoice['invoice_date'])
        ? jdate('j F Y', strtotime((string) $invoice['invoice_date']))
        : '';

    return [
        'ok' => true,
        'error' => '',
        'invoice' => $invoice,
        'items' => $items,
        'supplier' => $supplier,
        'store' => $store,
        'settings' => $settings,
        'province_name' => $provinceName,
        'city_name' => $cityName,
        'is_proforma' => $type === 'purchase_proforma',
        'template' => 'Purchase-Invoice',
        'direction' => $loc['direction'],
        'footer_text' => $loc['footer'],
        'template_color' => pdf_safe_color($settings['invoice_template_color'] ?? null),
    ];
}