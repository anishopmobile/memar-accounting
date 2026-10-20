<?php

/**
 * invoice_pdf_purchase.php — Endpoint تولید PDF فاکتور خرید (سرورمحور)
 *
 * GET (read-only؛ بدون CSRF مانند سایر GETهای پروژه):
 *   ?id=<invoiceId>&mode=view|download
 *
 * فقط رکوردهای واقعی ماژول خرید (purchase_invoice / purchase_proforma) رندر
 * می‌شود. فاکتور فروش، برگشت فروش یا هر نوع دیگر از این مسیر رد می‌شود.
 * قالب و جهت صفحه فقط از رکورد واقعی فاکتور و تنظیمات موجود دیتابیس تعیین
 * می‌شود؛ هیچ پارامتر قالب/مسیر/URL از کلاینت پذیرفته نمی‌شود.
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';          // session + احراز هویت + CSRF helper
require_once __DIR__ . '/db.php';            // $mysqli
require_once __DIR__ . '/../../vendor/autoload.php'; // mPDF (Composer)
require_once __DIR__ . '/invoice_pdf_common.php';
require_once __DIR__ . '/invoice_pdf_data.php';

use Mpdf\Mpdf;

/* ---------- خروجی خطای ساده و بی‌جزئیات برای کاربر ---------- */
function pdf_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$idRaw = trim((string) ($_GET['id'] ?? ''));
if ($idRaw === '' || !ctype_digit($idRaw) || (int) $idRaw <= 0) {
    pdf_fail('شناسه فاکتور نامعتبر است.', 400);
}
$mode = ((string) ($_GET['mode'] ?? 'view') === 'download') ? 'download' : 'view';

$data = getPurchaseInvoicePrintData($mysqli, (int) $idRaw);
if (!$data['ok']) {
    if ($data['error'] === 'invalid_type') {
        pdf_fail('فاکتور انتخابی از نوع قابل‌چاپ خرید (قطع/پیش‌فاکتور) نیست.', 404);
    } elseif ($data['error'] === 'supplier_not_found') {
        pdf_fail('تامین‌کننده فاکتور موردنظر یافت نشد.', 404);
    }
    pdf_fail('فاکتور موردنظر یافت نشد.', 404);
}

/* ---------- ساخت HTML از قالب تعیین‌شده سمت سرور ---------- */
$css = (string) file_get_contents(__DIR__ . '/../invoice-pdf/css/invoice-pdf.css');

$view = [
    'css' => $css,
    'inv' => $data['invoice'],
    'items' => $data['items'],
    'supplier' => $data['supplier'],
    'store' => $data['store'],
    'settings' => $data['settings'],
    'province_name' => $data['province_name'],
    'city_name' => $data['city_name'],
    'accent' => $data['template_color'],
    'is_proforma' => $data['is_proforma'],
    'amount_words' => pdf_amount_in_words($data['invoice']['payable_amount'] ?? '0'),
    'taxable' => pdf_taxable_amount($data['invoice']['subtotal'] ?? '0', $data['invoice']['discount'] ?? '0'),
];

$oldErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

try {
    $html = pdf_render_template($data['template'], $view);

    $orientation = $data['direction'] === 'horizontal' ? 'A4-L' : 'A4';
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => $orientation,
        'default_font' => 'xbriyaz',
        'margin_top' => 14,
        'margin_bottom' => 16,
        'margin_left' => 12,
        'margin_right' => 12,
        'setAutoBottomMargin' => 'pad',
        'setAutoTopMargin' => 'pad',
        'useSubstitutions' => true,
        'backupSubsFont' => ['dejavusans', 'freesans'],
    ]);

    $mpdf->SetTitle('فاکتور ' . pdf_esc($data['invoice']['invoice_number'] ?? ''));
    $mpdf->SetDisplayMode('fullpage');

    /* فوتر چاپی (تکرارشونده در تمام صفحات) */
    $footerNumber = pdf_esc($data['invoice']['invoice_number'] ?? '');
    $footerText = pdf_esc($data['footer_text']);
    $mpdf->SetHTMLFooter(
        '<table width="100%" style="border-top:0.35pt solid #c9d2e0; padding-top:4pt; font-size:8pt; color:#5d6b85;">'
        . '<tr>'
        . '<td style="width:33%; text-align:right;">' . $footerNumber . '</td>'
        . '<td style="width:34%; text-align:center;">' . $footerText . '</td>'
        . '<td style="width:33%; text-align:left;">' . '{PAGENO}' . ' / ' . '{nbpg}' . '</td>'
        . '</tr>'
        . '</table>'
    );

    $mpdf->WriteHTML($html);
} catch (\Throwable $e) {
    error_log('invoice_pdf_purchase error: ' . $e->getMessage());
    error_reporting($oldErrorReporting);
    pdf_fail('ایجاد سند PDF با خطا مواجه شد. لطفاً بعداً تلاش کنید.', 500);
}

error_reporting($oldErrorReporting);

/* ---------- خروجی PDF ---------- */
$safeName = pdf_safe_filename($data['invoice']['invoice_number'] ?? 'invoice');
$fileName = pdf_safe_filename($data['template']) . '-' . $safeName . '.pdf';

if ($mode === 'download') {
    $mpdf->Output($fileName, \Mpdf\Output\Destination::DOWNLOAD);
} else {
    $mpdf->Output($fileName, \Mpdf\Output\Destination::INLINE);
}