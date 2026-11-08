# گزارش اصلاح اتصال دیتابیس معمار

تاریخ: ۲۰۲۶-۱۱-۰۸
شاخه: `fix/database-connection-lifecycle`
مبنای بررسی: `9cd74b820c80d397d4965adf6fd11f6159bd6d98`

## نتیجه و حدود اعتبار

مهاجرت‌های سنگین از درخواست‌های وب خارج شدند. اتصال پیش از انتظار نشست ساخته نمی‌شود و endpointهای احرازشده نشست را بعد از تثبیت CSRF آزاد می‌کنند. اتصال تصویر قبل از streaming بسته می‌شود. این اصلاحات عوامل قابل مشاهده در کد را کم می‌کنند؛ رفع قطعی اشباع سرور مشترک بدون مشاهده زمان رخداد قابل تضمین نیست.

بکاپ ارسالی ۵۳ جدول داشت. فقط CREATE TABLEها استخراج شدند؛ هیچ INSERT یا ردیف مالی بکاپ وارد گیت نشد. این استخراج برای تست ساختار است و جایگزین بکاپ کامل نیست. ساختار بکاپ در MySQL مستقل روی 127.0.0.1:33317 وارد شد و مهاجرت دو مرتبه موفق بود. داده واقعی وارد محیط تست نشد؛ بنابراین سازگاری backfill با تمام داده‌های واقعی هنوز باید در staging بررسی شود.

## تغییرات فایل به فایل

- `assets/php/db.php`: اتصال مستقیم به دیتابیس موجود؛ حذف CREATE DATABASE و اجرای خودکار مهاجرت؛ پشتیبانی MEMAR_DB_HOST/USER/PASS/NAME/PORT؛ timeout اتصال ۵ ثانیه؛ منع host با p:؛ mysqli strict؛ خطای وب عمومی 503 و ثبت جزئیات در لاگ خصوصی. هیچ retry خودکار برای عملیات مالی اضافه نشده است.
- `assets/php/db_schema.php`: انتقال همان کد ساختار قدیمی، فقط برای اجرای صریح CLI؛ افزودن client_token و request_hash nullable به sales_returns و قید یکتا برای توکن. اسناد تاریخی تغییر هویت نمی‌دهند.
- `assets/php/db_schema_helpers.php`: توابع بررسی ستون و ایندکس جدا شدند تا وابستگی‌های قبلی حفظ شوند.
- `tools/migrate-database.php`: اجرای صریح مهاجرت، قفل نام‌گذاری‌شده مختص دیتابیس، آزادسازی قفل در finally. دیتابیس باید از قبل در cPanel ساخته شده باشد. ابزار مهاجرت تمام ماژول‌های مستقل payment/current-affairs را جایگزین نمی‌کند؛ این ماژول‌ها در بکاپ موجودند.
- `assets/php/auth.php`: انتقال اتصال به بعد از session_start؛ توابع احراز هویت همچنان در همان درخواست در دسترس‌اند.
- `assets/php/boot.php`: پس از require_login، ایجاد/تثبیت CSRF و session_write_close. endpointهای این bootstrap باید نشست را فقط بخوانند. مسیر login/register/captcha که به نشست می‌نویسد مستقیماً از auth استفاده می‌کند.
- `assets/php/media_file.php`: بستن اتصال بعد از دریافت مشخصات تصویر، قبل از ارسال فایل.
- `assets/php/invoice_common.php`: دریافت گروهی واحدهای اقلام به جای یک SELECT برای هر ردیف؛ حفظ بررسی تعلق واحد به کالا؛ حذف متن خام خطای SQL از پیام عمومی ایجاد فاکتور.
- `assets/php/invoice_save.php`: الزام شناسه درخواست ۱۶ تا ۶۴ کاراکتری برای ایجاد از endpoint؛ توکن فعلی مرورگر سازگار است. فراخوانی‌های داخلی تابع برای تست‌های قدیمی تغییر قرارداد نداده‌اند.
- `assets/php/sales_return_save.php`: الزام توکن hex برای ایجاد سند برگشت.
- `assets/php/sales_return_common.php`: داخل همان تراکنش و بعد از قفل فاکتور، بررسی توکن و hash درخواست؛ درخواست یکسان سند قبلی را می‌گیرد؛ محتوای متفاوت رد می‌شود؛ درج توکن همراه با سند و موجودی در یک تراکنش است. خطاهای SQL به لاگ خصوصی می‌روند.
- `assets/js/sales-return.js`: تولید توکن با crypto.getRandomValues برای فرم جدید؛ حفظ توکن در تلاش دوباره پس از خطای شبکه. صفحه باید در HTTPS یا localhost اجرا شود.
- تست برگشت فروش: آزمون سند یکسان، موجودی بدون تغییر و تضاد محتوای توکن؛ اتصال هم‌زمان از تنظیمات محیط استفاده می‌کند.
- تست دفتر حسابداری: اتصال دوم از همان تنظیمات محیط و port استفاده می‌کند.
- سه workflow موجود: ساخت دیتابیس و اجرای مهاجرت صریح پیش از تست‌های دیتابیس اضافه شد؛ نتیجه اجرای GitHub Actions در این گزارش ادعا نشده است.

## ترتیب اعمال روی هاست

۱. ابتدا روی staging از بکاپ کامل کپی تهیه و بازیابی کنید. به فایل SQL ساختار موجود در این شاخه به عنوان بکاپ داده تکیه نکنید.
۲. زمان نگهداری تعیین کنید؛ درخواست‌های مالی و cron را موقتاً متوقف کنید. از فایل‌ها و دیتابیس بکاپ مستقل بگیرید و امکان بازگردانی را بررسی کنید.
۳. اطلاعات واقعی اتصال را حفظ کنید: این مخزن مقدار پیش‌فرض root/بدون رمز برای توسعه دارد. در هاست MEMAR_DB_HOST، MEMAR_DB_USER، MEMAR_DB_PASS، MEMAR_DB_NAME و در صورت نیاز MEMAR_DB_PORT را در محیط PHP تنظیم کنید؛ یا چهار مقدار پیش‌فرض db.php را با مقادیر واقعی فعلی هاست جایگزین کنید. هیچ رمز را در گیت ثبت نکنید. CLI و وب باید به همان دیتابیس اشاره کنند.
۴. فایل‌های تغییرکرده و فایل‌های جدید PHP را در همان مسیرها آپلود کنید. در بازه آپلود سایت در حالت نگهداری بماند؛ ترکیب نسخه قدیم و جدید مجاز نیست.
۵. با PHP CLI همان نسخه هاست از ریشه پروژه اجرا کنید:

```sh
php tools/migrate-database.php
```

اگر دسترسی Terminal/SSH ندارید، از پشتیبانی بخواهید همین فرمان را با محیط اتصال صحیح اجرا کند. فایل مهاجرت را به endpoint وب تبدیل نکنید. تا موفقیت این مرحله سایت را باز نکنید. اجرای مجدد باید موفق باشد؛ DDL در MySQL تراکنش سراسری قابل rollback ندارد.
۶. OPcache/PHP را از پنل reload کنید و مرورگر را کامل تازه کنید تا JS جدید دریافت شود. در صورت cache استاتیک URL نسخه‌دار یا purge لازم است.
۷. ابتدا ورود، کپچا، CSRF، فهرست کالا، جست‌وجو، تصویر و PDF را بررسی کنید. بعد در staging ایجاد/ویرایش/حذف فروش، خرید، برگشت، دریافت و پرداخت را آزمایش کنید و مانده/موجودی/گردش را تطبیق دهید.
۸. درخواست برگشت یکسان را دوبار بفرستید؛ یک سند و یک تغییر موجودی باید دیده شود. پاسخ را پس از commit قطع و دوباره تلاش کنید. همان توکن با محتوای متفاوت باید رد شود. درخواست ایجاد بدون توکن باید رد شود.
۹. پس از تأیید staging سایت را باز کنید؛ زمان پاسخ، خطاهای 503، تعداد اتصال‌های همان کاربر MySQL و انتظار قفل را پایش کنید.

## بازگشت

در حالت نگهداری فایل‌های نسخه پیشین را یکجا برگردانید. ستون‌های nullable و ایندکس جدید را حذف نکنید؛ برای نسخه قبلی مزاحم نیستند. بازگشت کد عملیات مالی ثبت‌شده را برنمی‌گرداند. بازیابی کامل بکاپ پس از ثبت مالی جدید باعث ازدست‌رفتن آن عملیات می‌شود و نیازمند تصمیم و تطبیق مستقل است.

## تست‌های اجراشده

- PHP 8.3.30 و MySQL 8.4.3 مستقل؛ سرور MySQL موجود کاربر تغییر داده نشد.
- بررسی نحوی همه PHPهای assets/php و tools، بررسی syntax فایل JS تغییرکرده و git diff --check.
- مهاجرت روی دیتابیس تازه: موفق؛ اجرای دوباره: موفق.
- ورود ساختار بکاپ ۵۳ جدول بدون داده، سپس دو بار مهاجرت: موفق.
- فروش/خدمت: ۶۳ موفق، صفر شکست.
- بسته کالا: ۷۴ موفق، صفر شکست.
- برگشت فروش شامل سه تست جدید و قفل هم‌زمان: ۶۷ موفق، صفر شکست.
- دفتر حسابداری شامل قفل هم‌زمان و عدم مصرف دوباره اعتبار: موفق.
- تسویه خودکار و rollback موجودی هنگام شکست دریافت: موفق.

در اجرای اولیه، تست برگشت به دلیل داده‌های باقی‌مانده از اجرای نیمه‌تمام و اتصال دوم hardcoded شکست خورد؛ با دیتابیس تازه و تنظیمات اتصال مشترک، اجرای کامل ۶۷/۰ موفق شد. واردکردن اولیه ساختار به علت ترتیب FK شکست خورد؛ استخراج ساختار با FOREIGN_KEY_CHECKS=0/1 اصلاح و در دیتابیس تازه تأیید شد.

## موارد باقی‌مانده و تنظیمات هاست

PHP-FPM و محدودیت MySQL روی هاست از این محیط قابل تغییر یا اندازه‌گیری نیستند. از پشتیبانی نسخه PHP/MySQL، handler نشست، pm.max_children، slowlog و شمار اتصال‌های مختص کاربر در لحظه خطا را بگیرید. افزایش worker بدون ظرفیت دیتابیس راهکار قطعی نیست. آمار GLOBAL سرور مشترک را به تنهایی به برنامه نسبت ندهید.

این شاخه بازنویسی پشتیبان‌گیری حجیم، تمام گزارش‌های مالی یا همه مسیرهای مدیریت خطا نیست. پشتیبان‌گیری فعلی هنوز کل خروجی را در حافظه جمع می‌کند؛ تا بازطراحی مستقل، از پشتیبان‌گیری زمان‌بندی‌شده هاست خارج از ساعات کار استفاده شود. پروفایل کوئری کند، EXPLAIN با حجم واقعی، تست HTTP هم‌زمان نشست و تست مرورگر روی هاست هنوز اجرا نشده‌اند. خطاهای داخلی در مسیرهای دیگر ممکن است باقی مانده باشند. هدف این تغییر، حذف کار تکراری راه‌اندازی و کاهش اشغال اتصال با حفاظت مشخص از retry برگشت است؛ رفع تمام مشکلات نرم‌افزار یا تضمین رفع اشباع سرور ادعا نمی‌شود.

## جزئیات دقیق خط به خط

Diff زیر تمام خطوط تغییرکرده فایل‌های موجود را با شماره خط قدیم و جدید نشان می‌دهد. فایل‌های جدید پس از آن به صورت کامل و شماره‌گذاری‌شده آمده‌اند. فایل ساختار SQL فقط ساختار است و در لینک مجزا قرار دارد.

