<?php
/**
 * defective_goods_action.php — ثبت کالای معیوب (POST + CSRF)
 *
 * تمام اعتبارسنجی و منطق تراکنش/موجودی در dg_register_defective()
 * (defective_goods_common.php) قرار دارد؛ این Endpoint فقط ورودی را به آن می‌دهد.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/defective_goods_common.php';

require_csrf_or_fail();

$productIdRaw = trim((string) ($_POST['product_id'] ?? ''));

$result = dg_register_defective(
    $mysqli,
    ($productIdRaw !== '' && ctype_digit($productIdRaw)) ? (int) $productIdRaw : 0,
    trim((string) ($_POST['quantity'] ?? '')),
    trim((string) ($_POST['defective_date'] ?? '')),
    trim((string) ($_POST['reason'] ?? '')),
    trim((string) ($_POST['description'] ?? ''))
);

if ($result['ok']) {
    respond_json(true, $result['message'], $result['data']);
}

respond_error($result['message'], 422);