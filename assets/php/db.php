<?php
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = '';
$dbName = 'memar_accounting';

$mysqli = new mysqli($dbHost, $dbUser, $dbPass);
if ($mysqli->connect_errno) {
    die('خطا در اتصال به دیتابیس: ' . $mysqli->connect_error);
}

$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$mysqli->select_db($dbName);
$mysqli->set_charset('utf8mb4');

$createCustomers = "CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    national_code VARCHAR(20) DEFAULT NULL,
    phone VARCHAR(40) NOT NULL,
    economic_code VARCHAR(80) DEFAULT NULL,
    registration_number VARCHAR(80) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    postal_code VARCHAR(40) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    total_spent DECIMAL(14,2) NOT NULL DEFAULT 0,
    debt DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$mysqli->query($createCustomers);

$createSuppliers = "CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) DEFAULT NULL,
    first_name VARCHAR(120) DEFAULT NULL,
    last_name VARCHAR(120) DEFAULT NULL,
    national_code VARCHAR(20) DEFAULT NULL,
    phone VARCHAR(40) NOT NULL,
    economic_code VARCHAR(80) DEFAULT NULL,
    registration_number VARCHAR(80) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    postal_code VARCHAR(40) DEFAULT NULL,
    note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$mysqli->query($createSuppliers);

$createCategories = "CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    parent_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$mysqli->query($createCategories);

$createProducts = "CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    barcode VARCHAR(100) NULL,
    name VARCHAR(150) NOT NULL,
    category_id INT DEFAULT NULL,
    type ENUM('product','service') NOT NULL DEFAULT 'product',
    product_kind ENUM('standard','bundle') NOT NULL DEFAULT 'standard',
    unit VARCHAR(50) NOT NULL DEFAULT 'عدد',
    purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    stock DECIMAL(14,2) NOT NULL DEFAULT 0,
    min_stock DECIMAL(14,2) NOT NULL DEFAULT 0,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_barcode (barcode),
    FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$mysqli->query($createProducts);

/* --- واحدهای اندازه‌گیری هر کالا (Multi-Unit Inventory) --- */
$createProductUnits = "CREATE TABLE IF NOT EXISTS product_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    conversion_factor DECIMAL(14,4) NOT NULL DEFAULT 1,
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    barcode VARCHAR(100) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pu_product_name (product_id, name),
    KEY idx_pu_product (product_id),
    CONSTRAINT fk_pu_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$mysqli->query($createProductUnits);

$createStoreSettings = "CREATE TABLE IF NOT EXISTS store_settings (
    id INT PRIMARY KEY,
    store_name VARCHAR(255) NOT NULL,
    economic_code VARCHAR(80) DEFAULT NULL,
    national_code VARCHAR(80) DEFAULT NULL,
    registration_number VARCHAR(80) DEFAULT NULL,
    province_id INT DEFAULT NULL,
    city_id INT DEFAULT NULL,
    postal_code VARCHAR(40) DEFAULT NULL,
    phone VARCHAR(60) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    signature_path VARCHAR(255) DEFAULT NULL,
    stamp_path VARCHAR(255) DEFAULT NULL,
    default_size_percentage INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$mysqli->query($createStoreSettings);

$createInvoiceSettings = "CREATE TABLE IF NOT EXISTS invoice_settings (
    id INT PRIMARY KEY,
    unofficial_invoice_desc TEXT DEFAULT NULL,
    official_invoice_desc TEXT DEFAULT NULL,
    proforma_desc TEXT DEFAULT NULL,
    proforma_title VARCHAR(255) DEFAULT NULL,
    invoice_template_color VARCHAR(20) DEFAULT NULL,
    official_invoice_direction VARCHAR(20) DEFAULT 'vertical',
    unofficial_invoice_direction VARCHAR(20) DEFAULT 'vertical',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$mysqli->query($createInvoiceSettings);

$createTaxSettings = "CREATE TABLE IF NOT EXISTS tax_settings (
    id INT PRIMARY KEY,
    tax_enabled TINYINT(1) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$mysqli->query($createTaxSettings);

$createDbBackupSettings = "CREATE TABLE IF NOT EXISTS db_backup_settings (
    id INT PRIMARY KEY,
    backup_dir VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$mysqli->query($createDbBackupSettings);

/* ============================================================
 * ماژول فاکتور فروش/خرید — جداول جدید (Invoice Module)
 * ========================================================== */

