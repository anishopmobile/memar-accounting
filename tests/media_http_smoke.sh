#!/usr/bin/env bash
# Integration test on an ephemeral MariaDB + built-in PHP web server ONLY.
set -euo pipefail
base="http://127.0.0.1:8099"
tmp="$(mktemp -d)"
trap 'kill "${server_pid:-0}" 2>/dev/null || true; rm -rf "$tmp"' EXIT
export MEMAR_MEDIA_STORAGE="$tmp/private-media"
php -r '
require "assets/php/db.php";
$name="media_ci_user";
$hash=password_hash("MediaSmoke1234", PASSWORD_DEFAULT);
$stmt=$mysqli->prepare("INSERT INTO users(username,first_name,last_name,password_hash) VALUES (?,?,?,?)");
$fn="Media";$ln="CI";$stmt->bind_param("ssss",$name,$fn,$ln,$hash);
$stmt->execute();
'
php -r '$i=imagecreatetruecolor(80,80); $c=imagecolorallocate($i,40,110,210); imagefill($i,0,0,$c); imagepng($i,$argv[1]); imagedestroy($i);' "$tmp/sample.png"
printf '%s' 'This is NOT an image' > "$tmp/fake.jpg"
php -S 127.0.0.1:8099 -t . >"$tmp/http.log" 2>&1 &
server_pid=$!
for _ in {1..30}; do
  if curl -sf -o /dev/null "$base/login.php" 2>/dev/null; then break; fi
  sleep 0.2
done
curl -sSf -c "$tmp/cookies" "$base/login.php" > "$tmp/login.html"
token="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$tmp/login.html" | head -n 1)"
if [[ ! "$token" =~ ^[0-9a-f]{64}$ ]]; then
  echo 'Login form CSRF token is missing' >&2; exit 1
fi
curl -sS -b "$tmp/cookies" -c "$tmp/cookies" -D "$tmp/login.headers" -o /dev/null \
  --data-urlencode 'username=media_ci_user' \
  --data-urlencode 'password=MediaSmoke1234' \
  --data-urlencode "csrf_token=$token" "$base/login.php"
grep -iq 'location: index.php' "$tmp/login.headers"
echo 'PASS authenticated login'
code="$(curl -sS -b "$tmp/cookies" -o "$tmp/no-csrf.json" -w '%{http_code}' \
  -F 'action=upload' -F "file=@$tmp/sample.png" "$base/assets/php/media_api.php")"
[[ "$code" == '403' ]] || { echo "Expected CSRF 403, got $code" >&2; exit 1; }
echo 'PASS CSRF enforcement'
code="$(curl -sS -b "$tmp/cookies" -o "$tmp/not-image.json" -w '%{http_code}' \
  -F action=upload -F "csrf_token=$token" -F "file=@$tmp/fake.jpg" "$base/assets/php/media_api.php")"
[[ "$code" == '422' ]] || { echo "Expected bad file 422, got $code" >&2; exit 1; }
echo 'PASS real MIME validation'
curl -sS -b "$tmp/cookies" -o "$tmp/upload.json" \
  -F action=upload -F "csrf_token=$token" -F "file=@$tmp/sample.png" "$base/assets/php/media_api.php"
id="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"]) || empty($j["data"]["id"])) { fwrite(STDERR, json_encode($j).PHP_EOL);exit(1); }echo $j["data"]["id"];' "$tmp/upload.json")"
curl -sS -b "$tmp/cookies" -o "$tmp/reuse.json" \
  -F action=upload -F "csrf_token=$token" -F "file=@$tmp/sample.png" "$base/assets/php/media_api.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(empty($j["success"]) || empty($j["data"]["reused"]) || (int)$j["data"]["id"]!==(int)$argv[2])exit(1);' "$tmp/reuse.json" "$id"
