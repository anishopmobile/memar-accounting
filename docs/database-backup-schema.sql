SET FOREIGN_KEY_CHECKS=0;
-- Structure only; no production rows.
CREATE TABLE `account_invoice_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int DEFAULT NULL,
  `invoice_number` varchar(40) NOT NULL,
  `previous_total` decimal(14,2) NOT NULL,
  `new_total` decimal(14,2) NOT NULL,
  `previous_status` varchar(20) NOT NULL,
  `new_status` varchar(20) NOT NULL,
  `performed_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_air_invoice` (`invoice_id`),
  KEY `idx_air_number` (`invoice_number`),
  CONSTRAINT `fk_air_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_legacy_reconciliations` (
  `invoice_id` int NOT NULL,
  `historical_paid_amount` decimal(14,2) NOT NULL,
  `old_payment_status` enum('paid','unpaid','partial') NOT NULL,
  `reason` text NOT NULL,
  `performed_by` int DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_id`),
  CONSTRAINT `fk_alr_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_alr_paid` CHECK ((`historical_paid_amount` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_opening_balances` (
  `party_kind` enum('customer','supplier') NOT NULL,
  `party_id` int NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `note` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`party_kind`,`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_payment_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint unsigned NOT NULL,
  `invoice_id` int NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `client_token` varchar(64) DEFAULT NULL,
  `reversed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apa_token` (`client_token`),
  KEY `idx_apa_pair` (`payment_id`,`invoice_id`),
  KEY `idx_apa_invoice` (`invoice_id`),
  CONSTRAINT `fk_apa_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_apa_payment` FOREIGN KEY (`payment_id`) REFERENCES `account_payments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_apa_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=136 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_payment_batches` (
  `token` varchar(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `party_kind` enum('customer','supplier') NOT NULL,
  `party_id` int NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `party_kind` enum('customer','supplier') NOT NULL,
  `party_id` int NOT NULL,
  `direction` enum('in','out') NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `method` enum('cash','pos','bank_transfer') NOT NULL,
  `payment_date` date NOT NULL,
  `reference_no` varchar(120) DEFAULT NULL,
  `note` text,
  `client_token` varchar(64) NOT NULL,
  `request_hash` char(64) DEFAULT NULL,
  `source_proforma_id` int DEFAULT NULL,
  `batch_token` varchar(64) DEFAULT NULL,
  `cash_account_id` int DEFAULT NULL,
  `reversed_payment_id` bigint unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_token` (`client_token`),
  UNIQUE KEY `uq_ap_reversal` (`reversed_payment_id`),
  KEY `idx_ap_account` (`cash_account_id`),
  KEY `idx_ap_proforma` (`source_proforma_id`),
  KEY `idx_ap_batch` (`batch_token`),
  KEY `idx_ap_party` (`party_kind`,`party_id`,`payment_date`),
  CONSTRAINT `fk_ap_account` FOREIGN KEY (`cash_account_id`) REFERENCES `accounting_cash_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ap_batch` FOREIGN KEY (`batch_token`) REFERENCES `account_payment_batches` (`token`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ap_proforma` FOREIGN KEY (`source_proforma_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ap_reversal` FOREIGN KEY (`reversed_payment_id`) REFERENCES `account_payments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ap_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_refund_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `refund_payment_id` bigint unsigned NOT NULL,
  `source_kind` enum('payment','return','opening') NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refund_source` (`refund_payment_id`,`source_kind`,`source_id`),
  KEY `idx_refund_source` (`source_kind`,`source_id`),
  CONSTRAINT `fk_refund_payment` FOREIGN KEY (`refund_payment_id`) REFERENCES `account_payments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_refund_source_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `account_return_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `return_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `client_token` varchar(64) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ara_token` (`client_token`),
  KEY `idx_ara_pair` (`return_id`,`invoice_id`),
  KEY `idx_ara_invoice` (`invoice_id`),
  CONSTRAINT `fk_ara_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ara_return` FOREIGN KEY (`return_id`) REFERENCES `sales_returns` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ara_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `accounting_auto_settlement_settings` (
  `id` tinyint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `customer_id` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_auto_settle_customer` (`customer_id`),
  CONSTRAINT `fk_auto_settle_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accounting_cash_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `account_type` enum('cash','pos','bank_transfer') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ac_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `accounting_migrations` (
  `migration_key` varchar(80) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `app_brand_settings` (
  `id` int NOT NULL,
  `display_mode` enum('name','logo','logo_name') NOT NULL DEFAULT 'name',
  `logo_path` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `auth_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bucket_key` varchar(96) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `outcome` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fail',
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_auth_attempts_lookup` (`bucket`,`bucket_key`,`attempted_at`),
  KEY `idx_auth_attempts_cleanup` (`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=346 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `auth_settings` (
  `id` int NOT NULL,
  `registration_locked` tinyint(1) NOT NULL DEFAULT '0',
  `captcha_provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pure',
  `login_captcha_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `registration_captcha_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `captcha_lang` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa',
  `captcha_char_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mixed',
  `captcha_length` int NOT NULL DEFAULT '5',
  `captcha_width` int NOT NULL DEFAULT '170',
  `captcha_height` int NOT NULL DEFAULT '55',
  `captcha_font_size` int NOT NULL DEFAULT '22',
  `captcha_noise_level` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `captcha_case_sensitive` tinyint(1) NOT NULL DEFAULT '0',
  `captcha_expiry` int NOT NULL DEFAULT '180',
  `brute_max_attempts` int NOT NULL DEFAULT '5',
  `brute_window_minutes` int NOT NULL DEFAULT '15',
  `brute_lockout_minutes` int NOT NULL DEFAULT '15',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bundle_components` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bundle_product_id` int NOT NULL,
  `component_product_id` int NOT NULL,
  `quantity` decimal(14,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bundle_component` (`bundle_product_id`,`component_product_id`),
  KEY `idx_bc_bundle` (`bundle_product_id`),
  KEY `idx_bc_component` (`component_product_id`),
  CONSTRAINT `fk_bc_bundle` FOREIGN KEY (`bundle_product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bc_component` FOREIGN KEY (`component_product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_bc_quantity` CHECK ((`quantity` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `category_media` (
  `category_id` int NOT NULL,
  `media_id` int unsigned NOT NULL,
  PRIMARY KEY (`category_id`),
  KEY `fk_cm_media` (`media_id`),
  CONSTRAINT `fk_cm_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `currency_settings` (
  `id` int NOT NULL,
  `currency_code` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TOMAN',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `current_affairs_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cac_active` (`is_active`),
  KEY `idx_cac_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `current_affairs_field_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `field_id` int NOT NULL,
  `option_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cafo_field` (`field_id`),
  CONSTRAINT `fk_cafo_field` FOREIGN KEY (`field_id`) REFERENCES `current_affairs_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `current_affairs_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_type` enum('text','textarea','number','money','date','select','yesno') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `show_in_table` tinyint(1) NOT NULL DEFAULT '1',
  `is_filterable` tinyint(1) NOT NULL DEFAULT '0',
  `include_in_totals` tinyint(1) NOT NULL DEFAULT '0',
  `is_reporting_date` tinyint(1) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_caf_cat_name` (`category_id`,`name`),
  UNIQUE KEY `uq_caf_rep_date` (`category_id`,`is_reporting_date`),
  KEY `idx_caf_cat` (`category_id`),
  KEY `idx_caf_type` (`field_type`),
  KEY `idx_caf_active` (`is_active`),
  KEY `idx_caf_order` (`category_id`,`sort_order`,`id`),
  CONSTRAINT `fk_caf_cat` FOREIGN KEY (`category_id`) REFERENCES `current_affairs_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_caf_rep_date` CHECK (((`is_reporting_date` is null) or ((`is_reporting_date` = 1) and (`field_type` = _utf8mb4'date') and (`is_active` = 1)))),
  CONSTRAINT `chk_caf_totals` CHECK (((`include_in_totals` = 0) or (`field_type` in (_utf8mb4'number',_utf8mb4'money'))))
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `current_affairs_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_car_cat` (`category_id`),
  KEY `idx_car_created` (`created_at`),
  KEY `idx_car_updated` (`updated_at`),
  CONSTRAINT `fk_car_cat` FOREIGN KEY (`category_id`) REFERENCES `current_affairs_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `current_affairs_values` (
  `id` int NOT NULL AUTO_INCREMENT,
  `record_id` int NOT NULL,
  `field_id` int NOT NULL,
  `value_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `value_number` decimal(20,2) DEFAULT NULL,
  `value_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cav_record_field` (`record_id`,`field_id`),
  KEY `idx_cav_field` (`field_id`),
  KEY `idx_cav_date` (`value_date`),
  KEY `idx_cav_number` (`value_number`),
  KEY `idx_cav_text_prefix` (`value_text`(191)),
  CONSTRAINT `fk_cav_field` FOREIGN KEY (`field_id`) REFERENCES `current_affairs_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cav_record` FOREIGN KEY (`record_id`) REFERENCES `current_affairs_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(120) NOT NULL,
  `last_name` varchar(120) NOT NULL,
  `national_code` varchar(20) DEFAULT NULL,
  `phone` varchar(40) NOT NULL,
  `economic_code` varchar(80) DEFAULT NULL,
  `registration_number` varchar(80) DEFAULT NULL,
  `address` text,
  `postal_code` varchar(40) DEFAULT NULL,
  `note` text,
  `total_spent` decimal(14,2) NOT NULL DEFAULT '0.00',
  `debt` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `db_backup_settings` (
  `id` int NOT NULL,
  `backup_dir` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `defective_goods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `quantity` decimal(14,2) NOT NULL,
  `defective_date` date NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dg_product` (`product_id`),
  KEY `idx_dg_date` (`defective_date`),
  KEY `idx_dg_created` (`created_at`),
  CONSTRAINT `fk_dg_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_dg_quantity` CHECK ((`quantity` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `defective_media` (
  `defective_id` int NOT NULL,
  `media_id` int unsigned NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`defective_id`,`media_id`),
  KEY `idx_defective_media_order` (`defective_id`,`sort_order`),
  KEY `fk_defective_media_file` (`media_id`),
  CONSTRAINT `fk_defective_media_entry` FOREIGN KEY (`defective_id`) REFERENCES `defective_goods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_defective_media_file` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_bundle_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_item_id` int NOT NULL,
  `bundle_product_id` int NOT NULL,
  `component_product_id` int NOT NULL,
  `component_quantity` decimal(14,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ibi_invoice_item` (`invoice_item_id`),
  KEY `idx_ibi_bundle` (`bundle_product_id`),
  KEY `idx_ibi_component` (`component_product_id`),
  CONSTRAINT `fk_ibi_bundle` FOREIGN KEY (`bundle_product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ibi_component` FOREIGN KEY (`component_product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ibi_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_ibi_quantity` CHECK ((`component_quantity` > 0))
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `product_id` int NOT NULL,
  `unit_id` int DEFAULT NULL,
  `unit_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conversion_factor` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(14,2) NOT NULL,
  `base_quantity` decimal(14,2) DEFAULT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `discount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ii_invoice` (`invoice_id`),
  KEY `idx_ii_product` (`product_id`),
  KEY `idx_ii_unit` (`unit_id`),
  CONSTRAINT `fk_ii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ii_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ii_discount` CHECK ((`discount` >= 0)),
  CONSTRAINT `chk_ii_quantity` CHECK ((`quantity` > 0)),
  CONSTRAINT `chk_ii_unit_price` CHECK ((`unit_price` >= 0))
) ENGINE=InnoDB AUTO_INCREMENT=2269 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_sequences` (
  `seq_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_value` bigint unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`seq_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_settings` (
  `id` int NOT NULL,
  `unofficial_invoice_desc` text,
  `official_invoice_desc` text,
  `proforma_desc` text,
  `proforma_title` varchar(255) DEFAULT NULL,
  `invoice_template_color` varchar(20) DEFAULT NULL,
  `official_invoice_direction` varchar(20) DEFAULT 'vertical',
  `unofficial_invoice_direction` varchar(20) DEFAULT 'vertical',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('sales_invoice','sales_proforma','purchase_invoice','purchase_proforma') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `payment_type` enum('cash','pos','bank_transfer') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pos',
  `payment_status` enum('paid','unpaid','partial') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `invoice_date` date NOT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `payable_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `client_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `legacy_paid_amount` decimal(14,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  UNIQUE KEY `client_token` (`client_token`),
  KEY `idx_inv_type` (`type`),
  KEY `idx_inv_customer` (`customer_id`),
  KEY `idx_inv_supplier` (`supplier_id`),
  KEY `idx_inv_date` (`invoice_date`),
  CONSTRAINT `fk_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_inv_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inv_party` CHECK ((((`type` like _utf8mb4'sales%') and (`customer_id` is not null) and (`supplier_id` is null)) or ((`type` like _utf8mb4'purchase%') and (`customer_id` is null) and (`supplier_id` is not null))))
) ENGINE=InnoDB AUTO_INCREMENT=854 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `media` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_filename` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `thumbnail_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL,
  `width` smallint unsigned NOT NULL,
  `height` smallint unsigned NOT NULL,
  `sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `folder_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stored_filename` (`stored_filename`),
  UNIQUE KEY `sha256` (`sha256`),
  KEY `idx_media_created` (`created_at`),
  KEY `idx_media_folder` (`folder_id`,`id`),
  CONSTRAINT `fk_media_folder` FOREIGN KEY (`folder_id`) REFERENCES `media_folders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `media_display_settings` (
  `id` int NOT NULL,
  `products_list` tinyint(1) NOT NULL DEFAULT '1',
  `sales_ui` tinyint(1) NOT NULL DEFAULT '1',
  `purchase_ui` tinyint(1) NOT NULL DEFAULT '1',
  `sales_returns_ui` tinyint(1) NOT NULL DEFAULT '1',
  `defective_ui` tinyint(1) NOT NULL DEFAULT '1',
  `reports_ui` tinyint(1) NOT NULL DEFAULT '0',
  `category_images` tinyint(1) NOT NULL DEFAULT '1',
  `pdf_sales` tinyint(1) NOT NULL DEFAULT '0',
  `pdf_sales_proforma` tinyint(1) NOT NULL DEFAULT '0',
  `pdf_purchase` tinyint(1) NOT NULL DEFAULT '0',
  `pdf_purchase_proforma` tinyint(1) NOT NULL DEFAULT '0',
  `pdf_sales_return` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `media_folders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(140) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#fff3a3',
  `pos_x` int NOT NULL DEFAULT '30',
  `pos_y` int NOT NULL DEFAULT '30',
  `z_index` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notes_z_index` (`z_index`),
  KEY `idx_notes_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_form_amounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `form_id` int NOT NULL,
  `amount` bigint unsigned NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pfa_form` (`form_id`),
  CONSTRAINT `fk_pfa_form` FOREIGN KEY (`form_id`) REFERENCES `payment_forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_pfa_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_form_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `form_id` int NOT NULL,
  `field_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('name','mobile','email','description','text','textarea','number','select','radio','checkbox') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `required` tinyint(1) NOT NULL DEFAULT '0',
  `options` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `placeholder` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `validation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pff_form_key` (`form_id`,`field_key`),
  KEY `idx_pff_form` (`form_id`),
  CONSTRAINT `fk_pff_form` FOREIGN KEY (`form_id`) REFERENCES `payment_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_forms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `amount_mode` enum('fixed','custom','selectable') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `fixed_amount` bigint unsigned NOT NULL DEFAULT '0',
  `min_amount` bigint unsigned NOT NULL DEFAULT '0',
  `max_amount` bigint unsigned NOT NULL DEFAULT '0',
  `purpose` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `expires_at` datetime DEFAULT NULL,
  `success_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `redirect_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redirect_delay_seconds` int unsigned NOT NULL DEFAULT '0',
  `notify_customer` tinyint(1) NOT NULL DEFAULT '0',
  `notify_admin` tinyint(1) NOT NULL DEFAULT '0',
  `theme` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pf_slug` (`slug`),
  KEY `idx_pf_active` (`is_active`),
  KEY `idx_pf_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `recipient` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('customer','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'farazsms',
  `status` enum('pending','sent','failed','disabled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `provider_request_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `error_message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pn_dedup` (`transaction_id`,`type`,`provider`),
  KEY `idx_pn_tx` (`transaction_id`),
  KEY `idx_pn_status` (`status`),
  CONSTRAINT `fk_pn_tx` FOREIGN KEY (`transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_rate_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `scope_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `window_start` datetime DEFAULT NULL,
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prl_scope` (`scope_key`),
  KEY `idx_prl_key` (`scope_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_settings` (
  `id` int NOT NULL,
  `zarinpal_merchant_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zarinpal_sandbox` tinyint(1) NOT NULL DEFAULT '0',
  `zarinpal_gateway_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `zarinpal_callback_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zarinpal_default_description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_form_id` int DEFAULT NULL,
  `sms_api_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_sender_line` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_customer_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `sms_admin_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `sms_success_pattern_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_admin_pattern_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_admin_mobile` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `form_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `transaction_number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` bigint unsigned NOT NULL,
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IRR',
  `status` enum('created','pending','redirected','callback_received','verifying','paid','failed','cancelled','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `gateway` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'zarinpal',
  `authority` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ref_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_response` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `failure_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_mobile` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_data` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `callback_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pt_number` (`transaction_number`),
  UNIQUE KEY `uq_pt_authority` (`authority`),
  KEY `idx_pt_form` (`form_id`),
  KEY `idx_pt_status` (`status`),
  KEY `idx_pt_created` (`created_at`),
  KEY `idx_pt_mobile` (`customer_mobile`),
  KEY `idx_pt_ref` (`ref_id`),
  KEY `idx_pt_customer` (`customer_id`),
  CONSTRAINT `fk_pt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pt_form` FOREIGN KEY (`form_id`) REFERENCES `payment_forms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_pt_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `parent_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `product_categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=164 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `product_media` (
  `product_id` int NOT NULL,
  `media_id` int unsigned NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`product_id`,`media_id`),
  KEY `idx_pm_product_order` (`product_id`,`sort_order`),
  KEY `idx_pm_media` (`media_id`),
  CONSTRAINT `fk_pm_media` FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversion_factor` decimal(14,4) NOT NULL DEFAULT '1.0000',
  `is_base` tinyint(1) NOT NULL DEFAULT '0',
  `purchase_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `sale_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `barcode` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pu_product_name` (`product_id`,`name`),
  KEY `idx_pu_product` (`product_id`),
  CONSTRAINT `fk_pu_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2336 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `category_id` int DEFAULT NULL,
  `type` enum('product','service') NOT NULL DEFAULT 'product',
  `product_kind` enum('standard','bundle') NOT NULL DEFAULT 'standard',
  `unit` varchar(50) NOT NULL DEFAULT 'عدد',
  `purchase_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `sale_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `stock` decimal(14,2) NOT NULL DEFAULT '0.00',
  `min_stock` decimal(14,2) NOT NULL DEFAULT '0.00',
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `uq_products_barcode` (`barcode`),
  KEY `category_id` (`category_id`),
  KEY `idx_products_kind` (`product_kind`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1731 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `sales_return_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_id` int NOT NULL,
  `invoice_item_id` int DEFAULT NULL,
  `product_id` int NOT NULL,
  `unit_id` int DEFAULT NULL,
  `unit_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conversion_factor` decimal(14,4) DEFAULT NULL,
  `quantity` decimal(14,2) NOT NULL,
  `base_quantity` decimal(14,2) NOT NULL,
  `unit_price` decimal(14,2) NOT NULL,
  `discount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sri_return` (`return_id`),
  KEY `idx_sri_product` (`product_id`),
  KEY `idx_sri_invoice_item` (`invoice_item_id`),
  CONSTRAINT `fk_sri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sri_return` FOREIGN KEY (`return_id`) REFERENCES `sales_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_sri_base_quantity` CHECK ((`base_quantity` > 0)),
  CONSTRAINT `chk_sri_discount` CHECK ((`discount` >= 0)),
  CONSTRAINT `chk_sri_quantity` CHECK ((`quantity` > 0)),
  CONSTRAINT `chk_sri_unit_price` CHECK ((`unit_price` >= 0))
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sales_returns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `return_date` date NOT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `payable_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_number` (`return_number`),
  KEY `idx_sr_invoice` (`invoice_id`),
  KEY `idx_sr_customer` (`customer_id`),
  KEY `idx_sr_date` (`return_date`),
  CONSTRAINT `fk_sr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sr_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `invoice_id` int DEFAULT NULL,
  `sales_return_id` int DEFAULT NULL,
  `type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direction` enum('in','out') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(14,2) NOT NULL,
  `transaction_quantity` decimal(14,2) DEFAULT NULL,
  `conversion_factor` decimal(14,4) DEFAULT NULL,
  `stock_before` decimal(14,2) NOT NULL,
  `stock_after` decimal(14,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_product` (`product_id`),
  KEY `idx_sm_invoice` (`invoice_id`),
  KEY `idx_sm_type` (`type`),
  KEY `idx_sm_created` (`created_at`),
  KEY `idx_sm_sales_return` (`sales_return_id`),
  CONSTRAINT `fk_sm_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=1754 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `store_settings` (
  `id` int NOT NULL,
  `store_name` varchar(255) NOT NULL,
  `economic_code` varchar(80) DEFAULT NULL,
  `national_code` varchar(80) DEFAULT NULL,
  `registration_number` varchar(80) DEFAULT NULL,
  `province_id` int DEFAULT NULL,
  `city_id` int DEFAULT NULL,
  `postal_code` varchar(40) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `address` text,
  `logo_path` varchar(255) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `stamp_path` varchar(255) DEFAULT NULL,
  `default_size_percentage` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_name` varchar(150) DEFAULT NULL,
  `first_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(120) DEFAULT NULL,
  `national_code` varchar(20) DEFAULT NULL,
  `phone` varchar(40) NOT NULL,
  `economic_code` varchar(80) DEFAULT NULL,
  `registration_number` varchar(80) DEFAULT NULL,
  `address` text,
  `postal_code` varchar(40) DEFAULT NULL,
  `note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `tax_settings` (
  `id` int NOT NULL,
  `tax_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;
