# بازبینی دوم ایمنی و سازگاری

تاریخ: ۲۰۲۶-۱۱-۰۸ — ادامه شاخه fix/database-connection-lifecycle

## نتیجه

نسخه اصلاح‌شده در محیط مستقل آزمایش شد؛ هیچ تغییری روی هاست آنلاین اعمال نشد. تضمین مطلق نبود هر باگ یا رفع اشباع سرور مشترک بدون staging و پایش سرور ممکن نیست. تست‌ها شواهد سازگاری‌اند، نه تضمین تمام سناریوها.

## یافته‌ها و اصلاحات این بازبینی

۱. job مرورگر در accounting-ledger-ci هنوز ساختار خودکار را فرض می‌کرد؛ ساخت دیتابیس و مهاجرت صریح به همان job اضافه شد.
۲. fresh_install_test با تغییر متنی db.php کار می‌کرد که با اتصال محیطی جدید ناسازگار بود. اکنون با guard محیط تست، دیتابیس تصادفی مستقل، اجرای مهاجرت واقعی و پاکسازی در finally کار می‌کند. دیگر فایل اتصال patchشده نمی‌سازد.
۳. تست امور جاری نیز روش patch قدیمی داشت؛ به تنظیمات محیط و مهاجرت صریح تبدیل شد و guard اجرای CI گرفت.
۴. تست کالای معیوب افزونه mysqli را دوباره بار می‌کرد و warning به JSON می‌چسبید؛ از PHP_BINARY بدون بارگذاری مجدد افزونه استفاده شد.
۵. نقص واقعی قدیمی: payment-transactions.js تابع renderPagination را صدا می‌زد اما تعریف نداشت. صفحه‌بندی قبلی/بعدی با اطلاعات page و totalPages سرور اضافه شد و با event handler موجود هماهنگ شد. استثنای نادیده‌گرفتن این خطا از تست admin_ui حذف شد.

## تست‌های اجراشده

- HTTP واقعی احراز هویت: ۴۵/۴۵ شامل ورود، خروج، حساب، آواتار و محدودسازی ورود.
- نصب تازه با مسیر مهاجرت جدید: موفق.
- امور جاری: ۱۴۰ assertion، صفر شکست.
- کالای معیوب: ۴۱ موفق، صفر شکست.
- برگشت کالای معیوب: ۵۰ موفق، صفر شکست؛ شامل هم‌زمانی.
- تست MySQL ارز: موفق؛ تست محاسبات ارز: ۲۹ assertion موفق.
- اکسل دسته‌بندی: موفق.
- رسانه: تست ارتباط تصاویر و عدم تغییر موجودی/مالی موفق.
- PDF واقعی: قالب‌های فروش، پیش‌فاکتور، خرید و برگشت، با/بدون تصویر و A4/A4-L موفق؛ تصویر مفقود نیز پوشش داده شد. این تست تولید PDF است، بررسی پیکسلی کامل PDF ادعا نمی‌شود.
- مرورگر Edge headless روی وب‌سرور محلی: ۱۷۶ حالت = ۲۲ صفحه × ۸ اندازه از ۳۶۰ تا ۱۴۴۰ پیکسل. status HTTP، خطاهای JS، overflow، هندسه navbar، منو و باز/بسته‌شدن فرم‌های موجود بررسی شدند. پس از اصلاح پرداخت دوباره بدون نادیده‌گرفتن خطای renderPagination اجرا شد.
- اتصال عادی به تنهایی: Com_create_table=0، Com_alter_table=0، Com_update=0؛ خروج مهاجرت و نوشتن داده از مسیر اتصال عملاً تأیید شد.
- PHP lint و git diff --check موفق.

مرورگر دانلودی Chromium در این محیط اجرا نشد؛ همان harness با Edge سیستم اجرا شد، فایل تست برای وابستگی به Edge تغییر نکرده است. مرورگر CI همچنان از Playwright Chromium استفاده می‌کند. این تفاوت و نسخه محلی MySQL 8.4.3/PHP 8.3.30 باید در ارزیابی نتیجه لحاظ شود.

