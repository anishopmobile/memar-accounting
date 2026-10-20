<?php

/**
 * invoice_pdf_common.php — helperهای مشترک تولید PDF فاکتور فروش
 *
 * این فایل فقط توابع خالص (display helper) دارد و هیچ محاسبه مالی جدیدی
 * معرفی نمی‌کند. منبع حقیقت مبالغ، مقادیر ذخیره‌شده در دیتابیس است
 * (invoice_common.php همان‌طور که هست باقی می‌ماند).
 */

declare(strict_types=1);

/** Escape امن HTML برای خروجی داخل قالب‌های PDF */
function pdf_esc($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * اعتبارسنجی رنگ قالب — فقط hex خالص پذیرفته می‌شود تا هیچ ورودی مخربی
 * وارد CSS نشود.
 */
function pdf_safe_color($raw): string
{
    if (is_string($raw) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $raw) === 1) {
        return $raw;
    }
    return '#2068ff';
}

/** تبدیل شناسه استان به نام از فایل JSON ایستای پروژه */
function pdf_province_name($provinceId): string
{
    if ($provinceId === null || $provinceId === '') {
        return '';
    }
    $file = realpath(__DIR__ . '/../data/provinces.json');
    if ($file === false) {
        return '';
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        return '';
    }
    $id = (int) $provinceId;
    foreach ($json as $row) {
        if (isset($row['id']) && (int) $row['id'] === $id) {
            return is_string($row['name'] ?? null) ? (string) $row['name'] : '';
        }
    }
    return '';
}

/** تبدیل شناسه شهر به نام از فایل JSON ایستای پروژه */
function pdf_city_name($cityId): string
{
    if ($cityId === null || $cityId === '') {
        return '';
    }
    $file = realpath(__DIR__ . '/../data/cities.json');
    if ($file === false) {
        return '';
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        return '';
    }
    $id = (int) $cityId;
    foreach ($json as $row) {
        if (isset($row['id']) && (int) $row['id'] === $id) {
            return is_string($row['name'] ?? null) ? (string) $row['name'] : '';
        }
    }
    return '';
}
/**
 * تبدیل مسیر تصویر ذخیره‌شده در تنظیمات (مثل assets/uploads/logo.jpg) به مسیر
 * محلی امن. هیچ URL، مسیر مطلق یا path traversal پذیرفته نمی‌شود.
 * تصاویر آپلودی در پروژه ممکن است در assets/uploads یا uploads باشند.
 */
function pdf_local_image_path($dbPath): ?string
{
    if (!is_string($dbPath) || trim($dbPath) === '') {
        return null;
    }
    $dbPath = trim($dbPath);

    // بدون URL (http/ftp/data/...)
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $dbPath) === 1) {
        return null;
    }
    // بدون protocol-relative
    if (str_starts_with($dbPath, '//')) {
        return null;
    }
    // بدون مسیر مطلق / ریشه فایل سیستم
    if (str_starts_with($dbPath, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $dbPath) === 1) {
        return null;
    }

    // فقط نام فایل استخراج می‌شود؛ عبور از دایرکتوری‌ها عملاً غیرممکن است
    $fileName = basename(str_replace('\\', '/', $dbPath));
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return null;
    }
    if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $fileName) !== 1) {
        return null;
    }

    $root = realpath(__DIR__ . '/../..');
    if ($root === false) {
        return null;
    }
    foreach (['/assets/uploads/', '/uploads/'] as $sub) {
        $candidate = $root . $sub . $fileName;
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved)) {
            return str_replace('\\', '/', $resolved);
        }
    }
    return null;
}

/** محدودسازی عرض تصاویر امضا/مهر بر اساس default_size_percentage تنظیمات */
function pdf_image_width($pctRaw, float $baseMm): float
{
    $pct = filter_var($pctRaw, FILTER_VALIDATE_INT);
    if ($pct === false || $pct === null) {
        $pct = 80;
    }
    $pct = max(20, min(200, $pct));
    return round($baseMm * $pct / 100, 2);
}

/**
 * فرمت مبلغ (جداکننده هزارگان) بدون اعشارهای بی‌مورد؛ مطابق رفتار فعلی
 * frontend پروژه (splitNum).
 */
function pdf_money_format($amount): string
{
    $s = (string) ($amount ?? '0');
    if ($s === '') {
        $s = '0';
    }
    $neg = '';
    if (str_starts_with($s, '-')) {
        $neg = '-';
        $s = substr($s, 1);
    }
    $parts = explode('.', $s, 2);
    $int = trim($parts[0]);
    $int = $int === '' ? '0' : $int;
    $frac = rtrim($parts[1] ?? '', '0');
    $formatted = number_format((float) $int, 0, '.', ',');
    if ($frac !== '') {
        $formatted .= '.' . $frac;
    }
    return $neg . $formatted;
}