echo 'PASS upload and server-side SHA-256 deduplication'
curl -sSf -b "$tmp/cookies" "$base/assets/php/media_file.php?id=$id&size=thumb" -o "$tmp/served.jpg"
php -r '$i=getimagesize($argv[1]);if(!$i || ($i["mime"] ?? "")!=="image/jpeg")exit(1);' "$tmp/served.jpg"
echo 'PASS authenticated thumbnail stream'
curl -sSf -b "$tmp/cookies" "$base/assets/php/media_api.php?action=list&search=sample&page=1" > "$tmp/list.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(empty($j["success"]) || empty($j["data"]["items"]))exit(1);' "$tmp/list.json"
echo 'PASS paginated library search'
# A product referencing this image must prohibit removal from the central library.
php -r '
require "assets/php/db.php"; require "assets/php/media_common.php";
$code="media-http-ci-".bin2hex(random_bytes(4));
$stmt=$mysqli->prepare("INSERT INTO products(code,name) VALUES (?,?)");$name="Media HTTP CI";
$stmt->bind_param("ss",$code,$name);$stmt->execute();$productId=$stmt->insert_id;
$mysqli->begin_transaction();media_sync_product($mysqli,(int)$productId,json_encode([(int)$argv[1]]));$mysqli->commit();
' "$id"
code="$(curl -sS -b "$tmp/cookies" -o "$tmp/deny-delete.json" -w '%{http_code}' \
  --data-urlencode action=delete --data-urlencode "id=$id" \
  --data-urlencode "csrf_token=$token" "$base/assets/php/media_api.php")"
[[ "$code" == '422' ]] || { echo "Expected linked-media deletion to return 422, got $code" >&2; exit 1; }
echo 'PASS shared-media deletion protection'

# Create a real full SQL + private-media backup, then restore into this
# disposable CI database and verify the removed original was recovered.
curl -sS -b "$tmp/cookies" -o "$tmp/set-backup.json" \
  --data-urlencode action=save_dir --data-urlencode "backup_dir=$tmp" \
  --data-urlencode "csrf_token=$token" "$base/assets/php/db_backup.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])) {fwrite(STDERR,json_encode($j));exit(1);}' "$tmp/set-backup.json"
curl -sS -b "$tmp/cookies" -o "$tmp/full-backup.json" \
  --data-urlencode action=backup_full --data-urlencode "csrf_token=$token" "$base/assets/php/db_backup.php"
archive="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||empty($j["data"]["path"])){fwrite(STDERR,json_encode($j));exit(1);}echo $j["data"]["path"];' "$tmp/full-backup.json")"
test -s "$archive"
php -r '
$z=new ZipArchive();if($z->open($argv[1])!==true)exit(1);
if($z->locateName("database.sql")===false || $z->locateName("manifest.json")===false)exit(2);
$manifest=json_decode($z->getFromName("manifest.json"),true);
if(empty($manifest["files"])||count($manifest["files"])!==2)exit(3);
$z->close();
' "$archive"
echo 'PASS checksum-manifest SQL and media full ZIP backup'
test "$(find "$tmp/private-media/original" -type f | wc -l)" -eq 1
find "$tmp/private-media/original" -type f -delete
test "$(find "$tmp/private-media/original" -type f | wc -l)" -eq 0
curl -sS -b "$tmp/cookies" -o "$tmp/full-restore.json" \
  -F action=restore_full -F "csrf_token=$token" -F "backup_file=@$archive" "$base/assets/php/db_backup.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])){fwrite(STDERR,json_encode($j,JSON_UNESCAPED_UNICODE).PHP_EOL);exit(1);}' "$tmp/full-restore.json"
test "$(find "$tmp/private-media/original" -type f | wc -l)" -eq 1
curl -sSf -b "$tmp/cookies" "$base/assets/php/media_file.php?id=$id&size=original" -o "$tmp/restored.png"
php -r '$x=getimagesize($argv[1]);if(!$x||$x["mime"]!=="image/png")exit(1);' "$tmp/restored.png"
echo 'PASS full ZIP restoration of missing original and SQL records'
# Regression: a NEW product, not just an edited product, must retain its picture.
curl -sS -b "$tmp/cookies" -o "$tmp/new-product.json" \
  --data-urlencode "csrf_token=$token" \
  --data-urlencode "name=CI_MEDIA_IMAGE_NEW" \
  --data-urlencode "type=product" --data-urlencode "unit=عدد" \
  --data-urlencode "purchase_price=1250" --data-urlencode "sale_price=1700" \
  --data-urlencode "stock=7" --data-urlencode "min_stock=1" \
  --data-urlencode "media_ids=[$id]" "$base/assets/php/product_action.php"
