<?php
/**
 * DB smoke test: run ONLY against a disposable memar_accounting database.
 * Do not execute against production data. CI starts an empty MariaDB.
 */
declare(strict_types=1);
require_once __DIR__ . '/../assets/php/db.php';
require_once __DIR__ . '/../assets/php/media_common.php';

function assert_true(bool $value, string $label): void {
    if (!$value) throw new RuntimeException('FAIL: ' . $label);
    fwrite(STDOUT, "PASS: $label\n");
}

foreach (['media','product_media','category_media','media_display_settings'] as $table) {
    $safe = $mysqli->real_escape_string($table);
    $q = $mysqli->query("SHOW TABLES LIKE '$safe'");
    assert_true($q && $q->num_rows === 1, "schema $table");
}
$settings = media_settings($mysqli);
assert_true((int)$settings['products_list'] === 1 && (int)$settings['pdf_sales'] === 0,
    'default UI/PDF settings remain independent');
assert_true(media_decode_ids('[1,2]') === [1,2], 'valid media ID decoding');
foreach (['[1,1]','[-1]','["1x"]','{}',str_repeat('[1,',11).'1]'] as $invalid) {
    try { media_decode_ids($invalid); throw new RuntimeException('Accepted invalid media IDs'); }
    catch (InvalidArgumentException $expected) { }
}
fwrite(STDOUT, "PASS: rejects invalid/repeated IDs\n");

$mysqli->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $code1 = 'media-test-a-' . $suffix;
    $code2 = 'media-test-b-' . $suffix;
    $stmt = $mysqli->prepare(
        "INSERT INTO products(code,name,type,unit,purchase_price,sale_price,stock,min_stock)
         VALUES(?,?,'product','عدد',1250,1700,7,1)"
    );
    $name = 'آزمایش تصویر';
    $stmt->bind_param('ss', $code1, $name);
    $stmt->execute(); $p1 = (int)$stmt->insert_id;
    $code1 = $code2;
    $stmt->execute(); $p2 = (int)$stmt->insert_id;
    $stmt->close();

    $idToken = bin2hex(random_bytes(16));
    $original = 'original/' . $idToken . '.jpg';
    $thumb = 'thumb/' . $idToken . '.jpg';
    $sha = hash('sha256',random_bytes(48));
    $filename = 'sample.jpg';
    $mime = 'image/jpeg';
    $size = 100; $w=100; $h=100;
    $stmt = $mysqli->prepare("INSERT INTO media(original_filename,stored_filename,original_path,
        thumbnail_path,mime_type,file_size,width,height,sha256) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssiiis',$filename,$idToken,$original,$thumb,$mime,$size,$w,$h,$sha);
    $stmt->execute();
    $mediaId = (int)$stmt->insert_id; $stmt->close();

    media_sync_product($mysqli,$p1,json_encode([$mediaId]));
    media_sync_product($mysqli,$p2,json_encode([$mediaId]));
    $map = media_primary_map($mysqli,[$p1,$p2]);
    assert_true((int)$map[$p1]['id'] === $mediaId && (int)$map[$p2]['id'] === $mediaId,
        'single media reused by two products');
    assert_true(count(media_product_list($mysqli,$p1)) === 1,'product image retrieval');
    $changed = $mysqli->query("SELECT purchase_price,sale_price,stock FROM products WHERE id=$p1")->fetch_assoc();
    assert_true((float)$changed['purchase_price']===1250.0 &&
        (float)$changed['sale_price']===1700.0 && (float)$changed['stock']===7.0,
        'media associations cannot change financial figures or stock');

    media_sync_product($mysqli,$p1,'[]');
    assert_true(media_primary_map($mysqli,[$p1])===[] &&
        (int)media_primary_map($mysqli,[$p2])[$p2]['id']===$mediaId,
        'removing one relation preserves shared media');
    try {media_sync_product($mysqli,$p2,json_encode([$mediaId,$mediaId]));
         throw new RuntimeException('Unexpected success for duplicate association');
    } catch (InvalidArgumentException $expected) { }
    fwrite(STDOUT,"PASS: duplicate relationship rejected\n");
} finally {
    $mysqli->rollback();
}
fwrite(STDOUT,"PASS: DB media integration smoke checks completed (rolled back)\n");
