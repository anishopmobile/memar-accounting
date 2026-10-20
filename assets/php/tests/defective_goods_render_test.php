<?php
/* render smoke test — mimics index.php include flow */
if (php_sapi_name() !== 'cli') exit(1);
session_start();
$_SESSION['auth_user'] = 'memar';
require_once __DIR__ . '/../boot.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jdf.php';

/* --- full index.php render (navigation + routing) --- */
$_GET = ['page' => 'defective-goods', 'pageno' => '1'];
ob_start();
include __DIR__ . '/../../../index.php';
$full = (string) ob_get_clean();

$fullChecks = [
    'nav item کالاهای معیوب present' => str_contains($full, '>کالاهای معیوب<'),
    'nav icon present' => str_contains($full, 'defective-goods'),
    'page body renders form' => str_contains($full, 'id="defRegForm"'),
    'page title rendered' => str_contains($full, 'کالاهای معیوب'),
    'no PHP warnings' => !str_contains($full, 'Warning') && !str_contains($full, 'Fatal error') && !str_contains($full, 'Notice'),
];
$ok = true;
foreach ($fullChecks as $label => $passed) {
    echo ($passed ? '  [OK] ' : '  [!!] ') . $label . "\n";
    if (!$passed) $ok = false;
}

/* --- page include render --- */
$_GET = ['page' => 'defective-goods', 'pageno' => '1'];
ob_start();
include __DIR__ . '/../../../pages/defective-goods.php';
$html = (string) ob_get_clean();

$checks = [
    'registration form present' => str_contains($html, 'id="defRegForm"'),
    'report table present' => str_contains($html, 'id="defReportTable"'),
    'product modal present' => str_contains($html, 'id="defProductModal"'),
    'date display present' => str_contains($html, 'id="defDateDisplay"'),
    'csrf token present' => str_contains($html, 'id="defCsrfToken"'),
    'filter form present' => str_contains($html, 'id="defFromDisplay"') && str_contains($html, 'id="defToDisplay"'),
    'export link present' => str_contains($html, 'defective_goods_export.php'),
    'pagination info present' => str_contains($html, 'pagination-info'),
    'no PHP warnings' => !str_contains($html, 'Warning') && !str_contains($html, 'Fatal error') && !str_contains($html, 'Notice'),
];
foreach ($checks as $label => $passed) {
    echo ($passed ? '  [OK] ' : '  [!!] ') . $label . "\n";
    if (!$passed) $ok = false;
}
exit($ok ? 0 : 1);