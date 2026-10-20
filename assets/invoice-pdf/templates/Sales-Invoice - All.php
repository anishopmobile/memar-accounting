<?php

/**
 * Sales-Invoice.php — قالب PDF فاکتور فروش (قطعی)
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

$storeName = pdf_esc($store['store_name'] ?? '');
$customerFullName = pdf_esc(trim(((string) ($customer['first_name'] ?? '')) . ' ' . ((string) ($customer['last_name'] ?? ''))));

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
        .totals-table .strong { color: <?php echo $accent; ?>; }
    </style>
</head>
<body>
<div class="invoice-doc">

    <!-- هدر -->
    <table class="head-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="head-logo">
                <?php if ($logoPath): ?>
                    <img src="<?php echo pdf_esc($logoPath); ?>" alt="لوگو" style="max-height:18mm; max-width:42mm;">
                <?php endif; ?>
                <?php if ($storeName !== ''): ?>
                    <div class="store-name"><?php echo $storeName; ?></div>
                <?php endif; ?>
            </td>
            <td class="head-title">
                <h1 class="inv-title">فاکتور فروش کالا و خدمات</h1>
            </td>
            <td class="head-meta">
                <table class="meta-box" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="meta-label">شماره فاکتور</td>
                        <td class="meta-value" dir="ltr"><?php echo pdf_esc($inv['invoice_number'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td class="meta-label">تاریخ</td>
                        <td class="meta-value"><?php echo pdf_esc($inv['invoice_date_shamsi'] ?? ''); ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <div class="accent-rule"></div>

    <!-- مشخصات فروشنده -->
    <div class="section-title">مشخصات فروشنده</div>
    <table class="info-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="info-cell">
                <div class="lbl">نام فروشگاه / شخص</div>
                <div class="val"><?php echo $storeName !== '' ? $storeName : '—'; ?></div>
                <div class="lbl">کد اقتصادی</div>
                <div class="val"><?php echo pdf_esc($store['economic_code'] ?? '') ?: '—'; ?></div>
            </td>
            <td class="info-cell">
                <div class="lbl">کد ملی</div>
                <div class="val"><?php echo pdf_esc($store['national_code'] ?? '') ?: '—'; ?></div>
                <div class="lbl">شماره ثبت</div>
                <div class="val"><?php echo pdf_esc($store['registration_number'] ?? '') ?: '—'; ?></div>
            </td>
            <td class="info-cell">
                <div class="lbl">استان</div>
                <div class="val"><?php echo pdf_esc($province_name) ?: '—'; ?></div>
                <div class="lbl">شهر</div>
                <div class="val"><?php echo pdf_esc($city_name) ?: '—'; ?></div>
                <div class="lbl">کد پستی</div>
                <div class="val"><?php echo pdf_esc($store['postal_code'] ?? '') ?: '—'; ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="3" class="info-cell info-full">
                <span class="lbl-inline">آدرس کامل:</span>
                <?php echo pdf_esc($store['address'] ?? '') ?: '—'; ?>
                <span class="lbl-inline">تلفن:</span>
                <?php echo pdf_esc($store['phone'] ?? '') ?: '—'; ?>
            </td>
        </tr>
    </table>
<!-- مشخصات خریدار -->
    <div class="section-title">مشخصات خریدار</div>
    <table class="info-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="info-cell">
                <div class="lbl">نام و نام خانوادگی</div>
                <div class="val"><?php echo $customerFullName !== '' ? $customerFullName : '—'; ?></div>
                <div class="lbl">کد ملی</div>
                <div class="val"><?php echo pdf_esc($customer['national_code'] ?? '') ?: '—'; ?></div>
            </td>
            <td class="info-cell">
                <div class="lbl">کد اقتصادی</div>
                <div class="val"><?php echo pdf_esc($customer['economic_code'] ?? '') ?: '—'; ?></div>
                <div class="lbl">شماره ثبت</div>
                <div class="val"><?php echo pdf_esc($customer['registration_number'] ?? '') ?: '—'; ?></div>
            </td>
            <td class="info-cell">
                <div class="lbl">کد پستی</div>
                <div class="val"><?php echo pdf_esc($customer['postal_code'] ?? '') ?: '—'; ?></div>
            </td>
        </tr>
        <tr>
            <td colspan="3" class="info-cell info-full">
                <span class="lbl-inline">آدرس کامل:</span>
                <?php echo pdf_esc($customer['address'] ?? '') ?: '—'; ?>
                <span class="lbl-inline">تلفن:</span>
                <?php echo pdf_esc($customer['phone'] ?? '') ?: '—'; ?>
            </td>
        </tr>
    </table>

    <!-- جدول اقلام -->
    <div class="section-title">مشخصات کالا یا خدمات مورد معامله</div>
    <table class="items-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th class="c1">ردیف</th>
                <th class="c2">کد کالا</th>
                <th class="c3">شرح کالا یا خدمات</th>
                <th class="c4">تعداد</th>
                <th class="c5">مبلغ واحد</th>
                <th class="c6">مبلغ کل</th>
                <th class="c7">تخفیف</th>
            </tr>
        </thead>
        <tbody>
        <?php $rowNo = 0; foreach ($items as $it): $rowNo++; ?>
            <tr>
                <td class="c1 center"><?php echo $rowNo; ?></td>
                <td class="c2 center"><?php echo pdf_esc($it['product_code'] ?? ''); ?></td>
                <td class="c3"><?php echo pdf_esc($it['product_name'] ?? ''); ?></td>
                <td class="c4 center qty">
                    <?php echo pdf_money_format($it['quantity'] ?? '0'); ?>
                    <?php if (!empty($it['unit_name'])): ?><?php echo ' ' . pdf_esc($it['unit_name']); ?><?php endif; ?>
                </td>
                <td class="c5 left"><?php echo pdf_money_format($it['unit_price'] ?? '0'); ?></td>
                <td class="c6 left"><?php echo pdf_money_format($it['line_total'] ?? '0'); ?></td>
                <td class="c7 left"><?php echo pdf_money_format($it['discount'] ?? '0'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (count($items) === 0): ?>
        <p style="color:#5d6b85; font-size:9pt;">—</p>
    <?php endif; ?>
<!-- جمع‌بندی + شرایط و نحوه پرداخت -->
    <table class="summary-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="summary-side">
                <table class="totals-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td>مبلغ مشمول مالیات</td>
                        <td class="money"><?php echo pdf_money_format($taxable); ?></td>
                    </tr>
                    <tr>
                        <td>تخفیف فاکتور</td>
                        <td class="money"><?php echo pdf_money_format($inv['discount'] ?? '0'); ?></td>
                    </tr>
                    <?php if ((float) ($inv['tax_amount'] ?? 0) > 0): ?>
                    <tr>
                        <td>مالیات (<?php echo pdf_money_format($inv['tax_rate'] ?? '0'); ?>٪)</td>
                        <td class="money"><?php echo pdf_money_format($inv['tax_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="grand">
                        <td>مبلغ قابل پرداخت</td>
                        <td class="money strong"><?php echo pdf_money_format($inv['payable_amount'] ?? '0'); ?> ریال</td>
                    </tr>
                </table>
            </td>
            <td class="payment-side">
                <div class="section-title small">شرایط و نحوه پرداخت</div>
                <table class="totals-table" cellpadding="0" cellspacing="0">
                    <tr>
                        <td>نوع پرداخت</td>
                        <td class="money"><?php echo pdf_esc(pdf_payment_type_label($inv['payment_type'] ?? '')); ?></td>
                    </tr>
                    <tr>
                        <td>وضعیت پرداخت</td>
                        <td class="money"><?php echo pdf_esc(pdf_payment_status_label($inv['payment_status'] ?? '')); ?></td>
                    </tr>
                    <?php if (!empty(trim((string) ($inv['note'] ?? '')))): ?>
                    <tr>
                        <td>یادداشت</td>
                        <td><?php echo pdf_esc($inv['note']); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
        <tr>
            <td colspan="2" class="words-cell">مبلغ قابل پرداخت به حروف: <?php echo pdf_esc($amount_words); ?></td>
        </tr>
    </table>

    <!-- امضا و مهر -->
    <table class="sig-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="sig-box">
                <div class="sig-title">امضا و مهر فروشگاه</div>
                <?php if ($sigPath): ?><img src="<?php echo pdf_esc($sigPath); ?>" style="width:<?php echo $sigW; ?>mm;" alt="امضا"><?php endif; ?>
                <?php if ($stampPath): ?><img src="<?php echo pdf_esc($stampPath); ?>" style="width:<?php echo $stampW; ?>mm;" alt="مهر"><?php endif; ?>
            </td>
            <td class="sig-box">
                <div class="sig-title">مهر و امضاء خریدار</div>
            </td>
        </tr>
    </table>

</div>
</body>
</html>