/** تبدیل عدد به حروف فارسی — هم‌ارز منطق موجود در assets/js/invoice.js */
function pdf_number_to_words(int $num): string
{
    $U1 = [
        0 => '', 1 => 'یک', 2 => 'دو', 3 => 'سه', 4 => 'چهار', 5 => 'پنج',
        6 => 'شش', 7 => 'هفت', 8 => 'هشت', 9 => 'نه', 10 => 'ده', 11 => 'یازده',
        12 => 'دوازده', 13 => 'سیزده', 14 => 'چهارده', 15 => 'پانزده',
        16 => 'شانزده', 17 => 'هفده', 18 => 'هجده', 19 => 'نوزده',
    ];
    $TENS = [2 => 'بیست', 3 => 'سی', 4 => 'چهل', 5 => 'پنجاه', 6 => 'شصت', 7 => 'هفتاد', 8 => 'هشتاد', 9 => 'نود'];
    $HUND = [1 => 'صد', 2 => 'دویست', 3 => 'سیصد', 4 => 'چهارصد', 5 => 'پانصد', 6 => 'ششصد', 7 => 'هفتصد', 8 => 'هشتصد', 9 => 'نهصد'];
    $SCALE = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];

    if ($num === 0) {
        return 'صفر';
    }
    if ($num < 0) {
        return 'منفی ' . pdf_number_to_words(-$num);
    }

    $words = '';
    $scale = 0;
    while ($num > 0) {
        $chunk = $num % 1000;
        if ($chunk !== 0) {
            $s = '';
            $h = intdiv($chunk, 100);
            $r = $chunk % 100;
            if ($h) {
                $s .= $HUND[$h];
            }
            if ($r) {
                if ($s) {
                    $s .= ' و ';
                }
                if ($r < 20) {
                    $s .= $U1[$r];
                } else {
                    $s .= $TENS[intdiv($r, 10)];
                    if ($r % 10) {
                        $s .= ' و ' . $U1[$r % 10];
                    }
                }
            }
            $chunkWords = $s . ($SCALE[$scale] !== '' ? ' ' . $SCALE[$scale] : '');
            // گروه جدید (ارزش بالاتر) همیشه در جلوی گروه‌های قبلی قرار می‌گیرد؛
            // معادل «unshift» در نسخه مرجع assets/js/invoice.js
            $words = $words === '' ? $chunkWords : $chunkWords . ' و ' . $words;
        }
        $num = intdiv($num, 1000);
        $scale++;
    }

    return $words;
}
/** مبلغ به حروف + واحد ریال (بر اساس مقدار معتبر ذخیره‌شده payable_amount) */
function pdf_amount_in_words($amount): string
{
    $s = (string) ($amount ?? '0');
    if (str_starts_with($s, '-')) {
        $s = substr($s, 1);
    }
    $intPart = explode('.', $s, 2)[0];
    $intPart = trim($intPart);
    // حذف جداکننده‌های هزارگان (","، "٬")؛ مبدل باید مقدار عددی خام دریافت کند
    $intPart = str_replace(',', '', $intPart);
    $intPart = str_replace('٬', '', $intPart);
    $intPart = $intPart === '' ? '0' : $intPart;
    return pdf_number_to_words((int) $intPart) . ' ریال';
}

/** برچسب فارسی نوع پرداخت */
function pdf_payment_type_label($type): string
{
    return [
        'cash' => 'نقدی',
        'pos' => 'کارتخوان',
        'bank_transfer' => 'واریز وجه',
    ][(string) $type] ?? (string) $type;
}

/** برچسب فارسی وضعیت پرداخت */
function pdf_payment_status_label($status): string
{
    return [
        'paid' => 'پرداخت شده',
        'unpaid' => 'پرداخت نشده',
        'partial' => 'پرداخت جزئی',
    ][(string) $status] ?? (string) $status;
}

/** نام ایمن فایل خروجی (مجموعاً ASCII امن) */
function pdf_safe_filename(string $name): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $safe = trim((string) $safe, '-');
    return $safe === '' ? 'invoice' : $safe;
}

/**
 * نگاشت نوع فاکتور به جهت و متن فوتر از تنظیمات موجود:
 *  - sales_invoice / purchase_invoice → official_invoice_direction / official_invoice_desc
 *  - sales_proforma / purchase_proforma → unofficial_invoice_direction / proforma_desc
 * (تنظیمات دارای فیلد اختصاصی جهت پیش‌فاکتور نیست؛ نزدیک‌ترین خانواده یعنی
 *  غیررسمی به‌صورت deterministic استفاده می‌شود.)
 */
function pdf_direction_and_footer(array $settings, string $type): array
{
    $direction = 'vertical';
    $footer = '';

    if ($type === 'sales_invoice' || $type === 'purchase_invoice') {
        $raw = $settings['official_invoice_direction'] ?? 'vertical';
        $direction = in_array($raw, ['vertical', 'horizontal'], true) ? (string) $raw : 'vertical';
        $footer = (string) ($settings['official_invoice_desc'] ?? '');
    } elseif ($type === 'sales_proforma' || $type === 'purchase_proforma') {
        $raw = $settings['unofficial_invoice_direction'] ?? 'vertical';
        $direction = in_array($raw, ['vertical', 'horizontal'], true) ? (string) $raw : 'vertical';
        $footer = (string) ($settings['proforma_desc'] ?? '');
    }

    return ['direction' => $direction, 'footer' => $footer];
}

/**
 * «مبلغ مشمول مالیات» یک مقدار فقط‌برای‌نمایش است که دقیقاً از رابطه تعریف‌شده
 * در backend (subtotal - discount) به دست می‌آید؛ هیچ محاسبه مالی موازی نیست.
 */
function pdf_taxable_amount($subtotal, $discount): string
{
    try {
        $result = bcsub((string) ($subtotal ?? '0'), (string) ($discount ?? '0'), 2);
        return is_string($result) ? $result : '0';
    } catch (\Throwable $e) {
        return '0';
    }
}

/** رندر یک قالب PHP به HTML با متغیرهای از پیش‌ساخته (server-side) */
function pdf_render_template(string $templateName, array $view): string
{
    $file = __DIR__ . '/../invoice-pdf/templates/' . $templateName . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('قالب PDF فاکتور یافت نشد.');
    }
    extract($view, EXTR_SKIP); // فقط کلیدهای ثابت ساخته‌شده توسط خود پروژه
    ob_start();
    include $file;
    return (string) ob_get_clean();
}