new_id="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||empty($j["product"]["id"])) {fwrite(STDERR,json_encode($j));exit(1);}echo $j["product"]["id"];' "$tmp/new-product.json")"
curl -sSf -b "$tmp/cookies" "$base/assets/php/product_list.php?search=CI_MEDIA_IMAGE_NEW" > "$tmp/new-search.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||strpos($j["tableBody"] ?? "", "media_file.php?id=".$argv[2]."&amp;size=thumb")===false && strpos($j["tableBody"] ?? "", "media_file.php?id=".$argv[2]."&size=thumb")===false)exit(1);' "$tmp/new-search.json" "$id"
curl -sSf -b "$tmp/cookies" "$base/assets/php/invoice_products.php?media_section=purchase&search=CI_MEDIA_IMAGE_NEW" > "$tmp/new-invoice-search.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||count($j["data"])!==1||(int)$j["data"][0]["media_id"]!==(int)$argv[2])exit(1);' "$tmp/new-invoice-search.json" "$id"
echo 'PASS new product image survives save and appears in list and purchase picker'

# Folder navigation must work without copying any image.
curl -sS -b "$tmp/cookies" -o "$tmp/new-folder.json" \
  --data-urlencode "action=folder_create" --data-urlencode "csrf_token=$token" \
  --data-urlencode "name=CI mobile accessories" "$base/assets/php/media_api.php"
folder_id="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||empty($j["data"]["id"]))exit(1);echo $j["data"]["id"];' "$tmp/new-folder.json")"
curl -sS -b "$tmp/cookies" -o "$tmp/moved.json" \
  --data-urlencode "action=media_move" --data-urlencode "csrf_token=$token" \
  --data-urlencode "id=$id" --data-urlencode "folder_id=$folder_id" "$base/assets/php/media_api.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"]))exit(1);' "$tmp/moved.json"
curl -sSf -b "$tmp/cookies" "$base/assets/php/media_api.php?action=list&folder=$folder_id" > "$tmp/folder-list.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||count($j["data"]["items"])!==1||(int)$j["data"]["items"][0]["folder_id"]!==(int)$argv[2])exit(1);' "$tmp/folder-list.json" "$folder_id"
echo 'PASS create and browse folder / move shared image without copying'

# Images of damaged items belong to their own document, not the current product gallery.
curl -sS -b "$tmp/cookies" -o "$tmp/defective.json" \
  --data-urlencode "csrf_token=$token" --data-urlencode "product_id=$new_id" \
  --data-urlencode "quantity=1" --data-urlencode "defective_date=2026-10-22" \
  --data-urlencode "reason=Test damage" --data-urlencode "media_ids=[$id]" \
  "$base/assets/php/defective_goods_action.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||empty($j["data"]["defective_id"])) {fwrite(STDERR,json_encode($j));exit(1);}' "$tmp/defective.json"
php -r '
require "assets/php/db.php"; require "assets/php/media_common.php";
$pid=(int)$argv[1];$mid=(int)$argv[2];
$stmt=$mysqli->prepare("SELECT dg.id,p.stock FROM defective_goods dg JOIN products p ON p.id=dg.product_id WHERE dg.product_id=? ORDER BY dg.id DESC LIMIT 1");
$stmt->bind_param("i",$pid);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
if(!$row || (float)$row["stock"]!==6.0 || (int)media_defective_list($mysqli,(int)$row["id"])[0]["id"]!==$mid)exit(1);
' "$new_id" "$id"
echo 'PASS defective-photo snapshot and unchanged stock accounting path'

# 10 is a PER-PRODUCT attachment limit; media storage itself is unbounded.
for i in $(seq 1 11); do
  php -r '$n=(int)$argv[2];$im=imagecreatetruecolor(70,70);$bg=imagecolorallocate($im,$n*17,25,99);imagefill($im,0,0,$bg);imagestring($im,1,4,4,(string)$n,imagecolorallocate($im,255,255,255));imagepng($im,$argv[1]);imagedestroy($im);' "$tmp/pic-$i.png" "$i"
  curl -sS -b "$tmp/cookies" -o "$tmp/more-$i.json" -F action=upload \
    -F "csrf_token=$token" -F "folder_id=$folder_id" -F "file=@$tmp/pic-$i.png" \
    "$base/assets/php/media_api.php"
  php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||empty($j["data"]["id"]))exit(1);' "$tmp/more-$i.json"
done
curl -sSf -b "$tmp/cookies" "$base/assets/php/media_api.php?action=list&folder=$folder_id" > "$tmp/large-folder.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||(int)$j["data"]["total"]!==12)exit(1);' "$tmp/large-folder.json"
echo 'PASS 12-image folder demonstrates no global ten-file upload ceiling'


