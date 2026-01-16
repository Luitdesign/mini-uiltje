-- Mini Uiltje - Website version
-- MySQL 8 / MariaDB 10.4+ compatible

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('expense','income','transfer') NOT NULL,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_categories_type (type),
  KEY ix_categories_parent (parent_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  priority INT NOT NULL DEFAULT 100,
  active_from DATE NOT NULL,
  active_to DATE NULL,
  match_field VARCHAR(64) NOT NULL,
  match_op ENUM('contains','starts_with','equals','regex') NOT NULL,
  match_value VARCHAR(255) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rules_enabled_priority (enabled, priority),
  KEY ix_rules_dates (active_from, active_to),
  CONSTRAINT fk_rules_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uploaded_by_user_id INT UNSIGNED NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  inserted_count INT NOT NULL DEFAULT 0,
  duplicate_count INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY ux_imports_filehash (file_hash),
  KEY ix_imports_imported_at (imported_at),
  CONSTRAINT fk_imports_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_id INT UNSIGNED NULL,
  tx_date DATE NOT NULL,
  name_description VARCHAR(255) NOT NULL,
  account_iban VARCHAR(34) NOT NULL,
  counterparty_iban VARCHAR(34) NULL,
  code VARCHAR(8) NULL,
  direction ENUM('Af','Bij') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  amount_signed DECIMAL(12,2) NOT NULL,
  mutation_type VARCHAR(64) NULL,
  messages TEXT NULL,
  balance_after DECIMAL(14,2) NULL,
  tag VARCHAR(255) NULL,

  auto_category_id INT UNSIGNED NULL,
  manual_category_id INT UNSIGNED NULL,
  is_confirmed TINYINT(1) NOT NULL DEFAULT 0,

  tx_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY ux_transactions_txhash (tx_hash),
  KEY ix_transactions_date (tx_date),
  KEY ix_transactions_import (import_id),
  KEY ix_transactions_categories (auto_category_id, manual_category_id),
  CONSTRAINT fk_transactions_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_auto_cat FOREIGN KEY (auto_category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_manual_cat FOREIGN KEY (manual_category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_splits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id BIGINT UNSIGNED NOT NULL,
  line_no INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_split_line (transaction_id, line_no),
  KEY ix_splits_tx (transaction_id),
  CONSTRAINT fk_splits_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_splits_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_balances (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  yyyymm CHAR(7) NOT NULL, -- e.g. 2026-01
  bank_start_balance DECIMAL(14,2) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_monthly_balances_yyyymm (yyyymm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  start_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pot_monthly_delta (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  yyyymm CHAR(7) NOT NULL,
  pot_id INT UNSIGNED NOT NULL,
  delta DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_pot_monthly (yyyymm, pot_id),
  CONSTRAINT fk_pot_delta_pot FOREIGN KEY (pot_id) REFERENCES pots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
