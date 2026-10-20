<?php

/**
 * Sales-Invoice-Proforma.php — قالب PDF پیش‌فاکتور فروش
 *
 * متغیرهای تزریق‌شده (همگی سمت سرور و از منبع حقیقت دیتابیس):
 *   $css, $inv, $items, $customer, $store, $settings,
 *   $province_name, $city_name, $accent, $amount_words, $taxable
 *
 * قالب فاقد هرگونه محاسبه مالی موازی است؛ فقط مقادیر ذخیره‌شده را نمایش می‌دهد.
 */

$logoPath = pdf_local_image_path($store['logo_path'] ?? null);
$sigPath = pdf_local_image_path($store['signature_path'] ?? null);
$stampPath = pdf_local_image_path($store['stamp_path'] ?? null);
$sigW = pdf_image_width($store['default_size_percentage'] ?? 80, 34);
$stampW = pdf_image_width($store['default_size_percentage'] ?? 80, 30);

$proTitle = trim((string) ($settings['proforma_title'] ?? ''));
if ($proTitle === '') {
    $proTitle = 'پیش‌فاکتور فروش';
}
$storeName = pdf_esc($store['store_name'] ?? '');
$customerFullName = pdf_esc(trim(((string) ($customer['first_name'] ?? '')) . ' ' . ((string) ($customer['last_name'] ?? ''))));
$customerPhone = pdf_esc($customer['phone'] ?? '');
$customerAddress = pdf_esc($customer['address'] ?? '');

/* رنگ تأییدشده فقط برای accentها */
$accent = pdf_esc($accent);
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <style><?php echo $css; ?></style>
    <style>
        .accent-rule { border-bottom-color: <?php echo $accent; ?>; }
        .section-title { border-right-color: <?php echo $accent; ?>; }
    </style>
</head>
<body>
<div class="invoice-doc">

    <!-- هدر پیش‌فاکتور -->
    <table class="pro-head" cellpadding="0" cellspacing="0">
        <tr>
            <td class="head-logo" style="width:33%;">
                <?php if ($logoPath): ?>
                    <img src="<?php echo pdf_esc($logoPath); ?>" alt="لوگو" style="max-height:18mm; max-width:40mm;">
                <?php endif; ?>
                <?php if ($storeName !== ''): ?>
                    <div class="store-name"><?php echo $storeName; ?></div>
                <?php endif; ?>
            </td>
            <td class="head-title" style="width:34%;">
                <h1 class="inv-title"><?php echo pdf_esc($proTitle); ?></h1>
            </td>
            <td style="width:33%; text-align:left;">
                <div class="ec-code">
                    کد اقتصادی
                    <strong dir="ltr"><?php echo pdf_esc($store['economic_code'] ?? '') ?: '—'; ?></strong>
                </div>
            </td>
        </tr>
    </table>
    <div class="accent-rule"></div>

    <!-- اطلاعات اصلی -->
    <table class="pro-info" cellpadding="0" cellspacing="0">
        <tr>
            <td class="info-label">شماره فاکتور</td>
            <td class="info-value" dir="ltr"><?php echo pdf_esc($inv['invoice_number'] ?? ''); ?></td>
            <td class="info-label">تاریخ</td>
            <td class="info-value"><?php echo pdf_esc($inv['invoice_date_shamsi'] ?? ''); ?></td>
            <td class="info-label">مشتری</td>
            <td class="info-value"><?php echo $customerFullName !== '' ? $customerFullName : '—'; ?></td>
        </tr>
    </table>

    <!-- جدول اقلام -->
    <div class="section-title">شرح کالا یا خدمات</div>
    <table class="items-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th class="c1">ردیف</th>
                <th class="c2">کد کالا</th>
                <th class="p3-description">شرح کالا یا خدمات</th>
                <th class="c4">تعداد</th>
                <th class="c5">مبلغ واحد</th>
                <th class="c6">مبلغ کل</th>
            </tr>
        </thead>
        <tbody>
        <?php $rowNo = 0; foreach ($items as $it): $rowNo++; ?>
            <tr>
                <td class="c1 center"><?php echo $rowNo; ?></td>
                <td class="c2 center"><?php echo pdf_esc($it['product_code'] ?? ''); ?></td>
                <td class="p3-description"><?php echo pdf_esc($it['product_name'] ?? ''); ?></td>
                <td class="c4 center qty">
                    <?php echo pdf_money_format($it['quantity'] ?? '0'); ?>
                    <?php if (!empty($it['unit_name'])): ?><?php echo ' ' . pdf_esc($it['unit_name']); ?><?php endif; ?>
                </td>
                <td class="c5 left"><?php echo pdf_money_format($it['unit_price'] ?? '0'); ?></td>
                <td class="c6 left"><?php echo pdf_money_format($it['line_total'] ?? '0'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (count($items) === 0): ?>
        <p style="color:#5d6b85; font-size:9pt;">—</p>
    <?php endif; ?>

    <!-- جمع‌بندی -->
    <table class="pro-summary" cellpadding="0" cellspacing="0">
        <tr>
            <td class="lbl">مبلغ به حروف</td>
            <td><?php echo pdf_esc($amount_words); ?></td>
        </tr>
        <tr class="grand">
            <td class="lbl">مبلغ قابل پرداخت</td>
            <td class="money"><?php echo pdf_money_format($inv['payable_amount'] ?? '0'); ?> ریال</td>
        </tr>
    </table>

    <!-- امضا -->
    <table class="sig-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="sig-box">
                <div class="sig-title">امضا و مهر فروشگاه</div>
                <?php if ($sigPath): ?><img src="<?php echo pdf_esc($sigPath); ?>" style="width:<?php echo $sigW; ?>mm;" alt="امضا"><?php endif; ?>
                <?php if ($stampPath): ?><img src="<?php echo pdf_esc($stampPath); ?>" style="width:<?php echo $stampW; ?>mm;" alt="مهر"><?php endif; ?>
            </td>
            <td class="sig-box">
                <div class="sig-title">امضا و مهر خریدار</div>
            </td>
        </tr>
    </table>

</div>
</body>
</html>