## تصمیم استقرار

برای انتشار آنلاین، تمام مراحل راهنمای قبلی از جمله نگهداری، حفظ مشخصات اتصال، اجرای CLI migration و بارگذاری هم‌زمان PHP و JS ضروری‌اند. تا تأیید staging روی نسخه و تنظیمات هاست، استقرار بی‌وقفه تأیید نمی‌شود. CSS و قالب صفحات در این بازبینی تغییر نکردند؛ تنها UI تغییرکرده صفحه‌بندی پرداخت است.

داده واقعی بکاپ وارد تست‌ها نشد؛ تست‌ها روی ساختار استخراج‌شده و داده مصنوعی اجرا شدند. خطای Too many connections ممکن است سهمی از اشباع کل سرور مشترک داشته باشد؛ با این تست‌ها حذف آن عامل خارجی قابل اثبات نیست. پشتیبان‌گیری حجیم و باقی‌مانده مدیریت خطا طبق محدودیت‌های گزارش اول هنوز نیازمند کار مستقل‌اند.

## تغییرات دقیق خط به خط این بازبینی

```diff
diff --git a/.github/workflows/accounting-ledger-ci.yml b/.github/workflows/accounting-ledger-ci.yml
index 037d828..669f95e 100644
--- a/.github/workflows/accounting-ledger-ci.yml
+++ b/.github/workflows/accounting-ledger-ci.yml
@@ -104,5 +104,9 @@ jobs:
         run: |
           sudo apt-get update -qq
           sudo apt-get install -y -qq poppler-utils
+      - name: Provision explicit core schema for browser tests
+        run: |
+          php -r '$m=new mysqli("127.0.0.1","root",""); $m->query("CREATE DATABASE IF NOT EXISTS memar_accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'
+          php tools/migrate-database.php
       - name: Existing UI and RTL browser regressions
         run: bash assets/php/tests/tabler_browser_smoke.sh
diff --git a/assets/js/payment-transactions.js b/assets/js/payment-transactions.js
index 2bb88f1..e45d50a 100644
--- a/assets/js/payment-transactions.js
+++ b/assets/js/payment-transactions.js
@@ -66,6 +66,20 @@ $(function () {
             });
     }
 
+    function renderPagination(data) {
+        var page = Math.max(1, parseInt(data.page, 10) || 1);
+        var pages = Math.max(1, parseInt(data.totalPages, 10) || 1);
+        state.page = page;
+        var html = '';
+        function button(target, label, disabled) {
+            return '<button type="button" class="dashboard-page-button" data-page="' + target + '"' + (disabled ? ' disabled' : '') + '>' + label + '</button>';
+        }
+        html += button(page - 1, 'قبلی', page <= 1);
+        html += '<span> ' + page + ' / ' + pages + ' </span>';
+        html += button(page + 1, 'بعدی', page >= pages);
+        $('#txPagination').html(html);
+    }
+
     function statusBadge(status) {
         var map = {
             paid: '<span class="badge">پرداخت شده</span>',
diff --git a/assets/js/tests/admin_ui.test.cjs b/assets/js/tests/admin_ui.test.cjs
index 9de5bcc..5e38ed4 100644
--- a/assets/js/tests/admin_ui.test.cjs
+++ b/assets/js/tests/admin_ui.test.cjs
@@ -194,10 +194,7 @@ const cookie = process.env.UI_TEST_SESSION || 'ci-tabler-browser-123456789';
           assert.equal(await page.locator('#invoiceServiceModal.open').count(), 0,
             'legacy service modal still closes');
         }
-// Existing Payment transaction client references an absent renderPagination helper.
-        // This is a pre-existing Payment module defect, deliberately excluded from this UI-only migration.
-        const newErrors = errors.filter(e => !e.includes('renderPagination is not defined'));
-        assert.equal(newErrors.length, 0, id + ' JS errors at ' + width + ': ' + newErrors.join(' | '));
+        assert.equal(errors.length, 0, id + ' JS errors at ' + width + ': ' + errors.join(' | '));
         errors.length = 0;
         checked++;
         console.log('OK ' + id + ' at ' + width + 'px');
diff --git a/assets/php/tests/current_affairs_test.php b/assets/php/tests/current_affairs_test.php
index 85769f9..d289205 100644
--- a/assets/php/tests/current_affairs_test.php
+++ b/assets/php/tests/current_affairs_test.php
@@ -40,23 +40,15 @@ function t_count(mysqli $m, string $sql): int
 }
 
 /* ---------- throwaway database setup ---------- */
-$root = new mysqli('127.0.0.1', 'root', '');
-if ($root->connect_errno) {
-    echo "NO DB\n";
-    exit(1);
-}
+if (getenv('GITHUB_ACTIONS') !== 'true') { exit(2); }
+$root = new mysqli(getenv('MEMAR_DB_HOST') ?: '127.0.0.1', getenv('MEMAR_DB_USER') ?: 'root', getenv('MEMAR_DB_PASS') ?: '', '', (int)(getenv('MEMAR_DB_PORT') ?: 3306));
 $root->query('DROP DATABASE IF EXISTS memar_accounting_ca_test');
 $root->query('CREATE DATABASE memar_accounting_ca_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 $root->close();
-
-$orig = file_get_contents(__DIR__ . '/../db.php');
-$patched = str_replace(
-    '$dbName = \'memar_accounting\';',
-    '$dbName = \'memar_accounting_ca_test\';',
-    (string) $orig
-);
-file_put_contents(__DIR__ . '/../db_ca_patch.php', $patched);
-require __DIR__ . '/../db_ca_patch.php'; // defines global $mysqli
+putenv('MEMAR_DB_NAME=memar_accounting_ca_test');
+require __DIR__ . '/../db.php';
+define('MEMAR_RUN_SCHEMA', true);
+require __DIR__ . '/../db_schema.php';
 require __DIR__ . '/../current_affairs/ca_loader.php';
 
 $migErrs = current_affairs_migrate($mysqli);
@@ -666,7 +658,7 @@ t_check('current affairs IRR input converted to TOMAN exactly',
 /* ============================================================
  * 9) Teardown + summary
  * ========================================================== */
-@unlink(__DIR__ . '/../db_ca_patch.php');
+@// No patched source file is created.
 $mysqli->query('DROP DATABASE IF EXISTS memar_accounting_ca_test');
 $mysqli->close();
 
diff --git a/assets/php/tests/defective_goods_test.php b/assets/php/tests/defective_goods_test.php
index e82dbd8..e824123 100644
--- a/assets/php/tests/defective_goods_test.php
+++ b/assets/php/tests/defective_goods_test.php
@@ -237,7 +237,7 @@ function t_dg_run_export(string $runner, string $tmpOut, array $filters): string
     @unlink($runner);
     file_put_contents($runner, $code);
     @unlink($tmpOut);
-    shell_exec('php -d extension=mysqli ' . escapeshellarg($runner) . ' > ' . escapeshellarg($tmpOut) . ' 2>&1');
+    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' > ' . escapeshellarg($tmpOut) . ' 2>&1');
     return (string) file_get_contents($tmpOut);
 }
 
@@ -276,7 +276,7 @@ function t_dg_run_action(string $runner, string $outFile, array $post, bool $csr
     @unlink($runner);
     file_put_contents($runner, $code);
     @unlink($outFile);
-    shell_exec('php -d extension=mysqli ' . escapeshellarg($runner) . ' > ' . escapeshellarg($outFile) . ' 2>&1');
+    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' > ' . escapeshellarg($outFile) . ' 2>&1');
     $raw = (string) file_get_contents($outFile);
     $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
     $json = json_decode($raw, true);
diff --git a/assets/php/tests/fresh_install_test.php b/assets/php/tests/fresh_install_test.php
index b88f0e9..20a260e 100644
--- a/assets/php/tests/fresh_install_test.php
+++ b/assets/php/tests/fresh_install_test.php
@@ -1,40 +1,26 @@
 <?php
-/**
- * Fresh-install smoke test: run db.php against a freshly created database.
- * Requires MySQL root with no password (matches db.php defaults).
- */
-$db = mysqli_connect('127.0.0.1', 'root', '');
-if (!$db) { echo "NO DB\n"; exit(1); }
-$db->query('DROP DATABASE IF EXISTS memar_accounting_fresh_test');
-$db->query('CREATE DATABASE memar_accounting_fresh_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
-$db->close();
-
-// Temporarily switch db.php config to the fresh DB
-$orig = file_get_contents(__DIR__ . '/../db.php');
-$patched = str_replace("$dbName = 'memar_accounting';", "$dbName = 'memar_accounting_fresh_test';", $orig);
-file_put_contents(__DIR__ . '/../db_fresh_patch.php', $patched);
-
-require __DIR__ . '/../db_fresh_patch.php';
-
-// Verify
-$tables = [];
-$r = $mysqli->query('SHOW TABLES');
-if ($r) { while ($row = $r->fetch_row()) { $tables[] = $row[0]; } }
-$checkProductUnits = in_array('product_units', $tables, true);
-$colOk = false;
-$r = $mysqli->query("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema='memar_accounting_fresh_test' AND table_name='invoice_items' AND column_name='unit_id'");
-if ($r && $row = $r->fetch_assoc()) { $colOk = (int)$row['c'] === 1; }
-$baseUnitCount = 0;
-$r = $mysqli->query("SELECT COUNT(*) AS c FROM product_units WHERE is_base = 1");
-if ($r && $row = $r->fetch_assoc()) { $baseUnitCount = (int)$row['c']; }
-
-echo "product_units table: " . ($checkProductUnits ? 'OK' : 'MISSING') . "\n";
-echo "invoice_items.unit_id column: " . ($colOk ? 'OK' : 'MISSING') . "\n";
-echo "fresh products: " . (int)$mysqli->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc()['c'] . "\n";
-echo "base units: $baseUnitCount\n";
-
-$ok = $checkProductUnits && $colOk && $baseUnitCount >= 0;
-$mysqli->query('DROP DATABASE memar_accounting_fresh_test');
-unlink(__DIR__ . '/../db_fresh_patch.php');
-echo ($ok ? "FRESH INSTALL OK" : "FRESH INSTALL PROBLEM") . "\n";
-exit($ok ? 0 : 1);
\ No newline at end of file
+// Only against an explicitly selected disposable test server.
+if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true') { exit(2); }
+$host = getenv('MEMAR_DB_HOST') ?: '127.0.0.1';
+$user = getenv('MEMAR_DB_USER') ?: 'root';
+$pass = getenv('MEMAR_DB_PASS') ?: '';
+$port = (int)(getenv('MEMAR_DB_PORT') ?: 3306);
+$name = 'memar_fresh_' . bin2hex(random_bytes(6));
+$db = new mysqli($host, $user, $pass, '', $port);
+$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
+putenv('MEMAR_DB_NAME=' . $name);
+try {
+    require dirname(__DIR__, 3) . '/tools/migrate-database.php';
+    $check = new mysqli($host, $user, $pass, $name, $port);
+    foreach (['product_units','invoices','sales_returns','account_payments'] as $table) {
+        $result = $check->query("SHOW TABLES LIKE '$table'");
+        if ($result->num_rows !== 1) { throw new RuntimeException('Missing ' . $table); }
+    }
+    $result = $check->query("SHOW COLUMNS FROM sales_returns LIKE 'client_token'");
+    if ($result->num_rows !== 1) { throw new RuntimeException('Retry schema missing'); }
+    $check->close();
+    echo "FRESH INSTALL OK\n";
+} finally {
+    $db->query("DROP DATABASE `$name`");
+    $db->close();
+}

```
