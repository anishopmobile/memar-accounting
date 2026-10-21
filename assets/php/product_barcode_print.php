<?php
/**
 * Barcode labels for browser printing or PDF download.
 *
 * A dedicated document, rather than printing an existing modal within the
 * admin shell, avoids blank sheets caused by the original page's layout.
 * The barcode always comes from the product row, never from a URL parameter.
 */
declare(strict_types=1);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/product_barcode_common.php';

function barcode_print_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$idRaw = (string) ($_GET['id'] ?? '');
$qtyRaw = (string) ($_GET['qty'] ?? '');
$mode = (string) ($_GET['mode'] ?? 'print');
if (!preg_match('/^[1-9][0-9]{0,9}$/D', $idRaw)
    || filter_var($idRaw, FILTER_VALIDATE_INT) === false
    || !preg_match('/^[1-9][0-9]{0,3}$/D', $qtyRaw)
    || (int) $qtyRaw > 1000
    || !in_array($mode, ['print', 'download'], true)) {
    barcode_print_fail('شناسه، تعداد یا نوع خروجی نامعتبر است.', 400);
}
$id = (int) $idRaw;
$qty = (int) $qtyRaw;
$stmt = $mysqli->prepare('SELECT id, name, barcode FROM products WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$product || trim((string) $product['barcode']) === '') {
    barcode_print_fail('کالا یا بارکد یافت نشد.', 404);
}
$barcode = (string) $product['barcode'];
try {
    $svg = product_barcode_code128_svg($barcode);
} catch (InvalidArgumentException $e) {
    barcode_print_fail('این بارکد قابلیت چاپ با Code 128 را ندارد.', 422);
}

function barcode_print_esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Fixed-size 3-column A4 sheets, each label kept together across pages. */
function barcode_print_html(int $id, string $name, string $barcode, int $qty, string $svg, bool $forPdf): string
{
    $encodedSvg = 'data:image/svg+xml;base64,' . base64_encode($svg);
    $safeId = barcode_print_esc((string) $id);
    $safeName = barcode_print_esc($name);
    $safeBarcode = barcode_print_esc($barcode);
    $label = '<div class="barcode-document-label">'
        . '<img src="' . $encodedSvg . '" alt="' . $safeBarcode . '">'
        . '<div class="barcode-document-id">Product ID: ' . $safeId . '</div>'
        . '<div class="barcode-document-value">' . $safeBarcode . '</div>'
        . '</div>';
    $table = '';
    for ($i = 0; $i < $qty; $i += 3) {
        $table .= '<tr>';
        for ($col = 0; $col < 3; $col++) {
            $table .= '<td>' . ($i + $col < $qty ? $label : '') . '</td>';
        }
        $table .= '</tr>';
    }
    $css = '@page { size: A4 portrait; margin: 10mm; }'
        . 'html, body { margin:0; padding:0; background:#fff; color:#000; }'
        . '.barcode-document-table { width:100%; border-collapse:collapse; table-layout:fixed; }'
        . '.barcode-document-table td { width:33.333%; height:39mm; padding:1.5mm; vertical-align:top; border:0; }'
        . '.barcode-document-table tr { page-break-inside:avoid; break-inside:avoid; }'
        . '.barcode-document-label { height:36mm; padding:2mm 1mm; text-align:center;'
        . 'font-family:DejaVu Sans,Arial,sans-serif; page-break-inside:avoid; break-inside:avoid; }'
        . '.barcode-document-label img { display:block; width:95%; height:21mm; margin:0 auto 1mm; }'
        . '.barcode-document-id { font-size:9pt; font-weight:bold; line-height:1.4; direction:ltr; }'
        . '.barcode-document-value { font-size:9pt; line-height:1.35; direction:ltr; }'
        . '@media screen { body { max-width:210mm; padding:9mm; margin:0 auto; }'
        . '.barcode-document-table td { border:1px dashed #d9e0e8; } }'
        . '@media print { .barcode-document-table td { border:none; } }';
    $head = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>چاپ بارکد ' . $safeName . '</title><style>' . $css . '</style></head>';
    $printJs = $forPdf ? '' : '<script>window.addEventListener("load",function(){'
        . 'window.focus();window.print();});</script>';
    // The document contains ONLY the printable labels, never the hidden admin shell.
    return $head . '<body><table class="barcode-document-table" dir="ltr">'
        . '<tbody>' . $table . '</tbody></table>' . $printJs . '</body></html>';
}

