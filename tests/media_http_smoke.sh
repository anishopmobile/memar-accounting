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