/* --- شمارنده اتمیک شماره فاکتور --- */
$createInvoiceSequences = "CREATE TABLE IF NOT EXISTS invoice_sequences (
    seq_key VARCHAR(40) PRIMARY KEY,
    current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$mysqli->query($createInvoiceSequences);

/* اطمینان از وجود ۴ سری شماره */
$mysqli->query("INSERT IGNORE INTO invoice_sequences (seq_key, current_value) VALUES
    ('sales_invoice', 0),
    ('sales_proforma', 0),
    ('purchase_invoice', 0),
    ('purchase_proforma', 0)");

/* --- فاکتورها (فروش/خرید/پیش‌فاکتور) --- */
$createInvoices = "CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(40) NOT NULL UNIQUE,
    type ENUM('sales_invoice','sales_proforma','purchase_invoice','purchase_proforma') NOT NULL,
    customer_id INT NULL,
    supplier_id INT NULL,
    payment_type ENUM('cash','pos','bank_transfer') NOT NULL DEFAULT 'pos',
    payment_status ENUM('paid','unpaid','partial') NOT NULL DEFAULT 'unpaid',
    invoice_date DATE NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    payable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    client_token VARCHAR(64) NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_inv_type (type),
    KEY idx_inv_customer (customer_id),
    KEY idx_inv_supplier (supplier_id),
    KEY idx_inv_date (invoice_date),
    CONSTRAINT fk_inv_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
    CONSTRAINT chk_inv_party CHECK (
        (type LIKE 'sales%' AND customer_id IS NOT NULL AND supplier_id IS NULL)
        OR
        (type LIKE 'purchase%' AND customer_id IS NULL AND supplier_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$mysqli->query($createInvoices);

/* --- اقلام فاکتور --- */
$createInvoiceItems = "CREATE TABLE IF NOT EXISTS invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(14,2) NOT NULL,
    unit_price DECIMAL(14,2) NOT NULL,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ii_invoice (invoice_id),
    KEY idx_ii_product (product_id),
    CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_ii_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT chk_ii_quantity CHECK (quantity > 0),
    CONSTRAINT chk_ii_unit_price CHECK (unit_price >= 0),
    CONSTRAINT chk_ii_discount CHECK (discount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$mysqli->query($createInvoiceItems);

/* ============================================================
 * برگشت فروش — جداول سند برگشت (Sales Return Module)
 * سند برگشت مستقل از فاکتور اصلی است و فاکتور اصلی دست‌نخورده می‌ماند.
 * ========================================================== */

/* --- سندهای برگشت فروش --- */
$createSalesReturns = "CREATE TABLE IF NOT EXISTS sales_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(40) NOT NULL UNIQUE,
    invoice_id INT NOT NULL,
    customer_id INT NULL,
    return_date DATE NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    payable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sr_invoice (invoice_id),
    KEY idx_sr_customer (customer_id),
    KEY idx_sr_date (return_date),
    CONSTRAINT fk_sr_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$mysqli->query($createSalesReturns);

/* --- اقلام برگشت فروش (snapshot بهای فاکتور اصلی) --- */
$createSalesReturnItems = "CREATE TABLE IF NOT EXISTS sales_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    invoice_item_id INT NULL,
    product_id INT NOT NULL,
    unit_id INT NULL,
    unit_name VARCHAR(50) NULL,
    conversion_factor DECIMAL(14,4) NULL,
    quantity DECIMAL(14,2) NOT NULL,
    base_quantity DECIMAL(14,2) NOT NULL,
    unit_price DECIMAL(14,2) NOT NULL,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sri_return (return_id),
    KEY idx_sri_product (product_id),
    KEY idx_sri_invoice_item (invoice_item_id),
    CONSTRAINT fk_sri_return FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
    CONSTRAINT fk_sri_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT chk_sri_quantity CHECK (quantity > 0),
    CONSTRAINT chk_sri_base_quantity CHECK (base_quantity > 0),
    CONSTRAINT chk_sri_unit_price CHECK (unit_price >= 0),
    CONSTRAINT chk_sri_discount CHECK (discount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$mysqli->query($createSalesReturnItems);

/* اطمینان از وجود سری شماره برگشت فروش در شمارنده اتمیک پروژه */
$mysqli->query("INSERT IGNORE INTO invoice_sequences (seq_key, current_value) VALUES ('sales_return', 0)");

/* --- گردش موجودی (Audit Trail دائمی) --- */
$createStockMovements = "CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    invoice_id INT NULL,
    sales_return_id INT NULL,
    type VARCHAR(30) NOT NULL,
    direction ENUM('in','out') NOT NULL,
    quantity DECIMAL(14,2) NOT NULL,
    stock_before DECIMAL(14,2) NOT NULL,
    stock_after DECIMAL(14,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sm_product (product_id),
    KEY idx_sm_invoice (invoice_id),
    KEY idx_sm_sales_return (sales_return_id),
    KEY idx_sm_type (type),
    KEY idx_sm_created (created_at),
    CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sm_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
$mysqli->query($createStockMovements);
/* ============================================================
 * Sticky Notes
 * ========================================================== */

$createNotes = "CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    color VARCHAR(30) NOT NULL DEFAULT '#fff3a3',
    pos_x INT NOT NULL DEFAULT 30,
    pos_y INT NOT NULL DEFAULT 30,
    z_index INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_notes_z_index (z_index),
    KEY idx_notes_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$mysqli->query($createNotes);

/* ============================================================
 * Multi-Unit Inventory — Schema Migration (idempotent)
 * ========================================================== */

/**
 * بررسی وجود ستون در جدول (از information_schema)
 */
function db_column_exists(mysqli $mysqli, string $table, string $column): bool
{
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $row = $res ? $res->fetch_assoc() : null;
    return $row !== null && (int) $row['c'] > 0;
}

/**
 * بررسی وجود ایندکس در جدول
 */
function db_index_exists(mysqli $mysqli, string $table, string $index): bool
{
    $stmt = $mysqli->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    $row = $res ? $res->fetch_assoc() : null;
    return $row !== null && (int) $row['c'] > 0;
}

/**
 * افزودن ستون در صورت نبودن (idempotent)
 */
function db_ensure_column(mysqli $mysqli, string $table, string $column, string $definition): bool
{
    if (db_column_exists($mysqli, $table, $column)) {
        return true;
    }
    $sql = "ALTER TABLE `{$table}` ADD COLUMN {$definition}";
    return (bool) $mysqli->query($sql);
}

/**
 * اجرای مهاجرت چندواحدی:
 *  - ستون‌های snapshot در invoice_items
 *  - ستون‌های snapshot در stock_movements
 *  - ایندکس‌های کمکی
 *  - backfill واحد پایه برای محصولات موجود (ضریب ۱)
 *  - backfill اطلاعات snapshot برای اقلام فاکتورهای قدیمی
 */
function migrate_multi_unit_schema(mysqli $mysqli): void
{
    /* --- ستون‌های snapshot اقلام فاکتور --- */
    db_ensure_column($mysqli, 'invoice_items', 'unit_id', "unit_id INT NULL AFTER product_id");
    db_ensure_column($mysqli, 'invoice_items', 'unit_name', "unit_name VARCHAR(50) NULL AFTER unit_id");
    db_ensure_column($mysqli, 'invoice_items', 'conversion_factor', "conversion_factor DECIMAL(14,4) NULL AFTER unit_name");
    db_ensure_column($mysqli, 'invoice_items', 'base_quantity', "base_quantity DECIMAL(14,2) NULL AFTER quantity");

    if (!db_index_exists($mysqli, 'invoice_items', 'idx_ii_unit')) {
        $mysqli->query('ALTER TABLE invoice_items ADD KEY idx_ii_unit (unit_id)');
    }

    /* --- ستون‌های snapshot گردش موجودی --- */
    db_ensure_column($mysqli, 'stock_movements', 'unit_name', "unit_name VARCHAR(50) NULL AFTER type");
    db_ensure_column($mysqli, 'stock_movements', 'transaction_quantity', "transaction_quantity DECIMAL(14,2) NULL AFTER quantity");
    db_ensure_column($mysqli, 'stock_movements', 'conversion_factor', "conversion_factor DECIMAL(14,4) NULL AFTER transaction_quantity");

    /* --- اطمینان از وجود واحد پایه برای همه کالاها (با ضریب ۱) --- */
    $needBase = 0;
    $stmt = $mysqli->query(
        'SELECT COUNT(*) AS c FROM products p
         WHERE NOT EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id AND pu.is_base = 1)'
    );
    if ($stmt) {
        $row = $stmt->fetch_assoc();
        $needBase = (int) ($row['c'] ?? 0);
        $stmt->free();
    }
    if ($needBase > 0) {
        $mysqli->query(
            'INSERT INTO product_units (product_id, name, conversion_factor, is_base, purchase_price, sale_price, barcode, sort_order)
             SELECT p.id, p.unit, 1, 1, p.purchase_price, p.sale_price, p.barcode, 0
             FROM products p
             WHERE NOT EXISTS (SELECT 1 FROM product_units pu WHERE pu.product_id = p.id AND pu.is_base = 1)'
        );
    }

    /* --- همگام‌سازی products.unit با نام واحد پایه (در صورت تغییر) --- */
    $mysqli->query(
        'UPDATE products p
         JOIN product_units pu ON pu.product_id = p.id AND pu.is_base = 1
         SET p.unit = pu.name
         WHERE p.unit COLLATE utf8mb4_unicode_ci <> pu.name'
    );

    /* --- backfill اطلاعات snapshot اقلام قدیمی (unit_name/factor/base_quantity) --- */
    $missingSnap = 0;
    $stmt = $mysqli->query('SELECT COUNT(*) AS c FROM invoice_items WHERE unit_id IS NULL');
    if ($stmt) {
        $row = $stmt->fetch_assoc();
        $missingSnap = (int) ($row['c'] ?? 0);
        $stmt->free();
    }
    if ($missingSnap > 0) {
        $mysqli->query(
            'UPDATE invoice_items ii
             LEFT JOIN products p ON p.id = ii.product_id
             LEFT JOIN product_units pu ON pu.product_id = p.id AND pu.is_base = 1
             SET ii.unit_id = pu.id,
                 ii.unit_name = COALESCE(pu.name, p.unit),
                 ii.conversion_factor = 1,
                 ii.base_quantity = ii.quantity
             WHERE ii.unit_id IS NULL'
        );
    }
}

migrate_multi_unit_schema($mysqli);

/* ============================================================
 * Sales Return — Schema Migration (idempotent)
 * ========================================================== */

/**
 * افزودن ستون sales_return_id به stock_movements برای پیوند گردش موجودی
 * به سند برگشت فروش (به‌جای invoice_id که فقط به invoices اشاره می‌کند).
 * این تغییر صرفاً افزایشی است و رفتار ستون‌های موجود را تغییر نمی‌دهد.
 */
function migrate_sales_return_schema(mysqli $mysqli): void
{
    db_ensure_column($mysqli, 'stock_movements', 'sales_return_id', "sales_return_id INT NULL AFTER invoice_id");
    if (!db_index_exists($mysqli, 'stock_movements', 'idx_sm_sales_return')) {
        $mysqli->query('ALTER TABLE stock_movements ADD KEY idx_sm_sales_return (sales_return_id)');
    }
}

migrate_sales_return_schema($mysqli);

/* ============================================================
 * Defective Goods — Schema Migration (idempotent)
 * ========================================================== */

$createDefectiveGoods = "CREATE TABLE IF NOT EXISTS defective_goods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity DECIMAL(14,2) NOT NULL,
    defective_date DATE NOT NULL,
    reason VARCHAR(500) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dg_product (product_id),
    KEY idx_dg_date (defective_date),
    KEY idx_dg_created (created_at),
    CONSTRAINT fk_dg_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT chk_dg_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$mysqli->query($createDefectiveGoods);

/* ============================================================
 * Product Bundling / Bundle Sales — Schema (idempotent, additive)
 * ============================================================
 *  - products.product_kind discriminator (standard | bundle)
 *  - bundle_components: current composition of each bundle
 *  - invoice_bundle_items: historical snapshot of the composition
 *    that belonged to a specific invoice line at sale time
 *
 * هیچ‌کدام از این جداول، رفتار جداول موجود را تغییر نمی‌دهند و
 * ایجاد آن‌ها idempotent است (CREATE TABLE IF NOT EXISTS + db_ensure_column).
 * ========================================================== */

function migrate_product_bundling_schema(mysqli $mysqli): void
{
    /* --- ستون discriminator روی products (برای نصب‌های موجود) --- */
    db_ensure_column(
        $mysqli,
        'products',
        'product_kind',
        "product_kind ENUM('standard','bundle') NOT NULL DEFAULT 'standard' AFTER type"
    );
    if (!db_index_exists($mysqli, 'products', 'idx_products_kind')) {
        $mysqli->query('ALTER TABLE products ADD KEY idx_products_kind (product_kind)');
    }

    /* --- ترکیب فعلی بسته‌ها --- */
    $createBundleComponents = "CREATE TABLE IF NOT EXISTS bundle_components (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bundle_product_id INT NOT NULL,
        component_product_id INT NOT NULL,
        quantity DECIMAL(14,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_bundle_component (bundle_product_id, component_product_id),
        KEY idx_bc_bundle (bundle_product_id),
        KEY idx_bc_component (component_product_id),
        CONSTRAINT fk_bc_bundle FOREIGN KEY (bundle_product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_bc_component FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT chk_bc_quantity CHECK (quantity > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $mysqli->query($createBundleComponents);

    /* --- snapshot تاریخی ترکیب بسته در هر ردیف فاکتور --- */
    $createInvoiceBundleItems = "CREATE TABLE IF NOT EXISTS invoice_bundle_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_item_id INT NOT NULL,
        bundle_product_id INT NOT NULL,
        component_product_id INT NOT NULL,
        component_quantity DECIMAL(14,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ibi_invoice_item (invoice_item_id),
        KEY idx_ibi_bundle (bundle_product_id),
        KEY idx_ibi_component (component_product_id),
        CONSTRAINT fk_ibi_invoice_item FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE CASCADE,
        CONSTRAINT fk_ibi_bundle FOREIGN KEY (bundle_product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_ibi_component FOREIGN KEY (component_product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT chk_ibi_quantity CHECK (component_quantity > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $mysqli->query($createInvoiceBundleItems);
}

migrate_product_bundling_schema($mysqli);

/* ============================================================
 * Authentication & Account Security — Schema (additive, idempotent)
 * ============================================================
 *  - users          : حساب‌های کاربری (مدیر) با رمز hashشده
 *  - auth_attempts  : پایدار (DB) ضد حمله‌ی جستجوی رمز / سوءاستفاده
 *  - auth_settings  : تنظیمات کپچا + سیاست قفل (ردیف id=1 مثل سایر تنظیمات)
 */

function migrate_auth_schema(mysqli $mysqli): void
{
    $createUsers = "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL,
        first_name VARCHAR(120) NOT NULL DEFAULT '',
        last_name VARCHAR(120) NOT NULL DEFAULT '',
        password_hash VARCHAR(255) NOT NULL,
        avatar_path VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_users_username (username),
        KEY idx_users_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $mysqli->query($createUsers);

    $createAuthAttempts = "CREATE TABLE IF NOT EXISTS auth_attempts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bucket VARCHAR(96) NOT NULL,
        bucket_key VARCHAR(96) NOT NULL,
        outcome VARCHAR(16) NOT NULL DEFAULT 'fail',
        attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_auth_attempts_lookup (bucket, bucket_key, attempted_at),
        KEY idx_auth_attempts_cleanup (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $mysqli->query($createAuthAttempts);

    $createAuthSettings = "CREATE TABLE IF NOT EXISTS auth_settings (
        id INT PRIMARY KEY,
        registration_locked TINYINT(1) NOT NULL DEFAULT 0,
        captcha_provider VARCHAR(40) NOT NULL DEFAULT 'pure',
        login_captcha_enabled TINYINT(1) NOT NULL DEFAULT 0,
        registration_captcha_enabled TINYINT(1) NOT NULL DEFAULT 0,
        captcha_lang VARCHAR(8) NOT NULL DEFAULT 'fa',
        captcha_char_type VARCHAR(20) NOT NULL DEFAULT 'mixed',
        captcha_length INT NOT NULL DEFAULT 5,
        captcha_width INT NOT NULL DEFAULT 170,
        captcha_height INT NOT NULL DEFAULT 55,
        captcha_font_size INT NOT NULL DEFAULT 22,
        captcha_noise_level VARCHAR(20) NOT NULL DEFAULT 'medium',
        captcha_case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
        captcha_expiry INT NOT NULL DEFAULT 180,
        brute_max_attempts INT NOT NULL DEFAULT 5,
        brute_window_minutes INT NOT NULL DEFAULT 15,
        brute_lockout_minutes INT NOT NULL DEFAULT 15,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $mysqli->query($createAuthSettings);

    /*
     * ردیف تنظیمات همیشه باید موجود باشد تا تراکنش ثبت‌نام اولیه بتواند
     * آن را با `SELECT ... FOR UPDATE` قفل کند (جلوگیری از ثبت‌نام همزمان).
     */
    $stmt = $mysqli->prepare(
        'INSERT INTO auth_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id'
    );
    $stmt->execute();
    $stmt->close();
}

migrate_auth_schema($mysqli);