if ($mode === 'print') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo barcode_print_html($id, (string) $product['name'], $barcode, $qty, $svg, false);
    exit;
}

/**
 * Draw barcode PDF labels directly with mPDF vector primitives.
 *
 * The previous PDF passed a full HTML page containing a base64 SVG img to
 * WriteHTML(). That path may silently render empty content in mPDF despite
 * returning a valid PDF. Drawing the original Code128 bits into the PDF
 * canvas makes each label visible and keeps bars razor-sharp when printed.
 * Browser print mode above still uses the existing SVG/HTML document.
 */
function barcode_print_draw_pdf(\Mpdf\Mpdf $pdf, int $productId, string $barcode, int $qty): void
{
    $bits = product_barcode_code128_bits($barcode); // Existing Code128-B encoder.
    $quietZone = 10; // Code128 quiet zone: at least 10 modules on each side.
    $totalModules = strlen($bits) + 2 * $quietZone;
    $labelWidth = 190.0 / 3.0; // A4 - 2 x 10mm margins.
    $moduleMm = min(0.32, 57.0 / $totalModules);
    $drawWidth = $totalModules * $moduleMm;
    $barHeight = 20.0;
    $rowsPerPage = 6;
    $columns = 3;
    $labelsPerPage = $rowsPerPage * $columns;
    $pdf->SetAutoPageBreak(false);

    for ($labelNo = 0; $labelNo < $qty; $labelNo++) {
        if ($labelNo % $labelsPerPage === 0) {
            $pdf->AddPage();
            $pdf->SetFillColor(0, 0, 0);
            $pdf->SetTextColor(0, 0, 0);
        }

        $cell = $labelNo % $labelsPerPage;
        $column = $cell % $columns;
        $row = intdiv($cell, $columns);
        $cellX = 10.0 + $column * $labelWidth;
        $cellY = 13.0 + $row * 42.0;

        // Center the full symbol, including the 10-module silent margins.
        $barX = $cellX + ($labelWidth - $drawWidth) / 2.0 + $quietZone * $moduleMm;
        $barY = $cellY + 3.0;
        $length = strlen($bits);

        // Draw continuous black runs, not thousands of individual modules.
        for ($offset = 0; $offset < $length;) {
            if ($bits[$offset] === '0') {
                $offset++;
                continue;
            }
            $runStart = $offset;
            while ($offset < $length && $bits[$offset] === '1') {
                $offset++;
            }
            $pdf->Rect(
                $barX + $runStart * $moduleMm,
                $barY,
                ($offset - $runStart) * $moduleMm,
                $barHeight,
                'F'
            );
        }

        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetXY($cellX, $cellY + 24.0);
        $pdf->Cell($labelWidth, 5.0, 'Product ID: ' . $productId, 0, 0, 'C');
        $pdf->SetFont('dejavusans', '', strlen($barcode) > 24 ? 6 : 9);
        $pdf->SetXY($cellX, $cellY + 29.0);
        $pdf->Cell($labelWidth, 5.0, $barcode, 0, 0, 'C');
    }
}

require_once __DIR__ . '/../../vendor/autoload.php'; // Existing mPDF installation
$oldErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_top' => 10,
        'margin_bottom' => 10,
        'margin_left' => 10,
        'margin_right' => 10,
        'default_font' => 'dejavusans',
    ]);
    $mpdf->SetTitle('Barcode ' . $id);
    barcode_print_draw_pdf($mpdf, $id, $barcode, $qty);
    $filename = 'barcode-' . $id . '-' . $qty . '.pdf';
    header('Cache-Control: no-store');
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (Throwable $e) {
    error_log('product_barcode_print: ' . $e->getMessage());
    error_reporting($oldErrorReporting);
    barcode_print_fail('تولید فایل PDF با خطا مواجه شد.', 500);
}
error_reporting($oldErrorReporting);
