#!/usr/bin/env bash
# Test-only browser harness on disposable CI MySQL. Never against live data.
set -euo pipefail
if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  echo "Browser smoke tests require isolated CI" >&2
  exit 1
fi
# Payment has separate idempotent schema migrations; initialize only the disposable CI database.
# The Current Affairs module has a separate one-time schema migration.
php assets/php/current_affairs/migration.php
php assets/php/payment/payment_migration.php
php assets/php/payment/payment_public_migration.php
php assets/php/payment/payment_rate_limit_migration.php
php -r '
require "assets/php/db.php";
$name = "ci_tabler_browser";
$hash = password_hash("ci-tabler-test", PASSWORD_DEFAULT);
$s = $mysqli->prepare("INSERT INTO users (username,password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
$s->bind_param("ss", $name, $hash);
$s->execute();
$id = (int) $mysqli->insert_id;
$s->close();
/* Read-only barcode-print fixture in disposable CI database. */
$mysqli->query("INSERT INTO products (code, barcode, name, type, stock)
    VALUES (\"ci_print_barcode_fixture\", \"CI128ABC123\", \"تست برچسب کالا\", \"product\", 7)
    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
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
# Every admin route, native jQuery/AJAX event, dynamic invoice/payment select and
# secure public form keep their existing value when enhanced by HCG.
node assets/js/tests/hcg_select_ui.test.cjs || {
  echo "--- Searchable select PHP diagnostics ---"
  tail -100 "$log"
  exit 1
}
# Verify barcode labels, PDF downloads, secure display preferences, and dense defective layout.
node assets/js/tests/barcode_brand_ui.test.cjs || {
  echo "--- Barcode/brand PHP diagnostics ---"
  tail -100 "$log"
  exit 1
}
# Authentication layout tests use the same disposable MySQL schema.
# Explicitly exercise both feature-flag states without changing production settings.
php -r 'require "assets/php/db.php"; $mysqli->query("UPDATE auth_settings SET login_captcha_enabled=0 WHERE id=1");'
UI_TEST_CAPTCHA=0 node assets/js/tests/login_ui.test.cjs
php -r 'require "assets/php/db.php"; $mysqli->query("UPDATE auth_settings SET login_captcha_enabled=1 WHERE id=1");'
UI_TEST_CAPTCHA=1 node assets/js/tests/login_ui.test.cjs
php -r 'require "assets/php/db.php"; $mysqli->query("UPDATE auth_settings SET login_captcha_enabled=0 WHERE id=1");'
# Verify actual customer/supplier settlements in the running PHP application.
acct_fixture="$(mktemp)"
php assets/php/tests/accounting_ui_fixture.php > "$acct_fixture"
UI_ACCOUNTING_FIXTURE="$acct_fixture" node assets/js/tests/accounting_ui.test.cjs || {
  echo "--- Accounting browser test PHP diagnostics ---"
  tail -100 "$log"
  rm -f "$acct_fixture"
  exit 1
}
# The new feature uses the real settings page, customer picker and invoice flow.
UI_ACCOUNTING_FIXTURE="$acct_fixture" node assets/js/tests/accounting_auto_settlement_ui.test.cjs || {
  echo "--- Auto settlement browser diagnostics ---"
  tail -100 "$log"
  rm -f "$acct_fixture"
  exit 1
}
rm -f "$acct_fixture"
# PHP 8.4 CI runner may disable opcache JIT when a third-party extension
# overrides zend_execute_ex(). This startup diagnostic is not an application error.
# Still fail on every other PHP warning, parse error or fatal error.
if grep -iE 'PHP Fatal error|PHP Warning|PHP Parse error' "$log" \
  | grep -viF 'JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled.' \
  | grep -q '.'; then
  echo "--- Captured PHP errors ---"
  grep -iE 'PHP Fatal error|PHP Warning|PHP Parse error' "$log" \
    | grep -viF 'JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled.' \
    | head -30
  exit 1
fi
