<?php
/**
 * header.php — Public payment layout header.
 * Expects $pageTitle (string). RTL, responsive, modern; loads public CSS.
 */
declare(strict_types=1);
$pt = (string) ($pageTitle ?? 'پرداخت');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo htmlspecialchars($pt, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/css/payment-public.css">
    <link rel="stylesheet" href="assets/vendor/hcg-searchable-select/hcg-select.css">
    <link rel="stylesheet" href="assets/css/hcg-select-integration.css">
    <script src="assets/vendor/hcg-searchable-select/hcg-select.js" defer></script>
    <script src="assets/js/hcg-select-integration.js" defer></script>
</head>
<body>
<div class="pay-page">
    <div class="pay-brand">
        <span class="pay-brand-badge"><i>پ</i></span>
        <span class="pay-brand-text">درگاه پرداخت</span>
    </div>