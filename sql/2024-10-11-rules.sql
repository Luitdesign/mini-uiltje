-- Add rule-based categorization fields and rules table.

ALTER TABLE categories
  ADD COLUMN type ENUM('expense','income','transfer') NOT NULL DEFAULT 'expense',
  ADD COLUMN parent_id INT NULL,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE transactions
  ADD COLUMN auto_category_id INT NULL,
  ADD COLUMN manual_category_id INT NULL,
  ADD COLUMN auto_rule_id INT NULL,
  ADD COLUMN is_confirmed TINYINT(1) NOT NULL DEFAULT 0;

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

CREATE INDEX idx_transactions_auto_category_id ON transactions(auto_category_id);
CREATE INDEX idx_transactions_manual_category_id ON transactions(manual_category_id);
CREATE INDEX idx_transactions_is_confirmed ON transactions(is_confirmed);
CREATE INDEX idx_transactions_tx_date ON transactions(tx_date);
