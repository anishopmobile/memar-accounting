<?php
require_once __DIR__ . '/assets/php/auth.php';

/*
 * خروج فقط با POST + CSRF انجام می‌شود.
 * درخواست GET هیچ تغییری ایجاد نمی‌کند (از خروج اجباری جلوگیری می‌شود).
 */
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'POST' || !csrf_verify()) {
    header('Location: index.php');
    exit;
}

logout_user();
header('Location: login.php');
exit;
