#!/usr/bin/env bash
# Integration smoke: every registered admin route, local assets, RTL shell.
# CI-only: uses disposable MySQL and a test login, never production.
set -euo pipefail
if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  echo "Refusing to run outside isolated GitHub Actions" >&2
  exit 1
fi
# Payment has separate idempotent schema migrations; initialize only the disposable CI database.
php assets/php/payment/payment_migration.php
php assets/php/payment/payment_public_migration.php
php assets/php/payment/payment_rate_limit_migration.php
php -r '
require "assets/php/db.php";
$name = "ci_tabler_layout_user";
$hash = password_hash("ci-layout-only", PASSWORD_DEFAULT);
$st = $mysqli->prepare("INSERT INTO users (username,password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
$st->bind_param("ss", $name, $hash);
$st->execute();
$id = (int) $mysqli->insert_id;
$st->close();
session_id("ci-tabler-layout-123456789");
session_start();
$_SESSION["auth_user_id"] = $id;
$_SESSION["auth_user"] = $name;
$_SESSION["csrf_token"] = "ci-tabler-layout-csrf";
session_write_close();
'
port=8871
log="$(mktemp)"
php -S "127.0.0.1:$port" -t . >"$log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; rm -f "$log"' EXIT
for n in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:$port/login.php" -o /dev/null; then break; fi
  sleep 0.2
done
base="http://127.0.0.1:$port"
cookie="PHPSESSID=ci-tabler-layout-123456789"
for asset in   assets/vendor/tabler/tabler.rtl.min.css   assets/css/tabler-integration.css assets/js/layout.js   assets/js/jquery.min.js assets/css/style.css   assets/js/kamadatepicker.js assets/js/invoice.js   assets/js/current-affairs.js assets/js/chart.umd.min.js; do
  code=$(curl -sS -o /tmp/tabler-layout-asset -w '%{http_code}' "$base/$asset")
  if [[ "$code" != 200 ]] || [[ ! -s /tmp/tabler-layout-asset ]]; then
    echo "FAIL local asset: $asset HTTP $code"; exit 1
  fi
  echo "OK local asset: $asset"
done
pages=(dashboard factors purchase-invoice sales-return product-categories
  goods-and-services defective-goods customers suppliers report-inventory
  report-customer-debt report-supplier-debt report-sold-items-detail
  settings notes current-affairs about-us payment-forms
  payment-transactions payment-sms-settings payment-zarinpal-settings account)
for page in "${pages[@]}"; do
  body="$(mktemp)"
  code=$(curl -sS -b "$cookie" -o "$body" -w '%{http_code}' "$base/index.php?page=$page")
  if [[ "$code" != 200 ]]       || ! grep -q 'lang="fa" dir="rtl"' "$body"       || ! grep -q 'class="tabler-admin"' "$body"       || ! grep -q 'id="sidebarOpen"' "$body"       || ! grep -q 'id="appSidebar"' "$body"       || ! grep -q 'assets/vendor/tabler/tabler.rtl.min.css' "$body"       || grep -qiE 'Fatal error|Uncaught|Warning:|<script[^>]+src="https?://' "$body"; then
    echo "FAIL admin page: $page HTTP $code"; tail -45 "$body"; rm -f "$body"; exit 1
  fi
  rm -f "$body"
  echo "OK admin page: $page"
done
if grep -qiE 'PHP Fatal error|PHP Warning|PHP Parse error' "$log"; then
  echo 'Server emitted PHP errors'; cat "$log"; exit 1
fi
echo "PASS: 22 authenticated admin pages and all critical local assets"
