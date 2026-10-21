#!/usr/bin/env bash
# CI-only HTTP audit on a disposable MySQL instance. NEVER run in production.
set -euo pipefail
if [[ "${GITHUB_ACTIONS:-}" != "true" ]]; then
  echo "This test is restricted to an isolated GitHub Actions service." >&2
  exit 1
fi
php -r '
require "assets/php/db.php";
$hash = password_hash("ci-security-only", PASSWORD_DEFAULT);
$stmt = $mysqli->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
foreach (["ci_currency_owner", "ci_currency_staff"] as $name) {
    $stmt->bind_param("ss", $name, $hash);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    session_id("ci-currency-" . ($name === "ci_currency_owner" ? "owner" : "staff") . "-1234567");
    session_start();
    $_SESSION["auth_user_id"] = $id;
    $_SESSION["auth_user"] = $name;
    $_SESSION["csrf_token"] = "ci-security-test-token";
    session_write_close();
}
$stmt->close();
'
PORT=8765
php -S 127.0.0.1:$PORT -t . >/tmp/accounting-currency-security-server.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for attempt in $(seq 1 60); do
  if curl -sS -o /dev/null "http://127.0.0.1:$PORT/login.php" 2>/dev/null; then break; fi
  sleep 0.2
done
url="http://127.0.0.1:$PORT"
owner='PHPSESSID=ci-currency-owner-1234567'
staff='PHPSESSID=ci-currency-staff-1234567'
check() {
  local expected="$1" title="$2"
  shift 2
  local status
  status=$(curl -sS -o /tmp/currency-security-http-body -w '%{http_code}' \
    -H 'X-Requested-With: XMLHttpRequest' "$@")
  echo "$title: HTTP $status (expected $expected)"
  if [[ "$status" != "$expected" ]]; then
    cat /tmp/currency-security-http-body
    exit 1
  fi
}
check 401 "unauthenticated product mutation" --data 'name=blocked' "$url/assets/php/product_action.php"
check 401 "unauthenticated price update" --data 'increase_value=5000' "$url/assets/php/price_increase.php"
check 401 "unauthenticated currency settings" --data 'currency_code=IRR' "$url/assets/php/currency_settings.php"
check 401 "unauthenticated product listing" "$url/assets/php/product_list.php"
check 401 "unauthenticated product Excel export" "$url/assets/php/export_products.php"
check 401 "unauthenticated inventory Excel export" "$url/assets/php/report_inventory_export.php"
check 401 "unauthenticated customer debt Excel export" "$url/assets/php/report_customer_debt_export.php"
check 401 "unauthenticated supplier debt Excel export" "$url/assets/php/report_supplier_debt_export.php"
check 401 "unauthenticated tax settings" "$url/assets/php/tax_settings.php"
check 401 "unauthenticated invoice settings" "$url/assets/php/invoice_settings.php"
check 403 "product write without CSRF" -b "$owner" --data 'name=blocked' "$url/assets/php/product_action.php"
check 403 "price increase without CSRF" -b "$owner" --data 'increase_value=5000' "$url/assets/php/price_increase.php"
check 403 "currency change without CSRF" -b "$owner" --data 'currency_code=IRR' "$url/assets/php/currency_settings.php"
check 403 "tax change without CSRF" -b "$owner" --data 'tax_enabled=1&tax_rate=10' "$url/assets/php/tax_settings.php"
check 403 "invoice settings change without CSRF" -b "$owner" --data 'official_invoice_desc=blocked' "$url/assets/php/invoice_settings.php"
check 403 "non-owner currency change" -b "$staff" --data 'csrf_token=ci-security-test-token&currency_code=IRR' "$url/assets/php/currency_settings.php"
check 422 "owner invalid currency code" -b "$owner" --data 'csrf_token=ci-security-test-token&currency_code=USD' "$url/assets/php/currency_settings.php"
check 200 "owner can switch to IRR" -b "$owner" --data 'csrf_token=ci-security-test-token&currency_code=IRR' "$url/assets/php/currency_settings.php"
grep -q '"success":true' /tmp/currency-security-http-body
check 200 "IRR product price entry" -b "$owner" --data 'csrf_token=ci-security-test-token' \
  --data 'code=CI-SECURITY-PRICE' --data-urlencode 'name=CI Price Audit' \
  --data 'purchase_price=100000' --data 'sale_price=100000' "$url/assets/php/product_action.php"
grep -q '"success":true' /tmp/currency-security-http-body
php -r '
require "assets/php/db.php";
$r=$mysqli->query("SELECT sale_price,purchase_price FROM products WHERE code=\x27CI-SECURITY-PRICE\x27")->fetch_assoc();
if (!$r || $r["sale_price"] !== "10000.00" || $r["purchase_price"] !== "10000.00") {
    fwrite(STDERR, "IRR product price was not stored in TOMAN exactly\n"); exit(1);
}
echo "PASS product IRR entry stored TOMAN\n";
'
check 200 "IRR bulk fixed price increase" -b "$owner" \
  --data 'csrf_token=ci-security-test-token&price_type=sale&increase_type=amount&increase_value=5000&scope=all' \
  "$url/assets/php/price_increase.php"
grep -q '"success":true' /tmp/currency-security-http-body
php -r '
require "assets/php/db.php";
$r=$mysqli->query("SELECT sale_price FROM products WHERE code=\x27CI-SECURITY-PRICE\x27")->fetch_assoc();
if (!$r || $r["sale_price"] !== "10500.00") {
    fwrite(STDERR,"IRR bulk price increase was not converted exactly\n"); exit(1);
}
echo "PASS bulk price IRR input stored TOMAN\n";
'
check 200 "owner restores TOMAN" -b "$owner" --data 'csrf_token=ci-security-test-token&currency_code=TOMAN' "$url/assets/php/currency_settings.php"
echo "PASS all HTTP authorization, CSRF and financial money-boundary smoke checks"
