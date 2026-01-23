-- MySQL / MariaDB schema for Financial Web App MVP

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS savings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  start_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monthly_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  color VARCHAR(7) NULL,
  parent_id INT UNSIGNED NULL,
  savings_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name),
  KEY idx_categories_parent (parent_id),
  KEY idx_categories_savings (savings_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_categories_savings FOREIGN KEY (savings_id) REFERENCES savings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS imports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_imports_user (user_id),
  CONSTRAINT fk_imports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  priority INT NOT NULL DEFAULT 0,
  name VARCHAR(120) NOT NULL,
  from_text VARCHAR(255) NULL,
  from_text_match ENUM('contains','starts','equals') NULL,
  from_iban VARCHAR(34) NULL,
  mededelingen_text VARCHAR(255) NULL,
  mededelingen_match ENUM('contains','starts','equals') NULL,
  rekening_equals VARCHAR(34) NULL,
  amount_min DECIMAL(12,2) NULL,
  amount_max DECIMAL(12,2) NULL,
  target_category_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rules_user_priority (user_id, priority),
  CONSTRAINT fk_rules_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rules_target_category FOREIGN KEY (target_category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  import_id INT UNSIGNED NULL,
  import_batch_id INT UNSIGNED NULL,
  txn_hash CHAR(40) NOT NULL,

  txn_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  friendly_name VARCHAR(255) NULL,

  account_iban VARCHAR(34) NULL,
  counter_iban VARCHAR(34) NULL,
  code VARCHAR(10) NULL,

  direction ENUM('Af','Bij') NOT NULL,
  amount_signed DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',

  mutation_type VARCHAR(80) NULL,
  notes TEXT NULL,
  balance_after DECIMAL(12,2) NULL,
  tag VARCHAR(255) NULL,
  is_internal_transfer TINYINT(1) NOT NULL DEFAULT 0,
  include_in_overview TINYINT(1) NOT NULL DEFAULT 1,
  ignored TINYINT(1) NOT NULL DEFAULT 0,
  created_source VARCHAR(10) NOT NULL DEFAULT 'import',

  category_id INT UNSIGNED NULL,
  category_auto_id INT UNSIGNED NULL,
  rule_auto_id INT UNSIGNED NULL,
  auto_reason VARCHAR(255) NULL,
  savings_id INT UNSIGNED NULL,
  savings_entry_type VARCHAR(10) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_hash (txn_hash),
  KEY idx_transactions_user_date (user_id, txn_date),
  KEY idx_transactions_category (category_id),
  KEY idx_transactions_category_auto (category_auto_id),
  KEY idx_transactions_import (import_id),
  KEY idx_transactions_import_batch (import_batch_id),
  KEY idx_transactions_rule_auto (rule_auto_id),
  KEY idx_transactions_savings (savings_id),
  KEY idx_transactions_internal_transfer (is_internal_transfer),
  KEY idx_transactions_date_overview (txn_date, include_in_overview),
  KEY idx_transactions_date_ignored (txn_date, ignored),

  CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_transactions_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_import_batch FOREIGN KEY (import_batch_id) REFERENCES imports(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_category_auto FOREIGN KEY (category_auto_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_savings FOREIGN KEY (savings_id) REFERENCES savings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