```diff
diff --git a/.github/workflows/accounting-ledger-ci.yml b/.github/workflows/accounting-ledger-ci.yml
index 23c1a4a..037d828 100644
--- a/.github/workflows/accounting-ledger-ci.yml
+++ b/.github/workflows/accounting-ledger-ci.yml
@@ -53,6 +53,10 @@ jobs:
         with:
           php-version: '8.4'
           extensions: mysqli, mysqlnd, bcmath, mbstring, gd
+      - name: Provision explicit core schema
+        run: |
+          php -r '$m=new mysqli("127.0.0.1","root",""); $m->query("CREATE DATABASE IF NOT EXISTS memar_accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'
+          php tools/migrate-database.php
       - name: Settlement and balance tests
         run: php assets/php/tests/accounting_ledger_mysql_test.php
       - name: Automatic public customer settlement integrity tests
diff --git a/.github/workflows/currency-wip.yml b/.github/workflows/currency-wip.yml
index 4d718fd..0cf2f0d 100644
--- a/.github/workflows/currency-wip.yml
+++ b/.github/workflows/currency-wip.yml
@@ -117,5 +117,9 @@ jobs:
             sudo apt-get update -qq
             sudo apt-get install -y -qq poppler-utils
           fi
+      - name: Provision explicit core schema
+        run: |
+          php -r '$m=new mysqli("127.0.0.1","root",""); $m->query("CREATE DATABASE IF NOT EXISTS memar_accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'
+          php tools/migrate-database.php
       - name: Browser and actual rasterized PDF regression
         run: bash assets/php/tests/tabler_browser_smoke.sh
diff --git a/.github/workflows/media-ci.yml b/.github/workflows/media-ci.yml
index e527db6..f918cbe 100644
--- a/.github/workflows/media-ci.yml
+++ b/.github/workflows/media-ci.yml
@@ -46,6 +46,10 @@ jobs:
         run: |
           set -euo pipefail
           for f in assets/js/*.js assets/vendor/dropzone/dropzone.min.js; do node --check "$f"; done
+      - name: Provision explicit core schema
+        run: |
+          php -r '$m=new mysqli("127.0.0.1","root",""); $m->query("CREATE DATABASE IF NOT EXISTS memar_accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");'
+          php tools/migrate-database.php
       - name: Database schema and financial invariants
         shell: bash
         run: |
diff --git a/assets/js/sales-return.js b/assets/js/sales-return.js
index a63c945..563427b 100644
--- a/assets/js/sales-return.js
+++ b/assets/js/sales-return.js
@@ -9,6 +9,7 @@ $(function () {
     var csrf = String($('#srCsrfToken').val() || '');
     var currentPage = 1;
     var currentReturnId = '';
+    var requestToken = '';
     var currentInvoiceId = '';
     var currentCtx = null;      // invoice + items بارگذاری‌شده از سرور
     var taxRate = 0, taxEnabled = false;
@@ -193,6 +194,9 @@ $(function () {
         var invId = $(this).data('id');
         closeModal('srInvoiceModal');
         currentReturnId = '';
+        var tokenBytes = new Uint8Array(16);
+        window.crypto.getRandomValues(tokenBytes);
+        requestToken = Array.from(tokenBytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
         fetchCtx(invId, 0, null);
     });
 
@@ -420,6 +424,7 @@ $(function () {
         var payload = {
             csrf_token: csrf,
             return_id: currentReturnId,
+            client_token: requestToken,
             invoice_id: invoiceId,
             return_date: dateIso,
             note: String($('#srNote').val() || ''),
diff --git a/assets/php/auth.php b/assets/php/auth.php
index a733570..4c78482 100644
--- a/assets/php/auth.php
+++ b/assets/php/auth.php
@@ -12,7 +12,6 @@
 
 declare(strict_types=1);
 
-require_once __DIR__ . '/db.php';
 require_once __DIR__ . '/auth/auth_users.php';
 require_once __DIR__ . '/auth/auth_rate_limiter.php';
 require_once __DIR__ . '/auth/auth_settings.php';
@@ -34,6 +33,8 @@ if (session_status() === PHP_SESSION_NONE) {
     session_start();
 }
 
+require_once __DIR__ . '/db.php';
+
 /* ==================== CSRF (مشترک login/register + boot) ==================== */
 
 /**
diff --git a/assets/php/boot.php b/assets/php/boot.php
index 6d6dd64..8167c58 100644
--- a/assets/php/boot.php
+++ b/assets/php/boot.php
@@ -16,6 +16,9 @@
 require_once __DIR__ . '/auth.php';
 
 require_login();
+// Persist CSRF before releasing the session; endpoints only read this snapshot.
+csrf_token();
+if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
 
 /**
  * پاسخ JSON استاندارد پروژه + خروج
diff --git a/assets/php/db.php b/assets/php/db.php
index 95b0bd1..8307fb4 100644
--- a/assets/php/db.php
+++ b/assets/php/db.php
@@ -1,742 +1,25 @@
 <?php
-$dbHost = '127.0.0.1';
-$dbUser = 'root';
-$dbPass = '';
-$dbName = 'memar_accounting';
-
-$mysqli = new mysqli($dbHost, $dbUser, $dbPass);
-if ($mysqli->connect_errno) {
-    die('خطا در اتصال به دیتابیس: ' . $mysqli->connect_error);
-}
-
-$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
-$mysqli->select_db($dbName);
-$mysqli->set_charset('utf8mb4');
-
-// Authenticated image streaming uses an existing schema and MUST NOT execute
-// migrations / table setup for every thumbnail request.
-if (!defined('MEMAR_DB_READ_ONLY_FASTPATH')) {
-
-$createCustomers = "CREATE TABLE IF NOT EXISTS customers (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    first_name VARCHAR(120) NOT NULL,
-    last_name VARCHAR(120) NOT NULL,
-    national_code VARCHAR(20) DEFAULT NULL,
-    phone VARCHAR(40) NOT NULL,
-    economic_code VARCHAR(80) DEFAULT NULL,
-    registration_number VARCHAR(80) DEFAULT NULL,
-    address TEXT DEFAULT NULL,
-    postal_code VARCHAR(40) DEFAULT NULL,
-    note TEXT DEFAULT NULL,
-    total_spent DECIMAL(14,2) NOT NULL DEFAULT 0,
-    debt DECIMAL(14,2) NOT NULL DEFAULT 0,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
-
-$mysqli->query($createCustomers);
-
-$createSuppliers = "CREATE TABLE IF NOT EXISTS suppliers (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    company_name VARCHAR(150) DEFAULT NULL,
-    first_name VARCHAR(120) DEFAULT NULL,
-    last_name VARCHAR(120) DEFAULT NULL,
-    national_code VARCHAR(20) DEFAULT NULL,
-    phone VARCHAR(40) NOT NULL,
-    economic_code VARCHAR(80) DEFAULT NULL,
-    registration_number VARCHAR(80) DEFAULT NULL,
-    address TEXT DEFAULT NULL,
-    postal_code VARCHAR(40) DEFAULT NULL,
-    note TEXT DEFAULT NULL,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
-
-$mysqli->query($createSuppliers);
-
-$createCategories = "CREATE TABLE IF NOT EXISTS product_categories (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    code VARCHAR(50) NOT NULL UNIQUE,
-    name VARCHAR(150) NOT NULL,
-    parent_id INT DEFAULT NULL,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
-
-$mysqli->query($createCategories);
-
-$createProducts = "CREATE TABLE IF NOT EXISTS products (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    code VARCHAR(50) NOT NULL UNIQUE,
-    barcode VARCHAR(100) NULL,
-    name VARCHAR(150) NOT NULL,
-    category_id INT DEFAULT NULL,
-    type ENUM('product','service') NOT NULL DEFAULT 'product',
-    product_kind ENUM('standard','bundle') NOT NULL DEFAULT 'standard',
-    unit VARCHAR(50) NOT NULL DEFAULT 'عدد',
-    purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0,
-    sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
-    stock DECIMAL(14,2) NOT NULL DEFAULT 0,
-    min_stock DECIMAL(14,2) NOT NULL DEFAULT 0,
-    description TEXT DEFAULT NULL,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    UNIQUE KEY uq_products_barcode (barcode),
-    FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
-
-$mysqli->query($createProducts);
-
-/* --- واحدهای اندازه‌گیری هر کالا (Multi-Unit Inventory) --- */
-$createProductUnits = "CREATE TABLE IF NOT EXISTS product_units (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    product_id INT NOT NULL,
-    name VARCHAR(50) NOT NULL,
-    conversion_factor DECIMAL(14,4) NOT NULL DEFAULT 1,
-    is_base TINYINT(1) NOT NULL DEFAULT 0,
-    purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0,
-    sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
-    barcode VARCHAR(100) DEFAULT NULL,
-    sort_order INT NOT NULL DEFAULT 0,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
-    UNIQUE KEY uq_pu_product_name (product_id, name),
-    KEY idx_pu_product (product_id),
-    CONSTRAINT fk_pu_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-
-$mysqli->query($createProductUnits);
-
-$createStoreSettings = "CREATE TABLE IF NOT EXISTS store_settings (
-    id INT PRIMARY KEY,
-    store_name VARCHAR(255) NOT NULL,
-    economic_code VARCHAR(80) DEFAULT NULL,
-    national_code VARCHAR(80) DEFAULT NULL,
-    registration_number VARCHAR(80) DEFAULT NULL,
-    province_id INT DEFAULT NULL,
-    city_id INT DEFAULT NULL,
-    postal_code VARCHAR(40) DEFAULT NULL,
-    phone VARCHAR(60) DEFAULT NULL,
-    address TEXT DEFAULT NULL,
-    logo_path VARCHAR(255) DEFAULT NULL,
-    signature_path VARCHAR(255) DEFAULT NULL,
-    stamp_path VARCHAR(255) DEFAULT NULL,
-    default_size_percentage INT DEFAULT NULL,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
-
-$mysqli->query($createStoreSettings);
-
-/* Independent UI identity; invoice/store logo and financial schema stay intact. */
-$mysqli->query("CREATE TABLE IF NOT EXISTS app_brand_settings (
-    id INT PRIMARY KEY,
-    display_mode ENUM('name','logo','logo_name') NOT NULL DEFAULT 'name',
-    logo_path VARCHAR(255) DEFAULT NULL,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
-
-
-$createInvoiceSettings = "CREATE TABLE IF NOT EXISTS invoice_settings (
-    id INT PRIMARY KEY,
-    unofficial_invoice_desc TEXT DEFAULT NULL,
-    official_invoice_desc TEXT DEFAULT NULL,
-    proforma_desc TEXT DEFAULT NULL,
-    proforma_title VARCHAR(255) DEFAULT NULL,
-    invoice_template_color VARCHAR(20) DEFAULT NULL,
-    official_invoice_direction VARCHAR(20) DEFAULT 'vertical',
-    unofficial_invoice_direction VARCHAR(20) DEFAULT 'vertical',
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
-
-$mysqli->query($createInvoiceSettings);
-
-$createTaxSettings = "CREATE TABLE IF NOT EXISTS tax_settings (
-    id INT PRIMARY KEY,
-    tax_enabled TINYINT(1) NOT NULL DEFAULT 0,
-    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
-
-$mysqli->query($createTaxSettings);
-$mysqli->query("CREATE TABLE IF NOT EXISTS currency_settings (
-    id INT PRIMARY KEY,
-    currency_code VARCHAR(12) NOT NULL DEFAULT 'TOMAN',
-    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
-$mysqli->query("INSERT IGNORE INTO currency_settings (id, currency_code) VALUES (1, 'TOMAN')");
-
-$createDbBackupSettings = "CREATE TABLE IF NOT EXISTS db_backup_settings (
-    id INT PRIMARY KEY,
-    backup_dir VARCHAR(255) DEFAULT NULL,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
-
-$mysqli->query($createDbBackupSettings);
-
-/* ============================================================
- * ماژول فاکتور فروش/خرید — جداول جدید (Invoice Module)
- * ========================================================== */
-
-/* --- شمارنده اتمیک شماره فاکتور --- */
-$createInvoiceSequences = "CREATE TABLE IF NOT EXISTS invoice_sequences (
-    seq_key VARCHAR(40) PRIMARY KEY,
-    current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-$mysqli->query($createInvoiceSequences);
-
-/* اطمینان از وجود ۴ سری شماره */
-$mysqli->query("INSERT IGNORE INTO invoice_sequences (seq_key, current_value) VALUES
-    ('sales_invoice', 0),
-    ('sales_proforma', 0),
-    ('purchase_invoice', 0),
-    ('purchase_proforma', 0)");
-
-/* --- فاکتورها (فروش/خرید/پیش‌فاکتور) --- */
-$createInvoices = "CREATE TABLE IF NOT EXISTS invoices (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    invoice_number VARCHAR(40) NOT NULL UNIQUE,
-    type ENUM('sales_invoice','sales_proforma','purchase_invoice','purchase_proforma') NOT NULL,
-    customer_id INT NULL,
-    supplier_id INT NULL,
-    payment_type ENUM('cash','pos','bank_transfer') NOT NULL DEFAULT 'pos',
-    payment_status ENUM('paid','unpaid','partial') NOT NULL DEFAULT 'unpaid',
-    invoice_date DATE NOT NULL,
-    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
-    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
-    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
-    tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
-    payable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
-    note TEXT NULL,
-    client_token VARCHAR(64) NULL UNIQUE,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
-    KEY idx_inv_type (type),
-    KEY idx_inv_customer (customer_id),
-    KEY idx_inv_supplier (supplier_id),
-    KEY idx_inv_date (invoice_date),
-    CONSTRAINT fk_inv_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
-    CONSTRAINT fk_inv_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
-    CONSTRAINT chk_inv_party CHECK (
-        (type LIKE 'sales%' AND customer_id IS NOT NULL AND supplier_id IS NULL)
-        OR
-        (type LIKE 'purchase%' AND customer_id IS NULL AND supplier_id IS NOT NULL)
-    )
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-$mysqli->query($createInvoices);
-
-/* --- اقلام فاکتور --- */
-$createInvoiceItems = "CREATE TABLE IF NOT EXISTS invoice_items (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    invoice_id INT NOT NULL,
-    product_id INT NOT NULL,
-    quantity DECIMAL(14,2) NOT NULL,
-    unit_price DECIMAL(14,2) NOT NULL,
-    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
-    line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    KEY idx_ii_invoice (invoice_id),
-    KEY idx_ii_product (product_id),
-    CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
-    CONSTRAINT fk_ii_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
-    CONSTRAINT chk_ii_quantity CHECK (quantity > 0),
-    CONSTRAINT chk_ii_unit_price CHECK (unit_price >= 0),
-    CONSTRAINT chk_ii_discount CHECK (discount >= 0)
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-$mysqli->query($createInvoiceItems);
-
-/* ============================================================
- * برگشت فروش — جداول سند برگشت (Sales Return Module)
- * سند برگشت مستقل از فاکتور اصلی است و فاکتور اصلی دست‌نخورده می‌ماند.
- * ========================================================== */
-
-/* --- سندهای برگشت فروش --- */
-$createSalesReturns = "CREATE TABLE IF NOT EXISTS sales_returns (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    return_number VARCHAR(40) NOT NULL UNIQUE,
-    invoice_id INT NOT NULL,
-    customer_id INT NULL,
-    return_date DATE NOT NULL,
-    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
-    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
-    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
-    tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
-    payable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
-    note TEXT NULL,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
-    KEY idx_sr_invoice (invoice_id),
-    KEY idx_sr_customer (customer_id),
-    KEY idx_sr_date (return_date),
-    CONSTRAINT fk_sr_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
-    CONSTRAINT fk_sr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-$mysqli->query($createSalesReturns);
-
-/* --- اقلام برگشت فروش (snapshot بهای فاکتور اصلی) --- */
-$createSalesReturnItems = "CREATE TABLE IF NOT EXISTS sales_return_items (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    return_id INT NOT NULL,
-    invoice_item_id INT NULL,
-    product_id INT NOT NULL,
-    unit_id INT NULL,
-    unit_name VARCHAR(50) NULL,
-    conversion_factor DECIMAL(14,4) NULL,
-    quantity DECIMAL(14,2) NOT NULL,
-    base_quantity DECIMAL(14,2) NOT NULL,
-    unit_price DECIMAL(14,2) NOT NULL,
-    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
-    line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    KEY idx_sri_return (return_id),
-    KEY idx_sri_product (product_id),
-    KEY idx_sri_invoice_item (invoice_item_id),
-    CONSTRAINT fk_sri_return FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
-    CONSTRAINT fk_sri_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
-    CONSTRAINT chk_sri_quantity CHECK (quantity > 0),
-    CONSTRAINT chk_sri_base_quantity CHECK (base_quantity > 0),
-    CONSTRAINT chk_sri_unit_price CHECK (unit_price >= 0),
-    CONSTRAINT chk_sri_discount CHECK (discount >= 0)
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-$mysqli->query($createSalesReturnItems);
-
-/* اطمینان از وجود سری شماره برگشت فروش در شمارنده اتمیک پروژه */
-$mysqli->query("INSERT IGNORE INTO invoice_sequences (seq_key, current_value) VALUES ('sales_return', 0)");
-
-/* --- گردش موجودی (Audit Trail دائمی) --- */
-$createStockMovements = "CREATE TABLE IF NOT EXISTS stock_movements (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    product_id INT NOT NULL,
-    invoice_id INT NULL,
-    sales_return_id INT NULL,
-    type VARCHAR(30) NOT NULL,
-    direction ENUM('in','out') NOT NULL,
-    quantity DECIMAL(14,2) NOT NULL,
-    stock_before DECIMAL(14,2) NOT NULL,
-    stock_after DECIMAL(14,2) NOT NULL,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    KEY idx_sm_product (product_id),
-    KEY idx_sm_invoice (invoice_id),
-    KEY idx_sm_sales_return (sales_return_id),
-    KEY idx_sm_type (type),
-    KEY idx_sm_created (created_at),
-    CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
-    CONSTRAINT fk_sm_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-$mysqli->query($createStockMovements);
-/* ============================================================
- * Sticky Notes
- * ========================================================== */
-
-$createNotes = "CREATE TABLE IF NOT EXISTS notes (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    title VARCHAR(150) NOT NULL DEFAULT '',
-    content TEXT NOT NULL,
-    color VARCHAR(30) NOT NULL DEFAULT '#fff3a3',
-    pos_x INT NOT NULL DEFAULT 30,
-    pos_y INT NOT NULL DEFAULT 30,
-    z_index INT NOT NULL DEFAULT 1,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
-    KEY idx_notes_z_index (z_index),
-    KEY idx_notes_created (created_at)
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-
-$mysqli->query($createNotes);
-
-/* ============================================================
- * Multi-Unit Inventory — Schema Migration (idempotent)
- * ========================================================== */
-
-/**
- * بررسی وجود ستون در جدول (از information_schema)
- */
-function db_column_exists(mysqli $mysqli, string $table, string $column): bool
-{
-    $stmt = $mysqli->prepare(
-        'SELECT COUNT(*) AS c FROM information_schema.columns
-         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
-    );
-    $stmt->bind_param('ss', $table, $column);
-    $stmt->execute();
-    $res = $stmt->get_result();
-    $stmt->close();
-    $row = $res ? $res->fetch_assoc() : null;
-    return $row !== null && (int) $row['c'] > 0;
-}
-
-/**
- * بررسی وجود ایندکس در جدول
- */
-function db_index_exists(mysqli $mysqli, string $table, string $index): bool
-{
-    $stmt = $mysqli->prepare(
-        'SELECT COUNT(*) AS c FROM information_schema.statistics
-         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
-    );
-    $stmt->bind_param('ss', $table, $index);
-    $stmt->execute();
-    $res = $stmt->get_result();
-    $stmt->close();
-    $row = $res ? $res->fetch_assoc() : null;
-    return $row !== null && (int) $row['c'] > 0;
-}
-
-/**
- * افزودن ستون در صورت نبودن (idempotent)
- */
-function db_ensure_column(mysqli $mysqli, string $table, string $column, string $definition): bool
-{
-    if (db_column_exists($mysqli, $table, $column)) {
-        return true;
-    }
-    $sql = "ALTER TABLE `{$table}` ADD COLUMN {$definition}";
-    return (bool) $mysqli->query($sql);
-}
-
-/**
- * اجرای مهاجرت چندواحدی:
- *  - ستون‌های snapshot در invoice_items
- *  - ستون‌های snapshot در stock_movements
- *  - ایندکس‌های کمکی
- *  - backfill واحد پایه برای محصولات موجود (ضریب ۱)
- *  - backfill اطلاعات snapshot برای اقلام فاکتورهای قدیمی
- */
-function migrate_multi_unit_schema(mysqli $mysqli): void
-{
-    /* --- ستون‌های snapshot اقلام فاکتور --- */
-    db_ensure_column($mysqli, 'invoice_items', 'unit_id', "unit_id INT NULL AFTER product_id");
-    db_ensure_column($mysqli, 'invoice_items', 'unit_name', "unit_name VARCHAR(50) NULL AFTER unit_id");
-    db_ensure_column($mysqli, 'invoice_items', 'conversion_factor', "conversion_factor DECIMAL(14,4) NULL AFTER unit_name");
-    db_ensure_column($mysqli, 'invoice_items', 'base_quantity', "base_quantity DECIMAL(14,2) NULL AFTER quantity");
-
-    if (!db_index_exists($mysqli, 'invoice_items', 'idx_ii_unit')) {
-        $mysqli->query('ALTER TABLE invoice_items ADD KEY idx_ii_unit (unit_id)');
-    }
-
-    /* --- ستون‌های snapshot گردش موجودی --- */
-    db_ensure_column($mysqli, 'stock_movements', 'unit_name', "unit_name VARCHAR(50) NULL AFTER type");
-    db_ensure_column($mysqli, 'stock_movements', 'transaction_quantity', "transaction_quantity DECIMAL(14,2) NULL AFTER quantity");
-    db_ensure_column($mysqli, 'stock_movements', 'conversion_factor', "conversion_factor DECIMAL(14,4) NULL AFTER transaction_quantity");
-
-    /* --- اطمینان از وجود واحد پایه برای همه کالاها (با ضریب ۱) --- */
-    $needBase = 0;
-    $stmt = $mysqli->query(
-        'SELECT COUNT(*) AS c FROM products p
-         WHERE NOT EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id AND pu.is_base = 1)'
-    );
-    if ($stmt) {
-        $row = $stmt->fetch_assoc();
-        $needBase = (int) ($row['c'] ?? 0);
-        $stmt->free();
-    }
-    if ($needBase > 0) {
-        $mysqli->query(
-            'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
-             SELECT p.id, p.unit, 1, 1, p.purchase_price, p.sale_price, p.barcode, 0
-             FROM products p
-             WHERE NOT EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id AND pu.is_base = 1)'
-        );
-    }
-
-    /* --- همگام‌سازی products.unit با نام واحد پایه (در صورت تغییر) --- */
-    $mysqli->query(
-        'UPDATE products p
-         JOIN product_units pu ON pu.product_id = p.id AND pu.is_base = 1
-         SET p.unit = pu.name
-         WHERE p.unit COLLATE utf8mb4_unicode_ci <> pu.name'
-    );
-
-    /* --- backfill اطلاعات snapshot اقلام قدیمی (unit_name/factor/base_quantity) --- */
-    $missingSnap = 0;
-    $stmt = $mysqli->query('SELECT COUNT(*) AS c FROM invoice_items WHERE unit_id IS NULL');
-    if ($stmt) {
-        $row = $stmt->fetch_assoc();
-        $missingSnap = (int) ($row['c'] ?? 0);
-        $stmt->free();
-    }
-    if ($missingSnap > 0) {
-        $mysqli->query(
-            'UPDATE invoice_items ii
-             LEFT JOIN products p ON p.id = ii.product_id
-             LEFT JOIN product_units pu ON pu.product_id = p.id AND pu.is_base = 1
-             SET ii.unit_id = pu.id,
-                 ii.unit_name = COALESCE(pu.name, p.unit),
-                 ii.conversion_factor = 1,
-                 ii.base_quantity = ii.quantity
-             WHERE ii.unit_id IS NULL'
-        );
-    }
-}
-
-migrate_multi_unit_schema($mysqli);
-
-/* ============================================================
- * Sales Return — Schema Migration (idempotent)
- * ========================================================== */
-
-/**
- * افزودن ستون sales_return_id به stock_movements برای پیوند گردش موجودی
- * به سند برگشت فروش (به‌جای invoice_id که فقط به invoices اشاره می‌کند).
- * این تغییر صرفاً افزایشی است و رفتار ستون‌های موجود را تغییر نمی‌دهد.
- */
-function migrate_sales_return_schema(mysqli $mysqli): void
-{
-    db_ensure_column($mysqli, 'stock_movements', 'sales_return_id', "sales_return_id INT NULL AFTER invoice_id");
-    if (!db_index_exists($mysqli, 'stock_movements', 'idx_sm_sales_return')) {
-        $mysqli->query('ALTER TABLE stock_movements ADD KEY idx_sm_sales_return (sales_return_id)');
-    }
-}
-
-migrate_sales_return_schema($mysqli);
-
-/* ============================================================
- * Defective Goods — Schema Migration (idempotent)
- * ========================================================== */
-
-$createDefectiveGoods = "CREATE TABLE IF NOT EXISTS defective_goods (
-    id INT AUTO_INCREMENT PRIMARY KEY,
-    product_id INT NOT NULL,
-    quantity DECIMAL(14,2) NOT NULL,
-    defective_date DATE NOT NULL,
-    reason VARCHAR(500) NOT NULL,
-    description TEXT DEFAULT NULL,
-    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-    KEY idx_dg_product (product_id),
-    KEY idx_dg_date (defective_date),
-    KEY idx_dg_created (created_at),
-    CONSTRAINT fk_dg_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
-    CONSTRAINT chk_dg_quantity CHECK (quantity > 0)
-) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-
-$mysqli->query($createDefectiveGoods);
-
-/* ============================================================
- * Product Bundling / Bundle Sales — Schema (idempotent, additive)
- * ============================================================
- *  - products.product_kind discriminator (standard | bundle)
- *  - bundle_components: current composition of each bundle
- *  - invoice_bundle_items: historical snapshot of the composition
- *    that belonged to a specific invoice line at sale time
- *
- * هیچ‌کدام از این جداول، رفتار جداول موجود را تغییر نمی‌دهند و
- * ایجاد آن‌ها idempotent است (CREATE TABLE IF NOT EXISTS + db_ensure_column).
- * ========================================================== */
-
-function migrate_product_bundling_schema(mysqli $mysqli): void
-{
-    /* --- ستون discriminator روی products (برای نصب‌های موجود) --- */
-    db_ensure_column(
-        $mysqli,
-        'products',
-        'product_kind',
-        "product_kind ENUM('standard','bundle') NOT NULL DEFAULT 'standard' AFTER type"
-    );
-    if (!db_index_exists($mysqli, 'products', 'idx_products_kind')) {
-        $mysqli->query('ALTER TABLE products ADD KEY idx_products_kind (product_kind)');
-    }
-
-    /* --- ترکیب فعلی بسته‌ها --- */
-    $createBundleComponents = "CREATE TABLE IF NOT EXISTS bundle_components (
-        id INT AUTO_INCREMENT PRIMARY KEY,
-        bundle_product_id INT NOT NULL,
-        component_product_id INT NOT NULL,
-        quantity DECIMAL(14,2) NOT NULL,
-        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
-        UNIQUE KEY uq_bundle_component (bundle_product_id, component_product_id),
-        KEY idx_bc_bundle (bundle_product_id),
-        KEY idx_bc_component (component_product_id),
-        CONSTRAINT fk_bc_bundle FOREIGN KEY (bundle_product_id) REFERENCES products(id) ON DELETE RESTRICT,
-        CONSTRAINT fk_bc_component FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE RESTRICT,
-        CONSTRAINT chk_bc_quantity CHECK (quantity > 0)
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-    $mysqli->query($createBundleComponents);
-
-    /* --- snapshot تاریخی ترکیب بسته در هر ردیف فاکتور --- */
-    $createInvoiceBundleItems = "CREATE TABLE IF NOT EXISTS invoice_bundle_items (
-        id INT AUTO_INCREMENT PRIMARY KEY,
-        invoice_item_id INT NOT NULL,
-        bundle_product_id INT NOT NULL,
-        component_product_id INT NOT NULL,
-        component_quantity DECIMAL(14,2) NOT NULL,
-        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
-        KEY idx_ibi_invoice_item (invoice_item_id),
-        KEY idx_ibi_bundle (bundle_product_id),
-        KEY idx_ibi_component (component_product_id),
-        CONSTRAINT fk_ibi_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE CASCADE,
-        CONSTRAINT fk_ibi_bundle FOREIGN KEY (bundle_product_id) REFERENCES products(id) ON DELETE RESTRICT,
-        CONSTRAINT fk_ibi_component FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE RESTRICT,
-        CONSTRAINT chk_ibi_quantity CHECK (component_quantity > 0)
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-    $mysqli->query($createInvoiceBundleItems);
-}
-
-migrate_product_bundling_schema($mysqli);
-
-/* ============================================================
- * Authentication & Account Security — Schema (additive, idempotent)
- * ============================================================
- *  - users          : حساب‌های کاربری (مدیر) با رمز hashشده
- *  - auth_attempts  : پایدار (DB) ضد حمله‌ی جستجوی رمز / سوءاستفاده
- *  - auth_settings  : تنظیمات کپچا + سیاست قفل (ردیف id=1 مثل سایر تنظیمات)
- */
-
-function migrate_auth_schema(mysqli $mysqli): void
-{
-    $createUsers = "CREATE TABLE IF NOT EXISTS users (
-        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
-        username VARCHAR(64) NOT NULL,
-        first_name VARCHAR(120) NOT NULL DEFAULT '',
-        last_name VARCHAR(120) NOT NULL DEFAULT '',
-        password_hash VARCHAR(255) NOT NULL,
-        avatar_path VARCHAR(255) DEFAULT NULL,
-        is_active TINYINT(1) NOT NULL DEFAULT 1,
-        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
-        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
-        UNIQUE KEY uq_users_username (username),
-        KEY idx_users_active (is_active)
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-    $mysqli->query($createUsers);
-
-    $createAuthAttempts = "CREATE TABLE IF NOT EXISTS auth_attempts (
-        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
-        bucket VARCHAR(96) NOT NULL,
-        bucket_key VARCHAR(96) NOT NULL,
-        outcome VARCHAR(16) NOT NULL DEFAULT 'fail',
-        attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
-        KEY idx_auth_attempts_lookup (bucket, bucket_key, attempted_at),
-        KEY idx_auth_attempts_cleanup (attempted_at)
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-    $mysqli->query($createAuthAttempts);
-
-    $createAuthSettings = "CREATE TABLE IF NOT EXISTS auth_settings (
-        id INT PRIMARY KEY,
-        registration_locked TINYINT(1) NOT NULL DEFAULT 0,
-        captcha_provider VARCHAR(40) NOT NULL DEFAULT 'pure',
-        login_captcha_enabled TINYINT(1) NOT NULL DEFAULT 0,
-        registration_captcha_enabled TINYINT(1) NOT NULL DEFAULT 0,
-        captcha_lang VARCHAR(8) NOT NULL DEFAULT 'fa',
-        captcha_char_type VARCHAR(20) NOT NULL DEFAULT 'mixed',
-        captcha_length INT NOT NULL DEFAULT 5,
-        captcha_width INT NOT NULL DEFAULT 170,
-        captcha_height INT NOT NULL DEFAULT 55,
-        captcha_font_size INT NOT NULL DEFAULT 22,
-        captcha_noise_level VARCHAR(20) NOT NULL DEFAULT 'medium',
-        captcha_case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
-        captcha_expiry INT NOT NULL DEFAULT 180,
-        brute_max_attempts INT NOT NULL DEFAULT 5,
-        brute_window_minutes INT NOT NULL DEFAULT 15,
-        brute_lockout_minutes INT NOT NULL DEFAULT 15,
-        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
-    $mysqli->query($createAuthSettings);
-
-    /*
-     * ردیف تنظیمات همیشه باید موجود باشد تا تراکنش ثبت‌نام اولیه بتواند
-     * آن را با `SELECT ... FOR UPDATE` قفل کند (جلوگیری از ثبت‌نام همزمان).
-     */
-    $stmt = $mysqli->prepare(
-        'INSERT INTO auth_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id'
-    );
-    $stmt->execute();
-    $stmt->close();
-}
-
-migrate_auth_schema($mysqli);
-
-
-/* ============================================================
- * Product Media Library — additive, repeatable migration
- * The original product/financial tables are deliberately unchanged.
- * ========================================================== */
-function migrate_product_media_schema(mysqli $mysqli): void
-{
-    $mysqli->query("CREATE TABLE IF NOT EXISTS media_folders (
-        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
-        name VARCHAR(140) NOT NULL UNIQUE,
-        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
-    $mysqli->query("CREATE TABLE IF NOT EXISTS media (
-        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
-        original_filename VARCHAR(255) NOT NULL,
-        stored_filename VARCHAR(100) NOT NULL UNIQUE,
-        original_path VARCHAR(255) NOT NULL,
-        thumbnail_path VARCHAR(255) NOT NULL,
-        mime_type VARCHAR(40) NOT NULL,
-        file_size INT UNSIGNED NOT NULL,
-        width SMALLINT UNSIGNED NOT NULL,
-        height SMALLINT UNSIGNED NOT NULL,
-        sha256 CHAR(64) NOT NULL UNIQUE,
-        uploaded_by INT UNSIGNED DEFAULT NULL,
-        folder_id INT UNSIGNED NULL,
-        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
-        INDEX idx_media_created (created_at),
-        INDEX idx_media_folder (folder_id, id),
-        CONSTRAINT fk_media_folder FOREIGN KEY (folder_id) REFERENCES media_folders(id) ON DELETE RESTRICT
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
-
-    // Existing installations already have media; migrate in-place without data loss.
-    db_ensure_column($mysqli, 'media', 'folder_id', 'folder_id INT UNSIGNED NULL AFTER uploaded_by');
-    if (!db_index_exists($mysqli, 'media', 'idx_media_folder')) {
-        $mysqli->query('ALTER TABLE media ADD KEY idx_media_folder (folder_id, id)');
-    }
-    $fk = $mysqli->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
-        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media'
-          AND CONSTRAINT_NAME = 'fk_media_folder' AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1");
-    if ($fk && $fk->num_rows === 0) {
-        $mysqli->query('ALTER TABLE media ADD CONSTRAINT fk_media_folder
-            FOREIGN KEY (folder_id) REFERENCES media_folders(id) ON DELETE RESTRICT');
-    }
-    if ($fk) $fk->free();
-
-    $mysqli->query("CREATE TABLE IF NOT EXISTS product_media (
-        product_id INT NOT NULL,
-        media_id INT UNSIGNED NOT NULL,
-        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
-        PRIMARY KEY (product_id, media_id),
-        KEY idx_pm_product_order (product_id, sort_order),
-        KEY idx_pm_media (media_id),
-        CONSTRAINT fk_pm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
-        CONSTRAINT fk_pm_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
-
-    $mysqli->query("CREATE TABLE IF NOT EXISTS category_media (
-        category_id INT NOT NULL PRIMARY KEY,
-        media_id INT UNSIGNED NOT NULL,
-        CONSTRAINT fk_cm_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE,
-        CONSTRAINT fk_cm_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
-
-    // A defective registration has its own photos; never overwrite product photos.
-    $mysqli->query("CREATE TABLE IF NOT EXISTS defective_media (
-        defective_id INT NOT NULL,
-        media_id INT UNSIGNED NOT NULL,
-        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
-        PRIMARY KEY (defective_id, media_id),
-        INDEX idx_defective_media_order (defective_id, sort_order),
-        CONSTRAINT fk_defective_media_entry FOREIGN KEY (defective_id) REFERENCES defective_goods(id) ON DELETE CASCADE,
-        CONSTRAINT fk_defective_media_file FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
-
-    $mysqli->query("CREATE TABLE IF NOT EXISTS media_display_settings (
-        id INT PRIMARY KEY,
-        products_list TINYINT(1) NOT NULL DEFAULT 1,
-        sales_ui TINYINT(1) NOT NULL DEFAULT 1,
-        purchase_ui TINYINT(1) NOT NULL DEFAULT 1,
-        sales_returns_ui TINYINT(1) NOT NULL DEFAULT 1,
-        defective_ui TINYINT(1) NOT NULL DEFAULT 1,
-        reports_ui TINYINT(1) NOT NULL DEFAULT 0,
-        category_images TINYINT(1) NOT NULL DEFAULT 1,
-        pdf_sales TINYINT(1) NOT NULL DEFAULT 0,
-        pdf_sales_proforma TINYINT(1) NOT NULL DEFAULT 0,
-        pdf_purchase TINYINT(1) NOT NULL DEFAULT 0,
-        pdf_purchase_proforma TINYINT(1) NOT NULL DEFAULT 0,
-        pdf_sales_return TINYINT(1) NOT NULL DEFAULT 0,
-        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
-    $mysqli->query("INSERT IGNORE INTO media_display_settings (id) VALUES (1)");
-}
-migrate_product_media_schema($mysqli);
-require_once __DIR__ . '/accounting_ledger_migration.php';
-migrate_accounting_ledger($mysqli);
-migrate_accounting_auto_settlement($mysqli);
-
-} // End database schema initialization; normal application requests are unchanged.
+// Credentials can be supplied by environment without changing tracked files.
+$dbHost = getenv('MEMAR_DB_HOST') ?: '127.0.0.1';
+$dbUser = getenv('MEMAR_DB_USER') ?: 'root';
+$dbPass = getenv('MEMAR_DB_PASS') !== false ? getenv('MEMAR_DB_PASS') : '';
+$dbPort = (int) (getenv('MEMAR_DB_PORT') ?: 3306);
+$dbName = getenv('MEMAR_DB_NAME') ?: 'memar_accounting';
+mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
+try {
+    if (str_starts_with($dbHost, 'p:')) {
+        throw new RuntimeException('Persistent database hosts are disabled');
+    }
+    $mysqli = mysqli_init();
+    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
+    $mysqli->real_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
+    $mysqli->set_charset('utf8mb4');
+} catch (Throwable $e) {
+    error_log('Database connection failed: ' . $e->getMessage());
+    if (PHP_SAPI === 'cli') { throw $e; }
+    http_response_code(503);
+    header('Content-Type: application/json; charset=UTF-8');
+    echo json_encode(['success' => false, 'message' => 'سرویس پایگاه داده موقتاً در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
+    exit;
+}
+require_once __DIR__ . '/db_schema_helpers.php';
diff --git a/assets/php/invoice_common.php b/assets/php/invoice_common.php
index 3fcb54f..f318856 100644
--- a/assets/php/invoice_common.php
+++ b/assets/php/invoice_common.php
@@ -502,6 +502,19 @@ function normalizeItems(mysqli $mysqli, $rawItems): array
         return ['ok' => false, 'message' => 'حداقل یک ردیف کالا باید ارسال شود.', 'items' => []];
     }
 
+    $unitIds = [];
+    foreach ($rawItems as $candidate) {
+        if (is_array($candidate) && ctype_digit((string)($candidate['unit_id'] ?? ''))) {
+            $unitIds[] = (int)$candidate['unit_id'];
+        }
+    }
+    $unitMap = [];
+    if ($unitIds) {
+        $ids = implode(',', array_unique($unitIds)); // integers only
+        $units = $mysqli->query('SELECT pu.*, p.type AS product_type FROM product_units pu LEFT JOIN products p ON p.id=pu.product_id WHERE pu.id IN (' . $ids . ')');
+        while ($unitRow = $units->fetch_assoc()) { $unitMap[(int)$unitRow['id']] = $unitRow; }
+        $units->free();
+    }
     $merged = [];
     foreach ($rawItems as $raw) {
         if (!is_array($raw)) {
@@ -540,18 +553,8 @@ function normalizeItems(mysqli $mysqli, $rawItems): array
                 return ['ok' => false, 'message' => "واحد اندازه‌گیری کالای {$pid} نامعتبر است.", 'items' => []];
             }
             // بارگذاری واحد از دیتابیس و اطمینان از تعلق آن به این کالا
-            $stmt = $mysqli->prepare(
-                'SELECT pu.*, p.type AS product_type FROM product_units pu
-                 LEFT JOIN products p ON p.id = pu.product_id
-                 WHERE pu.id = ? AND pu.product_id = ? LIMIT 1'
-            );
-            $unitIdInt = (int) $unitIdRaw;
-            $pidInt = $pid;
-            $stmt->bind_param('ii', $unitIdInt, $pidInt);
-            $stmt->execute();
-            $res = $stmt->get_result();
-            $stmt->close();
-            $row = $res ? $res->fetch_assoc() : null;
+            $row = $unitMap[(int)$unitIdRaw] ?? null;
+            if ($row && (int)$row['product_id'] !== $pid) { $row = null; }
             if (!$row) {
                 return ['ok' => false, 'message' => "واحد اندازه‌گیری انتخابی برای کالای {$pid} معتبر نیست.", 'items' => []];
             }
@@ -1030,7 +1033,8 @@ function invoiceErrorMessage(Exception $e, mysqli $mysqli, ?string $clientToken)
             return 'DOUBLE_SUBMIT_REUSE';
         }
     }
-    return 'خطا در ذخیره‌سازی: ' . $msg;
+    error_log('Invoice operation failed: ' . $msg);
+    return 'خطا در ذخیره‌سازی؛ لطفاً وضعیت سند را بررسی کنید.';
 }
 
 /* ============================================================
diff --git a/assets/php/invoice_save.php b/assets/php/invoice_save.php
index 618d681..7729301 100644
--- a/assets/php/invoice_save.php
+++ b/assets/php/invoice_save.php
@@ -66,6 +66,9 @@ $in = [
 ];
 
 if ($invoiceId === '') {
+    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $in['client_token'])) {
+        respond_error('شناسه درخواست نامعتبر است؛ صفحه را تازه کنید.', 422);
+    }
     $result = createInvoice($mysqli, $in);
 } else {
     if (!ctype_digit($invoiceId)) {
diff --git a/assets/php/media_file.php b/assets/php/media_file.php
index c9d6e59..981b70e 100644
--- a/assets/php/media_file.php
+++ b/assets/php/media_file.php
@@ -11,6 +11,8 @@ if (!$id || !in_array($size, ['thumb','original'], true)) {
     http_response_code(400); exit;
 }
 $m = media_get($mysqli, (int)$id);
+// No database work remains while a potentially large file is streamed.
+$mysqli->close();
 $path = $m ? media_absolute_path((string)$m[$size === 'thumb' ? 'thumbnail_path' : 'original_path']) : null;
 if (!$path) { http_response_code(404); exit; }
 header('Content-Type: ' . ($size === 'thumb' ? 'image/jpeg' : $m['mime_type']));
diff --git a/assets/php/sales_return_common.php b/assets/php/sales_return_common.php
index 330413b..877fee7 100644
--- a/assets/php/sales_return_common.php
+++ b/assets/php/sales_return_common.php
@@ -518,6 +518,8 @@ function createSalesReturn(mysqli $mysqli, array $in): array
         $rawItems = [];
     }
 
+    $clientToken = trim((string) ($in['client_token'] ?? ''));
+    $requestHash = hash('sha256', json_encode([$invoiceId, $in['return_date'], $note, $rawItems], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
     $mysqli->begin_transaction();
     try {
         /* قفل فاکتور: تمام سندهای برگشت یک فاکتور سریالی اجرا می‌شوند */
@@ -534,6 +536,18 @@ function createSalesReturn(mysqli $mysqli, array $in): array
             throw new Exception('not_final_sales');
         }
 
+        if ($clientToken !== '') {
+            $dedup = $mysqli->prepare('SELECT id, return_number, request_hash FROM sales_returns WHERE client_token = ?');
+            $dedup->bind_param('s', $clientToken); $dedup->execute();
+            $existing = $dedup->get_result()->fetch_assoc(); $dedup->close();
+            if ($existing) {
+                if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
+                    throw new Exception('request_token_conflict');
+                }
+                $mysqli->commit();
+                return ['ok'=>true, 'message'=>'این درخواست قبلاً ثبت شده است.', 'return_id'=>(int)$existing['id'], 'return_number'=>$existing['return_number']];
+            }
+        }
         $ctx = sr_invoice_context($mysqli, $invoiceId, null);
         if (!$ctx['ok']) {
             throw new Exception('invoice_context');
@@ -579,6 +593,10 @@ function createSalesReturn(mysqli $mysqli, array $in): array
         $returnId = (int) $stmt->insert_id;
         $stmt->close();
 
+        if ($clientToken !== '') {
+            $dedup = $mysqli->prepare('UPDATE sales_returns SET client_token = ?, request_hash = ? WHERE id = ?');
+            $dedup->bind_param('ssi', $clientToken, $requestHash, $returnId); $dedup->execute(); $dedup->close();
+        }
         sr_insert_return_items($mysqli, $returnId, $items);
         sr_apply_stock_for_items($mysqli, $items, $returnId, 'create');
 
@@ -598,6 +616,8 @@ function createSalesReturn(mysqli $mysqli, array $in): array
         } elseif ($msg === 'product_not_found') {
             $msg = 'یکی از کالاهای برگشتی دیگر معتبر نیست.';
         }
+        if ($e instanceof mysqli_sql_exception) { error_log('Sales return SQL failure: ' . $msg); $msg = 'خطای ذخیره‌سازی؛ وضعیت سند را بررسی کنید.'; }
+        if ($msg === 'request_token_conflict') { $msg = 'شناسه درخواست برای محتوای دیگری استفاده شده است.'; }
         return ['ok' => false, 'message' => $msg];
     }
 }
@@ -822,6 +842,8 @@ function editSalesReturn(mysqli $mysqli, int $returnId, array $in): array
         } elseif ($msg === 'product_not_found') {
             $msg = 'یکی از کالاهای برگشتی دیگر معتبر نیست.';
         }
+        if ($e instanceof mysqli_sql_exception) { error_log('Sales return SQL failure: ' . $msg); $msg = 'خطای ذخیره‌سازی؛ وضعیت سند را بررسی کنید.'; }
+        if ($msg === 'request_token_conflict') { $msg = 'شناسه درخواست برای محتوای دیگری استفاده شده است.'; }
         return ['ok' => false, 'message' => $msg];
     }
 }
@@ -901,6 +923,8 @@ function deleteSalesReturn(mysqli $mysqli, int $returnId): array
         } elseif ($msg === 'product_not_found') {
             $msg = 'یکی از کالاهای برگشتی دیگر معتبر نیست.';
         }
+        if ($e instanceof mysqli_sql_exception) { error_log('Sales return SQL failure: ' . $msg); $msg = 'خطای ذخیره‌سازی؛ وضعیت سند را بررسی کنید.'; }
+        if ($msg === 'request_token_conflict') { $msg = 'شناسه درخواست برای محتوای دیگری استفاده شده است.'; }
         return ['ok' => false, 'message' => $msg];
     }
 }
diff --git a/assets/php/sales_return_save.php b/assets/php/sales_return_save.php
index b1337d5..36279e2 100644
--- a/assets/php/sales_return_save.php
+++ b/assets/php/sales_return_save.php
@@ -25,6 +25,7 @@ if (!is_array($items)) {
 }
 
 $in = [
+    'client_token' => trim((string) ($_POST['client_token'] ?? '')),
     'invoice_id'  => trim((string) ($_POST['invoice_id'] ?? '')),
     'return_date' => trim((string) ($_POST['return_date'] ?? '')),
     'note'        => trim((string) ($_POST['note'] ?? '')),
@@ -32,6 +33,9 @@ $in = [
 ];
 
 if ($returnId === '') {
+    if (!preg_match('/^[a-f0-9]{32,64}$/D', $in['client_token'])) {
+        respond_error('شناسه درخواست نامعتبر است؛ صفحه را تازه کنید.', 422);
+    }
     $result = createSalesReturn($mysqli, $in);
 } else {
     if (!ctype_digit($returnId) || (int) $returnId <= 0) {
diff --git a/assets/php/tests/accounting_ledger_mysql_test.php b/assets/php/tests/accounting_ledger_mysql_test.php
index 83d3ee2..98c98ab 100644
--- a/assets/php/tests/accounting_ledger_mysql_test.php
+++ b/assets/php/tests/accounting_ledger_mysql_test.php
@@ -376,7 +376,7 @@ check_acct(acct_party_statement($mysqli,'customer',$returnParty)['returns'][0]['
 // while the first writer owns its party lock. Final amounts remain bounded.
 $concurrentParty=a_customer($mysqli);
 pay_acct($mysqli,'customer',$concurrentParty,'50000',[]);
-$waiter=new mysqli('127.0.0.1','root','','memar_accounting');
+$waiter=new mysqli($dbHost,$dbUser,$dbPass,$dbName,$dbPort);
 $waiter->set_charset('utf8mb4');
 $waiter->query('SET SESSION innodb_lock_wait_timeout=1');
 $mysqli->begin_transaction();
diff --git a/assets/php/tests/sales_return_test.php b/assets/php/tests/sales_return_test.php
index a00af7b..d5d47c3 100644
--- a/assets/php/tests/sales_return_test.php
+++ b/assets/php/tests/sales_return_test.php
@@ -412,6 +412,17 @@ $ret = createSalesReturn($mysqli, t_ret_input($saleInv, [t_ret_item((int) $ii[0]
 t_check('return 2 cartons (base 20) ok', $ret['ok'], $ret['message']);
 $stockNow = t_stock_of($mysqli, $pid);
 t_check('stock increased by 20 (base units)', money_cmp($stockNow, '470') === 0, $stockNow);
+$retryInput = t_ret_input($saleInv, [t_ret_item((int)$ii[0]['id'], $pid, $cartonUnit, 1)]);
+$retryInput['client_token'] = bin2hex(random_bytes(16));
+$firstRetry = createSalesReturn($mysqli, $retryInput);
+$stockBeforeRetry = t_stock_of($mysqli, $pid);
+$secondRetry = createSalesReturn($mysqli, $retryInput);
+t_check('same token returns same return document', $firstRetry['ok'] && $secondRetry['ok'] && $firstRetry['return_id'] === $secondRetry['return_id']);
+t_check('retry does not change stock', money_cmp(t_stock_of($mysqli, $pid), $stockBeforeRetry) === 0);
+$retryInput['note'] = 'different content';
+$conflictRetry = createSalesReturn($mysqli, $retryInput);
+t_check('same token different content rejected', !$conflictRetry['ok']);
+
 
 echo "\n== Test J2: CSRF via endpoint (subprocess) ==\n";
 /** اجرای کد PHP در یک process جداگانه (بدون مشکل نقل‌قول shell) */
@@ -431,7 +442,7 @@ $out1 = t_run_child($code1);
 t_check('invalid CSRF => success false', str_contains($out1, '"success":false'), substr($out1, 0, 80));
 t_check('invalid CSRF message mentions CSRF', mb_strpos($out1, 'CSRF') !== false);
 $code2 = 'session_start();$_SESSION["auth_user"]="memar";$_SESSION["csrf_token"]="good-token";'
-    . '$_POST=["csrf_token"=>"good-token","invoice_id"=>"9999999","return_date"=>"2026-01-01","note"=>"","items"=>"[]"];'
+    . '$_POST=["csrf_token"=>"good-token","client_token"=>"0123456789abcdef0123456789abcdef","invoice_id"=>"9999999","return_date"=>"2026-01-01","note"=>"","items"=>"[]"];'
     . 'require ' . var_export($saveAbs, true) . ';';
 $out2 = t_run_child($code2);
 t_check('valid CSRF passed (moved to invoice validation)', str_contains($out2, 'فاکتور موردنظر یافت نشد'), substr($out2, 0, 80));
@@ -441,7 +452,7 @@ $p = t_make_product($mysqli, 'T-SR-K-1', 'همزمانی');
 $r = t_purchase($mysqli, $p['id'], $p['unit_id'], 100, '1000');
 $r = t_sell($mysqli, $p['id'], $p['unit_id'], 10, '2000');
 $saleInv = (int) $r['invoice_id'];
-$c2 = new mysqli('127.0.0.1', 'root', '', 'memar_accounting');
+$c2 = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
 $c2->set_charset('utf8mb4');
 $c2->query('SET SESSION innodb_lock_wait_timeout = 1');
 $mysqli->begin_transaction();

```

