-- Safe, repeatable, additive settings table. Existing financial rows are untouched.
CREATE TABLE IF NOT EXISTS currency_settings (
  id INT PRIMARY KEY,
  currency_code VARCHAR(12) NOT NULL DEFAULT 'TOMAN',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO currency_settings (id,currency_code) VALUES (1,'TOMAN');