# Gallery contract: the same product can return multiple ordered photo IDs,
# while selecting its primary thumbnail never changes financial columns.
second_id="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo (int)($j["data"]["id"]??0);' "$tmp/more-1.json")"
php -r '
require "assets/php/db.php"; require "assets/php/media_common.php";
$pid=(int)$argv[1]; $a=(int)$argv[2]; $b=(int)$argv[3];
$mysqli->begin_transaction(); media_sync_product($mysqli,$pid,json_encode([$a,$b])); $mysqli->commit();
' "$new_id" "$id" "$second_id"
curl -sSf -b "$tmp/cookies" "$base/assets/php/media_api.php?action=product&product_id=$new_id" > "$tmp/gallery.json"
php -r '$j=json_decode(file_get_contents($argv[1]),true);$x=$j["data"]["items"]??[];if(!($j["success"]??false)||count($x)!==2||(int)$x[0]["id"]!==(int)$argv[2]||(int)$x[1]["id"]!==(int)$argv[3])exit(1);' "$tmp/gallery.json" "$id" "$second_id"
echo 'PASS ordered multi-image product gallery response'

# Protect legacy logo / signature / stamp route from CSRF and forged MIME types.
code="$(curl -sS -b "$tmp/cookies" -o "$tmp/no-csrf-settings.json" -w '%{http_code}' \
  -F 'store_name=Test Store' -F "logo_image=@$tmp/sample.png" "$base/assets/php/store_settings.php")"
[[ "$code" == '403' ]] || { echo "Expected store settings CSRF 403, got $code" >&2; exit 1; }
curl -sS -b "$tmp/cookies" -o "$tmp/malicious-logo.json" \
  -F "csrf_token=$token" -F 'store_name=Test Store' -F "logo_image=@$tmp/fake.jpg;type=image/jpeg" \
  "$base/assets/php/store_settings.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(($j["success"]??true)!==false)exit(1);' "$tmp/malicious-logo.json"
curl -sS -b "$tmp/cookies" -o "$tmp/settings-image.json" \
  -F "csrf_token=$token" -F 'store_name=Test Store' \
  -F "logo_image=@$tmp/sample.png" -F "stamp_image=@$tmp/sample.png" \
  -F "signature_image=@$tmp/sample.png" "$base/assets/php/store_settings.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])) {fwrite(STDERR,json_encode($j).PHP_EOL);exit(1);}foreach(["logo_path","stamp_path","signature_path"] as $k){$p=$j["data"][$k]??"";if(!preg_match("#^assets/uploads/(logo_image|stamp_image|signature_image)_[a-f0-9]{32}\\.(png|jpg|webp)$#",$p)||!getimagesize($p))exit(2);}' "$tmp/settings-image.json"
curl -sS -b "$tmp/cookies" -o "$tmp/brand-image.json" \
  -F "csrf_token=$token" -F 'display_mode=logo' \
  -F "brand_logo=@$tmp/sample.png" "$base/assets/php/app_brand_settings.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||!preg_match("#^assets/uploads/brand_[a-f0-9]{32}\\.(png|jpg|webp)$#",$j["data"]["logo_path"]??""))exit(1);' "$tmp/brand-image.json"
echo 'PASS authenticated/CSRF-protected store and app-brand image uploads with real file validation'

# Repeated backup restoration on an unchanged installation should avoid
# redundant re-extraction and destructive SQL replay.
curl -sS -b "$tmp/cookies" -o "$tmp/second-full-backup.json" \
  --data-urlencode action=backup_full --data-urlencode "csrf_token=$token" "$base/assets/php/db_backup.php"
second_archive="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"]))exit(1);echo $j["data"]["path"];' "$tmp/second-full-backup.json")"
curl -sS -b "$tmp/cookies" -o "$tmp/second-full-restore.json" \
  -F action=restore_full -F "csrf_token=$token" -F "backup_file=@$second_archive" "$base/assets/php/db_backup.php"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(empty($j["success"])||(int)($j["data"]["new_media_files"]??-1)!==0) {fwrite(STDERR,json_encode($j).PHP_EOL);exit(1);}' "$tmp/second-full-restore.json"
echo 'PASS repeat restoration without re-extracting identical media'