### assets/php/db_schema.php

```text
1: <?php
2: // Explicit CLI deployment only. Never run schema changes from an HTTP request.
3: if (PHP_SAPI !== 'cli' || !defined('MEMAR_RUN_SCHEMA')) { http_response_code(404); exit; }
4: require_once __DIR__ . '/db_schema_helpers.php';
5: $createCustomers = "CREATE TABLE IF NOT EXISTS customers (
6:     id INT AUTO_INCREMENT PRIMARY KEY,
7:     first_name VARCHAR(120) NOT NULL,
8:     last_name VARCHAR(120) NOT NULL,
9:     national_code VARCHAR(20) DEFAULT NULL,
10:     phone VARCHAR(40) NOT NULL,
11:     economic_code VARCHAR(80) DEFAULT NULL,
12:     registration_number VARCHAR(80) DEFAULT NULL,
13:     address TEXT DEFAULT NULL,
14:     postal_code VARCHAR(40) DEFAULT NULL,
15:     note TEXT DEFAULT NULL,
16:     total_spent DECIMAL(14,2) NOT NULL DEFAULT 0,
17:     debt DECIMAL(14,2) NOT NULL DEFAULT 0,
18:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
19: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
20: 
21: $mysqli->query($createCustomers);
22: 
23: $createSuppliers = "CREATE TABLE IF NOT EXISTS suppliers (
24:     id INT AUTO_INCREMENT PRIMARY KEY,
25:     company_name VARCHAR(150) DEFAULT NULL,
26:     first_name VARCHAR(120) DEFAULT NULL,
27:     last_name VARCHAR(120) DEFAULT NULL,
28:     national_code VARCHAR(20) DEFAULT NULL,
29:     phone VARCHAR(40) NOT NULL,
30:     economic_code VARCHAR(80) DEFAULT NULL,
31:     registration_number VARCHAR(80) DEFAULT NULL,
32:     address TEXT DEFAULT NULL,
33:     postal_code VARCHAR(40) DEFAULT NULL,
34:     note TEXT DEFAULT NULL,
35:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
36: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
37: 
38: $mysqli->query($createSuppliers);
39: 
40: $createCategories = "CREATE TABLE IF NOT EXISTS product_categories (
41:     id INT AUTO_INCREMENT PRIMARY KEY,
42:     code VARCHAR(50) NOT NULL UNIQUE,
43:     name VARCHAR(150) NOT NULL,
44:     parent_id INT DEFAULT NULL,
45:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
46:     FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL
47: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
48: 
49: $mysqli->query($createCategories);
50: 
51: $createProducts = "CREATE TABLE IF NOT EXISTS products (
52:     id INT AUTO_INCREMENT PRIMARY KEY,
53:     code VARCHAR(50) NOT NULL UNIQUE,
54:     barcode VARCHAR(100) NULL,
55:     name VARCHAR(150) NOT NULL,
56:     category_id INT DEFAULT NULL,
57:     type ENUM('product','service') NOT NULL DEFAULT 'product',
58:     product_kind ENUM('standard','bundle') NOT NULL DEFAULT 'standard',
59:     unit VARCHAR(50) NOT NULL DEFAULT 'عدد',
60:     purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0,
61:     sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
62:     stock DECIMAL(14,2) NOT NULL DEFAULT 0,
63:     min_stock DECIMAL(14,2) NOT NULL DEFAULT 0,
64:     description TEXT DEFAULT NULL,
65:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
66:     UNIQUE KEY uq_products_barcode (barcode),
67:     FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL
68: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
69: 
70: $mysqli->query($createProducts);
71: 
72: /* --- واحدهای اندازه‌گیری هر کالا (Multi-Unit Inventory) --- */
73: $createProductUnits = "CREATE TABLE IF NOT EXISTS product_units (
74:     id INT AUTO_INCREMENT PRIMARY KEY,
75:     product_id INT NOT NULL,
76:     name VARCHAR(50) NOT NULL,
77:     conversion_factor DECIMAL(14,4) NOT NULL DEFAULT 1,
78:     is_base TINYINT(1) NOT NULL DEFAULT 0,
79:     purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0,
80:     sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
81:     barcode VARCHAR(100) DEFAULT NULL,
82:     sort_order INT NOT NULL DEFAULT 0,
83:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
84:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
85:     UNIQUE KEY uq_pu_product_name (product_id, name),
86:     KEY idx_pu_product (product_id),
87:     CONSTRAINT fk_pu_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
88: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
89: 
90: $mysqli->query($createProductUnits);
91: 
92: $createStoreSettings = "CREATE TABLE IF NOT EXISTS store_settings (
93:     id INT PRIMARY KEY,
94:     store_name VARCHAR(255) NOT NULL,
95:     economic_code VARCHAR(80) DEFAULT NULL,
96:     national_code VARCHAR(80) DEFAULT NULL,
97:     registration_number VARCHAR(80) DEFAULT NULL,
98:     province_id INT DEFAULT NULL,
99:     city_id INT DEFAULT NULL,
100:     postal_code VARCHAR(40) DEFAULT NULL,
101:     phone VARCHAR(60) DEFAULT NULL,
102:     address TEXT DEFAULT NULL,
103:     logo_path VARCHAR(255) DEFAULT NULL,
104:     signature_path VARCHAR(255) DEFAULT NULL,
105:     stamp_path VARCHAR(255) DEFAULT NULL,
106:     default_size_percentage INT DEFAULT NULL,
107:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
108: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
109: 
110: $mysqli->query($createStoreSettings);
111: 
112: /* Independent UI identity; invoice/store logo and financial schema stay intact. */
113: $mysqli->query("CREATE TABLE IF NOT EXISTS app_brand_settings (
114:     id INT PRIMARY KEY,
115:     display_mode ENUM('name','logo','logo_name') NOT NULL DEFAULT 'name',
116:     logo_path VARCHAR(255) DEFAULT NULL,
117:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
118: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
119: 
120: 
121: $createInvoiceSettings = "CREATE TABLE IF NOT EXISTS invoice_settings (
122:     id INT PRIMARY KEY,
123:     unofficial_invoice_desc TEXT DEFAULT NULL,
124:     official_invoice_desc TEXT DEFAULT NULL,
125:     proforma_desc TEXT DEFAULT NULL,
126:     proforma_title VARCHAR(255) DEFAULT NULL,
127:     invoice_template_color VARCHAR(20) DEFAULT NULL,
128:     official_invoice_direction VARCHAR(20) DEFAULT 'vertical',
129:     unofficial_invoice_direction VARCHAR(20) DEFAULT 'vertical',
130:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
131: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
132: 
133: $mysqli->query($createInvoiceSettings);
134: 
135: $createTaxSettings = "CREATE TABLE IF NOT EXISTS tax_settings (
136:     id INT PRIMARY KEY,
137:     tax_enabled TINYINT(1) NOT NULL DEFAULT 0,
138:     tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
139:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
140: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
141: 
142: $mysqli->query($createTaxSettings);
143: $mysqli->query("CREATE TABLE IF NOT EXISTS currency_settings (
144:     id INT PRIMARY KEY,
145:     currency_code VARCHAR(12) NOT NULL DEFAULT 'TOMAN',
146:     updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
147: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
148: $mysqli->query("INSERT IGNORE INTO currency_settings (id, currency_code) VALUES (1, 'TOMAN')");
149: 
150: $createDbBackupSettings = "CREATE TABLE IF NOT EXISTS db_backup_settings (
151:     id INT PRIMARY KEY,
152:     backup_dir VARCHAR(255) DEFAULT NULL,
153:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
154: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
155: 
156: $mysqli->query($createDbBackupSettings);
157: 
158: /* ============================================================
159:  * ماژول فاکتور فروش/خرید — جداول جدید (Invoice Module)
160:  * ========================================================== */
161: 
162: /* --- شمارنده اتمیک شماره فاکتور --- */
163: $createInvoiceSequences = "CREATE TABLE IF NOT EXISTS invoice_sequences (
164:     seq_key VARCHAR(40) PRIMARY KEY,
165:     current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
166:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
167: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
168: $mysqli->query($createInvoiceSequences);
169: 
170: /* اطمینان از وجود ۴ سری شماره */
171: $mysqli->query("INSERT IGNORE INTO invoice_sequences (seq_key, current_value) VALUES
172:     ('sales_invoice', 0),
173:     ('sales_proforma', 0),
174:     ('purchase_invoice', 0),
175:     ('purchase_proforma', 0)");
176: 
177: /* --- فاکتورها (فروش/خرید/پیش‌فاکتور) --- */
178: $createInvoices = "CREATE TABLE IF NOT EXISTS invoices (
179:     id INT AUTO_INCREMENT PRIMARY KEY,
180:     invoice_number VARCHAR(40) NOT NULL UNIQUE,
181:     type ENUM('sales_invoice','sales_proforma','purchase_invoice','purchase_proforma') NOT NULL,
182:     customer_id INT NULL,
183:     supplier_id INT NULL,
184:     payment_type ENUM('cash','pos','bank_transfer') NOT NULL DEFAULT 'pos',
185:     payment_status ENUM('paid','unpaid','partial') NOT NULL DEFAULT 'unpaid',
186:     invoice_date DATE NOT NULL,
187:     subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
188:     discount DECIMAL(14,2) NOT NULL DEFAULT 0,
189:     tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
190:     tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
191:     payable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
192:     note TEXT NULL,
193:     client_token VARCHAR(64) NULL UNIQUE,
194:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
195:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
196:     KEY idx_inv_type (type),
197:     KEY idx_inv_customer (customer_id),
198:     KEY idx_inv_supplier (supplier_id),
199:     KEY idx_inv_date (invoice_date),
200:     CONSTRAINT fk_inv_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
201:     CONSTRAINT fk_inv_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
202:     CONSTRAINT chk_inv_party CHECK (
203:         (type LIKE 'sales%' AND customer_id IS NOT NULL AND supplier_id IS NULL)
204:         OR
205:         (type LIKE 'purchase%' AND customer_id IS NULL AND supplier_id IS NOT NULL)
206:     )
207: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
208: $mysqli->query($createInvoices);
209: 
210: /* --- اقلام فاکتور --- */
211: $createInvoiceItems = "CREATE TABLE IF NOT EXISTS invoice_items (
212:     id INT AUTO_INCREMENT PRIMARY KEY,
213:     invoice_id INT NOT NULL,
214:     product_id INT NOT NULL,
215:     quantity DECIMAL(14,2) NOT NULL,
216:     unit_price DECIMAL(14,2) NOT NULL,
217:     discount DECIMAL(14,2) NOT NULL DEFAULT 0,
218:     line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
219:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
220:     KEY idx_ii_invoice (invoice_id),
221:     KEY idx_ii_product (product_id),
222:     CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
223:     CONSTRAINT fk_ii_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
224:     CONSTRAINT chk_ii_quantity CHECK (quantity > 0),
225:     CONSTRAINT chk_ii_unit_price CHECK (unit_price >= 0),
226:     CONSTRAINT chk_ii_discount CHECK (discount >= 0)
227: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
228: $mysqli->query($createInvoiceItems);
229: 
230: /* ============================================================
231:  * برگشت فروش — جداول سند برگشت (Sales Return Module)
232:  * سند برگشت مستقل از فاکتور اصلی است و فاکتور اصلی دست‌نخورده می‌ماند.
233:  * ========================================================== */
234: 
235: /* --- سندهای برگشت فروش --- */
236: $createSalesReturns = "CREATE TABLE IF NOT EXISTS sales_returns (
237:     id INT AUTO_INCREMENT PRIMARY KEY,
238:     return_number VARCHAR(40) NOT NULL UNIQUE,
239:     invoice_id INT NOT NULL,
240:     customer_id INT NULL,
241:     return_date DATE NOT NULL,
242:     subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
243:     discount DECIMAL(14,2) NOT NULL DEFAULT 0,
244:     tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
245:     tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
246:     payable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
247:     note TEXT NULL,
248:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
249:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
250:     KEY idx_sr_invoice (invoice_id),
251:     KEY idx_sr_customer (customer_id),
252:     KEY idx_sr_date (return_date),
253:     CONSTRAINT fk_sr_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
254:     CONSTRAINT fk_sr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
255: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
256: $mysqli->query($createSalesReturns);
257: 
258: /* --- اقلام برگشت فروش (snapshot بهای فاکتور اصلی) --- */
259: $createSalesReturnItems = "CREATE TABLE IF NOT EXISTS sales_return_items (
260:     id INT AUTO_INCREMENT PRIMARY KEY,
261:     return_id INT NOT NULL,
262:     invoice_item_id INT NULL,
263:     product_id INT NOT NULL,
264:     unit_id INT NULL,
265:     unit_name VARCHAR(50) NULL,
266:     conversion_factor DECIMAL(14,4) NULL,
267:     quantity DECIMAL(14,2) NOT NULL,
268:     base_quantity DECIMAL(14,2) NOT NULL,
269:     unit_price DECIMAL(14,2) NOT NULL,
270:     discount DECIMAL(14,2) NOT NULL DEFAULT 0,
271:     line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
272:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
273:     KEY idx_sri_return (return_id),
274:     KEY idx_sri_product (product_id),
275:     KEY idx_sri_invoice_item (invoice_item_id),
276:     CONSTRAINT fk_sri_return FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
277:     CONSTRAINT fk_sri_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
278:     CONSTRAINT chk_sri_quantity CHECK (quantity > 0),
279:     CONSTRAINT chk_sri_base_quantity CHECK (base_quantity > 0),
280:     CONSTRAINT chk_sri_unit_price CHECK (unit_price >= 0),
281:     CONSTRAINT chk_sri_discount CHECK (discount >= 0)
282: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
283: $mysqli->query($createSalesReturnItems);
284: 
285: /* اطمینان از وجود سری شماره برگشت فروش در شمارنده اتمیک پروژه */
286: $mysqli->query("INSERT IGNORE INTO invoice_sequences (seq_key, current_value) VALUES ('sales_return', 0)");
287: 
288: /* --- گردش موجودی (Audit Trail دائمی) --- */
289: $createStockMovements = "CREATE TABLE IF NOT EXISTS stock_movements (
290:     id INT AUTO_INCREMENT PRIMARY KEY,
291:     product_id INT NOT NULL,
292:     invoice_id INT NULL,
293:     sales_return_id INT NULL,
294:     type VARCHAR(30) NOT NULL,
295:     direction ENUM('in','out') NOT NULL,
296:     quantity DECIMAL(14,2) NOT NULL,
297:     stock_before DECIMAL(14,2) NOT NULL,
298:     stock_after DECIMAL(14,2) NOT NULL,
299:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
300:     KEY idx_sm_product (product_id),
301:     KEY idx_sm_invoice (invoice_id),
302:     KEY idx_sm_sales_return (sales_return_id),
303:     KEY idx_sm_type (type),
304:     KEY idx_sm_created (created_at),
305:     CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
306:     CONSTRAINT fk_sm_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
307: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
308: $mysqli->query($createStockMovements);
309: /* ============================================================
310:  * Sticky Notes
311:  * ========================================================== */
312: 
313: $createNotes = "CREATE TABLE IF NOT EXISTS notes (
314:     id INT AUTO_INCREMENT PRIMARY KEY,
315:     title VARCHAR(150) NOT NULL DEFAULT '',
316:     content TEXT NOT NULL,
317:     color VARCHAR(30) NOT NULL DEFAULT '#fff3a3',
318:     pos_x INT NOT NULL DEFAULT 30,
319:     pos_y INT NOT NULL DEFAULT 30,
320:     z_index INT NOT NULL DEFAULT 1,
321:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
322:     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
323:     KEY idx_notes_z_index (z_index),
324:     KEY idx_notes_created (created_at)
325: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
326: 
327: $mysqli->query($createNotes);
328: 
329: /* ============================================================
330:  * Multi-Unit Inventory — Schema Migration (idempotent)
331:  * ========================================================== */
332: 
333: /**
334:  * بررسی وجود ستون در جدول (از information_schema)
335:  */
336: /**
337:  * اجرای مهاجرت چندواحدی:
338:  *  - ستون‌های snapshot در invoice_items
339:  *  - ستون‌های snapshot در stock_movements
340:  *  - ایندکس‌های کمکی
341:  *  - backfill واحد پایه برای محصولات موجود (ضریب ۱)
342:  *  - backfill اطلاعات snapshot برای اقلام فاکتورهای قدیمی
343:  */
344: function migrate_multi_unit_schema(mysqli $mysqli): void
345: {
346:     /* --- ستون‌های snapshot اقلام فاکتور --- */
347:     db_ensure_column($mysqli, 'invoice_items', 'unit_id', "unit_id INT NULL AFTER product_id");
348:     db_ensure_column($mysqli, 'invoice_items', 'unit_name', "unit_name VARCHAR(50) NULL AFTER unit_id");
349:     db_ensure_column($mysqli, 'invoice_items', 'conversion_factor', "conversion_factor DECIMAL(14,4) NULL AFTER unit_name");
350:     db_ensure_column($mysqli, 'invoice_items', 'base_quantity', "base_quantity DECIMAL(14,2) NULL AFTER quantity");
351: 
352:     if (!db_index_exists($mysqli, 'invoice_items', 'idx_ii_unit')) {
353:         $mysqli->query('ALTER TABLE invoice_items ADD KEY idx_ii_unit (unit_id)');
354:     }
355: 
356:     /* --- ستون‌های snapshot گردش موجودی --- */
357:     db_ensure_column($mysqli, 'stock_movements', 'unit_name', "unit_name VARCHAR(50) NULL AFTER type");
358:     db_ensure_column($mysqli, 'stock_movements', 'transaction_quantity', "transaction_quantity DECIMAL(14,2) NULL AFTER quantity");
359:     db_ensure_column($mysqli, 'stock_movements', 'conversion_factor', "conversion_factor DECIMAL(14,4) NULL AFTER transaction_quantity");
360: 
361:     /* --- اطمینان از وجود واحد پایه برای همه کالاها (با ضریب ۱) --- */
362:     $needBase = 0;
363:     $stmt = $mysqli->query(
364:         'SELECT COUNT(*) AS c FROM products p
365:          WHERE NOT EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id AND pu.is_base = 1)'
366:     );
367:     if ($stmt) {
368:         $row = $stmt->fetch_assoc();
369:         $needBase = (int) ($row['c'] ?? 0);
370:         $stmt->free();
371:     }
372:     if ($needBase > 0) {
373:         $mysqli->query(
374:             'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
375:              SELECT p.id, p.unit, 1, 1, p.purchase_price, p.sale_price, p.barcode, 0
376:              FROM products p
377:              WHERE NOT EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id AND pu.is_base = 1)'
378:         );
379:     }
380: 
381:     /* --- همگام‌سازی products.unit با نام واحد پایه (در صورت تغییر) --- */
382:     $mysqli->query(
383:         'UPDATE products p
384:          JOIN product_units pu ON pu.product_id = p.id AND pu.is_base = 1
385:          SET p.unit = pu.name
386:          WHERE p.unit COLLATE utf8mb4_unicode_ci <> pu.name'
387:     );
388: 
389:     /* --- backfill اطلاعات snapshot اقلام قدیمی (unit_name/factor/base_quantity) --- */
390:     $missingSnap = 0;
391:     $stmt = $mysqli->query('SELECT COUNT(*) AS c FROM invoice_items WHERE unit_id IS NULL');
392:     if ($stmt) {
393:         $row = $stmt->fetch_assoc();
394:         $missingSnap = (int) ($row['c'] ?? 0);
395:         $stmt->free();
396:     }
397:     if ($missingSnap > 0) {
398:         $mysqli->query(
399:             'UPDATE invoice_items ii
400:              LEFT JOIN products p ON p.id = ii.product_id
401:              LEFT JOIN product_units pu ON pu.product_id = p.id AND pu.is_base = 1
402:              SET ii.unit_id = pu.id,
403:                  ii.unit_name = COALESCE(pu.name, p.unit),
404:                  ii.conversion_factor = 1,
405:                  ii.base_quantity = ii.quantity
406:              WHERE ii.unit_id IS NULL'
407:         );
408:     }
409: }
410: 
411: migrate_multi_unit_schema($mysqli);
412: 
413: /* ============================================================
414:  * Sales Return — Schema Migration (idempotent)
415:  * ========================================================== */
416: 
417: /**
418:  * افزودن ستون sales_return_id به stock_movements برای پیوند گردش موجودی
419:  * به سند برگشت فروش (به‌جای invoice_id که فقط به invoices اشاره می‌کند).
420:  * این تغییر صرفاً افزایشی است و رفتار ستون‌های موجود را تغییر نمی‌دهد.
421:  */
422: function migrate_sales_return_schema(mysqli $mysqli): void
423: {
424:     db_ensure_column($mysqli, 'stock_movements', 'sales_return_id', "sales_return_id INT NULL AFTER invoice_id");
425:     if (!db_index_exists($mysqli, 'stock_movements', 'idx_sm_sales_return')) {
426:         $mysqli->query('ALTER TABLE stock_movements ADD KEY idx_sm_sales_return (sales_return_id)');
427:     }
428: }
429: 
430: migrate_sales_return_schema($mysqli);
431: 
432: /* ============================================================
433:  * Defective Goods — Schema Migration (idempotent)
434:  * ========================================================== */
435: 
436: $createDefectiveGoods = "CREATE TABLE IF NOT EXISTS defective_goods (
437:     id INT AUTO_INCREMENT PRIMARY KEY,
438:     product_id INT NOT NULL,
439:     quantity DECIMAL(14,2) NOT NULL,
440:     defective_date DATE NOT NULL,
441:     reason VARCHAR(500) NOT NULL,
442:     description TEXT DEFAULT NULL,
443:     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
444:     KEY idx_dg_product (product_id),
445:     KEY idx_dg_date (defective_date),
446:     KEY idx_dg_created (created_at),
447:     CONSTRAINT fk_dg_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
448:     CONSTRAINT chk_dg_quantity CHECK (quantity > 0)
449: ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
450: 
451: $mysqli->query($createDefectiveGoods);
452: 
453: /* ============================================================
454:  * Product Bundling / Bundle Sales — Schema (idempotent, additive)
455:  * ============================================================
456:  *  - products.product_kind discriminator (standard | bundle)
457:  *  - bundle_components: current composition of each bundle
458:  *  - invoice_bundle_items: historical snapshot of the composition
459:  *    that belonged to a specific invoice line at sale time
460:  *
461:  * هیچ‌کدام از این جداول، رفتار جداول موجود را تغییر نمی‌دهند و
462:  * ایجاد آن‌ها idempotent است (CREATE TABLE IF NOT EXISTS + db_ensure_column).
463:  * ========================================================== */
464: 
465: function migrate_product_bundling_schema(mysqli $mysqli): void
466: {
467:     /* --- ستون discriminator روی products (برای نصب‌های موجود) --- */
468:     db_ensure_column(
469:         $mysqli,
470:         'products',
471:         'product_kind',
472:         "product_kind ENUM('standard','bundle') NOT NULL DEFAULT 'standard' AFTER type"
473:     );
474:     if (!db_index_exists($mysqli, 'products', 'idx_products_kind')) {
475:         $mysqli->query('ALTER TABLE products ADD KEY idx_products_kind (product_kind)');
476:     }
477: 
478:     /* --- ترکیب فعلی بسته‌ها --- */
479:     $createBundleComponents = "CREATE TABLE IF NOT EXISTS bundle_components (
480:         id INT AUTO_INCREMENT PRIMARY KEY,
481:         bundle_product_id INT NOT NULL,
482:         component_product_id INT NOT NULL,
483:         quantity DECIMAL(14,2) NOT NULL,
484:         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
485:         updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
486:         UNIQUE KEY uq_bundle_component (bundle_product_id, component_product_id),
487:         KEY idx_bc_bundle (bundle_product_id),
488:         KEY idx_bc_component (component_product_id),
489:         CONSTRAINT fk_bc_bundle FOREIGN KEY (bundle_product_id) REFERENCES products(id) ON DELETE RESTRICT,
490:         CONSTRAINT fk_bc_component FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE RESTRICT,
491:         CONSTRAINT chk_bc_quantity CHECK (quantity > 0)
492:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
493:     $mysqli->query($createBundleComponents);
494: 
495:     /* --- snapshot تاریخی ترکیب بسته در هر ردیف فاکتور --- */
496:     $createInvoiceBundleItems = "CREATE TABLE IF NOT EXISTS invoice_bundle_items (
497:         id INT AUTO_INCREMENT PRIMARY KEY,
498:         invoice_item_id INT NOT NULL,
499:         bundle_product_id INT NOT NULL,
500:         component_product_id INT NOT NULL,
501:         component_quantity DECIMAL(14,2) NOT NULL,
502:         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
503:         KEY idx_ibi_invoice_item (invoice_item_id),
504:         KEY idx_ibi_bundle (bundle_product_id),
505:         KEY idx_ibi_component (component_product_id),
506:         CONSTRAINT fk_ibi_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE CASCADE,
507:         CONSTRAINT fk_ibi_bundle FOREIGN KEY (bundle_product_id) REFERENCES products(id) ON DELETE RESTRICT,
508:         CONSTRAINT fk_ibi_component FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE RESTRICT,
509:         CONSTRAINT chk_ibi_quantity CHECK (component_quantity > 0)
510:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
511:     $mysqli->query($createInvoiceBundleItems);
512: }
513: 
514: migrate_product_bundling_schema($mysqli);
515: 
516: /* ============================================================
517:  * Authentication & Account Security — Schema (additive, idempotent)
518:  * ============================================================
519:  *  - users          : حساب‌های کاربری (مدیر) با رمز hashشده
520:  *  - auth_attempts  : پایدار (DB) ضد حمله‌ی جستجوی رمز / سوءاستفاده
521:  *  - auth_settings  : تنظیمات کپچا + سیاست قفل (ردیف id=1 مثل سایر تنظیمات)
522:  */
523: 
524: function migrate_auth_schema(mysqli $mysqli): void
525: {
526:     $createUsers = "CREATE TABLE IF NOT EXISTS users (
527:         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
528:         username VARCHAR(64) NOT NULL,
529:         first_name VARCHAR(120) NOT NULL DEFAULT '',
530:         last_name VARCHAR(120) NOT NULL DEFAULT '',
531:         password_hash VARCHAR(255) NOT NULL,
532:         avatar_path VARCHAR(255) DEFAULT NULL,
533:         is_active TINYINT(1) NOT NULL DEFAULT 1,
534:         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
535:         updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
536:         UNIQUE KEY uq_users_username (username),
537:         KEY idx_users_active (is_active)
538:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
539:     $mysqli->query($createUsers);
540: 
541:     $createAuthAttempts = "CREATE TABLE IF NOT EXISTS auth_attempts (
542:         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
543:         bucket VARCHAR(96) NOT NULL,
544:         bucket_key VARCHAR(96) NOT NULL,
545:         outcome VARCHAR(16) NOT NULL DEFAULT 'fail',
546:         attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
547:         KEY idx_auth_attempts_lookup (bucket, bucket_key, attempted_at),
548:         KEY idx_auth_attempts_cleanup (attempted_at)
549:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
550:     $mysqli->query($createAuthAttempts);
551: 
552:     $createAuthSettings = "CREATE TABLE IF NOT EXISTS auth_settings (
553:         id INT PRIMARY KEY,
554:         registration_locked TINYINT(1) NOT NULL DEFAULT 0,
555:         captcha_provider VARCHAR(40) NOT NULL DEFAULT 'pure',
556:         login_captcha_enabled TINYINT(1) NOT NULL DEFAULT 0,
557:         registration_captcha_enabled TINYINT(1) NOT NULL DEFAULT 0,
558:         captcha_lang VARCHAR(8) NOT NULL DEFAULT 'fa',
559:         captcha_char_type VARCHAR(20) NOT NULL DEFAULT 'mixed',
560:         captcha_length INT NOT NULL DEFAULT 5,
561:         captcha_width INT NOT NULL DEFAULT 170,
562:         captcha_height INT NOT NULL DEFAULT 55,
563:         captcha_font_size INT NOT NULL DEFAULT 22,
564:         captcha_noise_level VARCHAR(20) NOT NULL DEFAULT 'medium',
565:         captcha_case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
566:         captcha_expiry INT NOT NULL DEFAULT 180,
567:         brute_max_attempts INT NOT NULL DEFAULT 5,
568:         brute_window_minutes INT NOT NULL DEFAULT 15,
569:         brute_lockout_minutes INT NOT NULL DEFAULT 15,
570:         updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
571:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
572:     $mysqli->query($createAuthSettings);
573: 
574:     /*
575:      * ردیف تنظیمات همیشه باید موجود باشد تا تراکنش ثبت‌نام اولیه بتواند
576:      * آن را با `SELECT ... FOR UPDATE` قفل کند (جلوگیری از ثبت‌نام همزمان).
577:      */
578:     $stmt = $mysqli->prepare(
579:         'INSERT INTO auth_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id'
580:     );
581:     $stmt->execute();
582:     $stmt->close();
583: }
584: 
585: migrate_auth_schema($mysqli);
586: 
587: 
588: /* ============================================================
589:  * Product Media Library — additive, repeatable migration
590:  * The original product/financial tables are deliberately unchanged.
591:  * ========================================================== */
592: function migrate_product_media_schema(mysqli $mysqli): void
593: {
594:     $mysqli->query("CREATE TABLE IF NOT EXISTS media_folders (
595:         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
596:         name VARCHAR(140) NOT NULL UNIQUE,
597:         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
598:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
599:     $mysqli->query("CREATE TABLE IF NOT EXISTS media (
600:         id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
601:         original_filename VARCHAR(255) NOT NULL,
602:         stored_filename VARCHAR(100) NOT NULL UNIQUE,
603:         original_path VARCHAR(255) NOT NULL,
604:         thumbnail_path VARCHAR(255) NOT NULL,
605:         mime_type VARCHAR(40) NOT NULL,
606:         file_size INT UNSIGNED NOT NULL,
607:         width SMALLINT UNSIGNED NOT NULL,
608:         height SMALLINT UNSIGNED NOT NULL,
609:         sha256 CHAR(64) NOT NULL UNIQUE,
610:         uploaded_by INT UNSIGNED DEFAULT NULL,
611:         folder_id INT UNSIGNED NULL,
612:         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
613:         INDEX idx_media_created (created_at),
614:         INDEX idx_media_folder (folder_id, id),
615:         CONSTRAINT fk_media_folder FOREIGN KEY (folder_id) REFERENCES media_folders(id) ON DELETE RESTRICT
616:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
617: 
618:     // Existing installations already have media; migrate in-place without data loss.
619:     db_ensure_column($mysqli, 'media', 'folder_id', 'folder_id INT UNSIGNED NULL AFTER uploaded_by');
620:     if (!db_index_exists($mysqli, 'media', 'idx_media_folder')) {
621:         $mysqli->query('ALTER TABLE media ADD KEY idx_media_folder (folder_id, id)');
622:     }
623:     $fk = $mysqli->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
624:         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media'
625:           AND CONSTRAINT_NAME = 'fk_media_folder' AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1");
626:     if ($fk && $fk->num_rows === 0) {
627:         $mysqli->query('ALTER TABLE media ADD CONSTRAINT fk_media_folder
628:             FOREIGN KEY (folder_id) REFERENCES media_folders(id) ON DELETE RESTRICT');
629:     }
630:     if ($fk) $fk->free();
631: 
632:     $mysqli->query("CREATE TABLE IF NOT EXISTS product_media (
633:         product_id INT NOT NULL,
634:         media_id INT UNSIGNED NOT NULL,
635:         sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
636:         PRIMARY KEY (product_id, media_id),
637:         KEY idx_pm_product_order (product_id, sort_order),
638:         KEY idx_pm_media (media_id),
639:         CONSTRAINT fk_pm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
640:         CONSTRAINT fk_pm_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT
641:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
642: 
643:     $mysqli->query("CREATE TABLE IF NOT EXISTS category_media (
644:         category_id INT NOT NULL PRIMARY KEY,
645:         media_id INT UNSIGNED NOT NULL,
646:         CONSTRAINT fk_cm_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE,
647:         CONSTRAINT fk_cm_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT
648:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
649: 
650:     // A defective registration has its own photos; never overwrite product photos.
651:     $mysqli->query("CREATE TABLE IF NOT EXISTS defective_media (
652:         defective_id INT NOT NULL,
653:         media_id INT UNSIGNED NOT NULL,
654:         sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
655:         PRIMARY KEY (defective_id, media_id),
656:         INDEX idx_defective_media_order (defective_id, sort_order),
657:         CONSTRAINT fk_defective_media_entry FOREIGN KEY (defective_id) REFERENCES defective_goods(id) ON DELETE CASCADE,
658:         CONSTRAINT fk_defective_media_file FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT
659:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
660: 
661:     $mysqli->query("CREATE TABLE IF NOT EXISTS media_display_settings (
662:         id INT PRIMARY KEY,
663:         products_list TINYINT(1) NOT NULL DEFAULT 1,
664:         sales_ui TINYINT(1) NOT NULL DEFAULT 1,
665:         purchase_ui TINYINT(1) NOT NULL DEFAULT 1,
666:         sales_returns_ui TINYINT(1) NOT NULL DEFAULT 1,
667:         defective_ui TINYINT(1) NOT NULL DEFAULT 1,
668:         reports_ui TINYINT(1) NOT NULL DEFAULT 0,
669:         category_images TINYINT(1) NOT NULL DEFAULT 1,
670:         pdf_sales TINYINT(1) NOT NULL DEFAULT 0,
671:         pdf_sales_proforma TINYINT(1) NOT NULL DEFAULT 0,
672:         pdf_purchase TINYINT(1) NOT NULL DEFAULT 0,
673:         pdf_purchase_proforma TINYINT(1) NOT NULL DEFAULT 0,
674:         pdf_sales_return TINYINT(1) NOT NULL DEFAULT 0,
675:         updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
676:     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
677:     $mysqli->query("INSERT IGNORE INTO media_display_settings (id) VALUES (1)");
678: }
679: migrate_product_media_schema($mysqli);
680: require_once __DIR__ . '/accounting_ledger_migration.php';
681: migrate_accounting_ledger($mysqli);
682: migrate_accounting_auto_settlement($mysqli);
683: 
684: 
685: // Additive retry protection: historical rows remain NULL.
686: db_ensure_column($mysqli, 'sales_returns', 'client_token', 'client_token VARCHAR(64) NULL');
687: db_ensure_column($mysqli, 'sales_returns', 'request_hash', 'request_hash CHAR(64) NULL');
688: if (!db_index_exists($mysqli, 'sales_returns', 'uq_sr_client_token')) {
689:     $mysqli->query('ALTER TABLE sales_returns ADD UNIQUE KEY uq_sr_client_token (client_token)');
690: }
```

