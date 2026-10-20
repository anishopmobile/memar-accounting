<?php
/**
 * defective_goods_return_action.php — برگشت کالای معیوب به موجودی (POST + CSRF)
 *
 * تمام اعتبارسنجی و منطق تراکنش/موجودی در dg_return_defective()
 * (defective_goods_common.php) قرار دارد؛ این Endpoint فقط ورودی را به آن می‌دهد.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/defective_goods_common.php';

require_csrf_or_fail();

$idRaw = trim((string) ($_POST['defective_id'] ?? ''));
$id = ($idRaw !== '' && ctype_digit($idRaw)) ? (int) $idRaw : 0;

$result = dg_return_defective($mysqli, $id, trim((string) ($_POST['quantity'] ?? '')));

if ($result['ok']) {
    respond_json(true, $result['message'], $result['data']);
}

respond_error($result['message'], 422);