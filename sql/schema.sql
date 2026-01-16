-- MySQL / MariaDB schema for Mini Uiltje

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NULL,
  email VARCHAR(120) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('expense','income','transfer') NOT NULL DEFAULT 'expense',
  parent_id INT UNSIGNED NULL,
  name VARCHAR(80) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_categories_parent (parent_id),
  KEY idx_categories_type (type),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS imports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  uploaded_by_user_id INT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_hash CHAR(64) NOT NULL,
  inserted_count INT NOT NULL DEFAULT 0,
  duplicate_count INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_imports_user (uploaded_by_user_id),
  CONSTRAINT fk_imports_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_id INT UNSIGNED NULL,
  tx_date DATE NOT NULL,
  name_description VARCHAR(255) NOT NULL,
  account_iban VARCHAR(34) NULL,
  counterparty_iban VARCHAR(34) NULL,
  code VARCHAR(10) NULL,
  direction ENUM('Af','Bij') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  amount_signed DECIMAL(12,2) NOT NULL,
  mutation_type VARCHAR(80) NULL,
  messages TEXT NULL,
  balance_after DECIMAL(12,2) NULL,
  tag VARCHAR(255) NULL,
  tx_hash CHAR(64) NOT NULL,
  auto_category_id INT UNSIGNED NULL,
  manual_category_id INT UNSIGNED NULL,
  auto_rule_id INT UNSIGNED NULL,
  is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_hash (tx_hash),
  KEY idx_transactions_import (import_id),
  KEY idx_transactions_tx_date (tx_date),
  KEY idx_transactions_auto_category (auto_category_id),
  KEY idx_transactions_manual_category (manual_category_id),
  KEY idx_transactions_confirmed (is_confirmed),
  CONSTRAINT fk_transactions_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_auto_category FOREIGN KEY (auto_category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_transactions_manual_category FOREIGN KEY (manual_category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rules (
  id INT NOT NULL AUTO_INCREMENT,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  position INT NOT NULL,
  active_from DATE NOT NULL,
  match_field VARCHAR(64) NOT NULL,
  match_op ENUM('contains','starts_with','equals','regex') NOT NULL,
  match_value VARCHAR(255) NOT NULL,
  category_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rules_active_position (is_active, position),
  KEY idx_rules_active_from (active_from),
  CONSTRAINT fk_rules_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