### assets/php/db_schema_helpers.php

```text
1: <?php
2: function db_column_exists(mysqli $mysqli, string $table, string $column): bool
3: {
4:     $stmt = $mysqli->prepare(
5:         'SELECT COUNT(*) AS c FROM information_schema.columns
6:          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
7:     );
8:     $stmt->bind_param('ss', $table, $column);
9:     $stmt->execute();
10:     $res = $stmt->get_result();
11:     $stmt->close();
12:     $row = $res ? $res->fetch_assoc() : null;
13:     return $row !== null && (int) $row['c'] > 0;
14: }
15: 
16: /**
17:  * بررسی وجود ایندکس در جدول
18:  */
19: function db_index_exists(mysqli $mysqli, string $table, string $index): bool
20: {
21:     $stmt = $mysqli->prepare(
22:         'SELECT COUNT(*) AS c FROM information_schema.statistics
23:          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
24:     );
25:     $stmt->bind_param('ss', $table, $index);
26:     $stmt->execute();
27:     $res = $stmt->get_result();
28:     $stmt->close();
29:     $row = $res ? $res->fetch_assoc() : null;
30:     return $row !== null && (int) $row['c'] > 0;
31: }
32: 
33: /**
34:  * افزودن ستون در صورت نبودن (idempotent)
35:  */
36: function db_ensure_column(mysqli $mysqli, string $table, string $column, string $definition): bool
37: {
38:     if (db_column_exists($mysqli, $table, $column)) {
39:         return true;
40:     }
41:     $sql = "ALTER TABLE `{$table}` ADD COLUMN {$definition}";
42:     return (bool) $mysqli->query($sql);
43: }
44: 
```

### tools/migrate-database.php

```text
1: <?php
2: // Run with the site's PHP CLI, during a maintenance window, after a backup.
3: if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
4: define('MEMAR_RUN_SCHEMA', true);
5: require_once __DIR__ . '/../assets/php/db.php';
6: $lockName = 'memar_schema_' . substr(hash('sha256', $dbName), 0, 32);
7: $stmt = $mysqli->prepare('SELECT GET_LOCK(?, 10)');
8: $stmt->bind_param('s', $lockName); $stmt->execute();
9: if ((int)$stmt->get_result()->fetch_row()[0] !== 1) { throw new RuntimeException('Migration already running'); }
10: try {
11:     require __DIR__ . '/../assets/php/db_schema.php';
12:     echo "Core schema migration completed.\n";
13: } finally {
14:     $stmt = $mysqli->prepare('SELECT RELEASE_LOCK(?)');
15:     $stmt->bind_param('s', $lockName); $stmt->execute();
16:     $mysqli->close();
17: }
```
