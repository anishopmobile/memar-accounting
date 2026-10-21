#!/usr/bin/env bash
# Test-only browser harness on disposable CI MySQL. Never against live data.
set -euo pipefail
if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  echo "Browser smoke tests require isolated CI" >&2
  exit 1
fi
php -r '
require "assets/php/db.php";
$name = "ci_tabler_browser";
$hash = password_hash("ci-tabler-test", PASSWORD_DEFAULT);
$s = $mysqli->prepare("INSERT INTO users (username,password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
$s->bind_param("ss", $name, $hash);
$s->execute();
$id = (int) $mysqli->insert_id;
$s->close();
session_id("ci-tabler-browser-123456789");
session_start();
$_SESSION["auth_user_id"] = $id;
$_SESSION["auth_user"] = $name;
$_SESSION["csrf_token"] = "ci-tabler-browser-token";
session_write_close();
'
port=8872
log="$(mktemp)"
php -S "127.0.0.1:$port" -t . >"$log" 2>&1 &
pid=$!
trap 'kill "$pid" 2>/dev/null || true; rm -f "$log"' EXIT
for i in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:$port/login.php" -o /dev/null; then break; fi
  sleep .2
done
node assets/js/tests/admin_ui.test.cjs || {
  echo "--- PHP server diagnostics ---"
  tail -80 "$log"
  exit 1
}
if grep -qiE 'PHP Fatal error|PHP Warning|PHP Parse error' "$log"; then
  tail -100 "$log"
  exit 1
fi
