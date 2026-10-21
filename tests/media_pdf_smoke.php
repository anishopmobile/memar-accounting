<?php
/** PDF smoke: render 5 document families, with media off/on, onto real mPDF. */
declare(strict_types=1);
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/invoice_pdf_common.php';
require_once __DIR__ . '/../vendor/autoload.php';

$png = tempnam(sys_get_temp_dir(), 'media_pdf_');
if ($png === false) throw new RuntimeException('Cannot create PDF fixture');
$img = imagecreatetruecolor(80, 80);
$color = imagecolorallocate($img, 18, 125, 200);
imagefill($img, 0, 0, $color);
imagepng($img, $png);
imagedestroy($img);
$css = file_get_contents(__DIR__ . '/../assets/invoice-pdf/css/invoice-pdf.css');
$invoice = [
    'invoice_number'=>'MEDIA-PDF-001', 'invoice_date_shamsi'=>'1405/08/01',
    'subtotal'=>'17000', 'discount'=>'0','tax_amount'=>'0','payable_amount'=>'17000',
    'payment_type'=>'cash','payment_status'=>'paid', 'note'=>''
];
$customer = ['first_name'=>'تست','last_name'=>'رسانه','phone'=>'','address'=>''];
$store = ['store_name'=>'فروشگاه تست','address'=>''];
$supplier = ['company_name'=>'تامین کننده تست','phone'=>''];
$item = [
    'product_id'=>1, 'product_code'=>'IMAGE-01', 'product_name'=>'کالای آزمایشی',
    'unit_name'=>'عدد','unit'=>'عدد','quantity'=>'1','unit_price'=>'17000',
    'discount'=>'0','line_total'=>'17000','media_print_path'=>$png
];
$base = [
    'css'=>$css,'inv'=>$invoice,'ret'=>array_merge($invoice,[
        'return_number'=>'RETURN-001', 'return_date_shamsi'=>'1405/08/01',
        'invoice_number'=>'MEDIA-PDF-001'
    ]),'invoice'=>$invoice,'items'=>[$item],'customer'=>$customer,
    'supplier'=>$supplier,'store'=>$store,'settings'=>[],
    'province_name'=>'','city_name'=>'','accent'=>'#206bc4','amount_words'=>'هفده هزار تومان',
    'taxable'=>'17000','is_proforma'=>false
];
$cases = [
    ['Sales-Invoice',false],['Sales-Invoice-Proforma',false],
    ['Purchase-Invoice',false],['Purchase-Invoice',true],['Sales-Return',false]
];
try {
    foreach ($cases as [$tpl,$proforma]) {
        $without = $base;
        $without['media_enabled'] = false;
        $without['is_proforma'] = $proforma;
        $htmlOld = pdf_render_template($tpl, $without);
        if (strpos($htmlOld, 'class="media-pdf-col"') !== false) {
            throw new RuntimeException("$tpl altered legacy image-free layout");
        }
        $with = $without;
        $with['media_enabled'] = true;
        $htmlNew = pdf_render_template($tpl, $with);
        if (substr_count($htmlNew,'class="media-pdf-col"') < 2 ||
            strpos($htmlNew,'IMAGE-01') === false ||
            strpos($htmlNew,'MEDIA-PDF-001') === false) {
            throw new RuntimeException("$tpl missing data or image column");
        }
        foreach ([false, true] as $enabled) {
            $mpdf = new \Mpdf\Mpdf([
                'mode'=>'utf-8','format'=>$enabled ? 'A4-L' : 'A4',
                'default_font'=>'xbriyaz','tempDir'=>sys_get_temp_dir()
            ]);
            $mpdf->WriteHTML($enabled ? $htmlNew : $htmlOld);
            $bytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
            if (!str_starts_with($bytes,'%PDF-') || strlen($bytes) < 1000) {
                throw new RuntimeException("$tpl cannot produce PDF");
            }
        }
        fwrite(STDOUT,"PASS PDF: $tpl (proforma=".($proforma?'yes':'no').") OFF/ON A4/A4-L\n");
    }
    $missing = $base;
    $missing['media_enabled']=true;
    $missing['items'][0]['media_print_path']=null;
    $fallback = pdf_render_template('Sales-Invoice',$missing);
    $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','tempDir'=>sys_get_temp_dir()]);
    $mpdf->WriteHTML($fallback);
    if (!str_starts_with($mpdf->Output('',\Mpdf\Output\Destination::STRING_RETURN),'%PDF-')) {
        throw new RuntimeException('Missing product picture failed to produce PDF');
    }
    fwrite(STDOUT,"PASS PDF: missing image fallback\n");
} finally {
    @unlink($png);
}
