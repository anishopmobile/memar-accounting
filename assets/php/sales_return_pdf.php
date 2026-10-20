<?php

/**
 * sales_return_pdf.php — Endpoint تولید PDF سند برگشت فروش (سرورمحور)
 *
 * GET (read-only؛ بدون CSRF مانند سایر GETهای پروژه):
 *   ?id=<returnId>&mode=view|download
 *
 * از معماری PDF موجود پروژه (mPDF + invoice_pdf_common + invoice-pdf.css)
 * استفاده می‌کند؛ هیچ کتابخانه/موتوری معرفی نمی‌کند و خروجی فاکتورهای
 * فروش/خرید موجود را تغییر نمی‌دهد.
 */

declare(strict_types=1);

require_once __DIR__ . '/boot.php';          // session + احراز هویت + CSRF helper
require_once __DIR__ . '/db.php';            // $mysqli
require_once __DIR__ . '/../../vendor/autoload.php'; // mPDF (Composer)
require_once __DIR__ . '/invoice_pdf_common.php';
require_once __DIR__ . '/sales_return_pdf_data.php';

use Mpdf\Mpdf;

/* ---------- خروجی خطای ساده و بی‌جزئیات برای کاربر ---------- */
function sr_pdf_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$idRaw = trim((string) ($_GET['id'] ?? ''));
if ($idRaw === '' || !ctype_digit($idRaw) || (int) $idRaw <= 0) {
    sr_pdf_fail('شناسه سند برگشت نامعتبر است.', 400);
}
$mode = ((string) ($_GET['mode'] ?? 'view') === 'download') ? 'download' : 'view';

$data = getSalesReturnPrintData($mysqli, (int) $idRaw);
if (!$data['ok']) {
    if ($data['error'] === 'customer_not_found') {
        sr_pdf_fail('مشتری سند برگشت یافت نشد.', 404);
    }
    sr_pdf_fail('سند برگشت فروش موردنظر یافت نشد.', 404);
}

/* ---------- ساخت HTML از قالب تعیین‌شده سمت سرور ---------- */
$css = (string) file_get_contents(__DIR__ . '/../invoice-pdf/css/invoice-pdf.css');

$view = [
    'css' => $css,
    'ret' => $data['ret'],
    'invoice' => $data['ret'],
    'items' => $data['items'],
    'customer' => $data['customer'],
    'store' => $data['store'],
    'settings' => $data['settings'],
    'province_name' => $data['province_name'],
    'city_name' => $data['city_name'],
    'accent' => $data['accent'],
    'footer_text' => $data['footer_text'],
    'amount_words' => pdf_amount_in_words($data['ret']['payable_amount'] ?? '0'),
    'taxable' => pdf_taxable_amount($data['ret']['subtotal'] ?? '0', $data['ret']['discount'] ?? '0'),
];

$oldErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);

try {
    $html = pdf_render_template('Sales-Return', $view);

    /* سند برگشت همواره عمودی (A4) چاپ می‌شود */
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
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

    $mpdf->SetTitle('برگشت فروش ' . pdf_esc($data['ret']['return_number'] ?? ''));
    $mpdf->SetDisplayMode('fullpage');

    /* فوتر چاپی (تکرارشونده در تمام صفحات) */
    $footerNumber = pdf_esc($data['ret']['return_number'] ?? '');
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
    error_log('sales_return_pdf error: ' . $e->getMessage());
    error_reporting($oldErrorReporting);
    sr_pdf_fail('ایجاد سند PDF با خطا مواجه شد. لطفاً بعداً تلاش کنید.', 500);
}

error_reporting($oldErrorReporting);

/* ---------- خروجی PDF ---------- */
$safeName = pdf_safe_filename($data['ret']['return_number'] ?? 'return');
$fileName = 'Sales-Return-' . $safeName . '.pdf';

if ($mode === 'download') {
    $mpdf->Output($fileName, \Mpdf\Output\Destination::DOWNLOAD);
} else {
    $mpdf->Output($fileName, \Mpdf\Output\Destination::INLINE);
}