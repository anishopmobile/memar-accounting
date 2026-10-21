<?php
/** Private, authenticated media streaming. Files never reside in webroot. */
declare(strict_types=1);
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/media_common.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$size = (string)($_GET['size'] ?? 'thumb');
if (!$id || !in_array($size, ['thumb','original'], true)) {
    http_response_code(400); exit;
}
$m = media_get($mysqli, (int)$id);
$path = $m ? media_absolute_path((string)$m[$size === 'thumb' ? 'thumbnail_path' : 'original_path']) : null;
if (!$path) { http_response_code(404); exit; }
header('Content-Type: ' . ($size === 'thumb' ? 'image/jpeg' : $m['mime_type']));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=3600');
header('X-Frame-Options: SAMEORIGIN');
header('Content-Length: ' . (int)filesize($path));
readfile($path);
