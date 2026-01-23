-- Add savings ledger support and overview flags

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS include_in_overview TINYINT(1) NOT NULL DEFAULT 1 AFTER is_internal_transfer;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS ignored TINYINT(1) NOT NULL DEFAULT 0 AFTER include_in_overview;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS created_source VARCHAR(10) NOT NULL DEFAULT 'import' AFTER ignored;

CREATE INDEX IF NOT EXISTS idx_transactions_date_overview ON transactions (txn_date, include_in_overview);
CREATE INDEX IF NOT EXISTS idx_transactions_date_ignored ON transactions (txn_date, ignored);
CREATE INDEX IF NOT EXISTS idx_transactions_category ON transactions (category_id);

CREATE TABLE IF NOT EXISTS savings_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  savings_id INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  entry_type VARCHAR(10) NOT NULL,
  source_transaction_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_savings_entries_source_txn (source_transaction_id),
  KEY idx_savings_entries_savings_date (savings_id, `date`),
  KEY idx_savings_entries_source_txn (source_transaction_id),
  CONSTRAINT fk_savings_entries_savings FOREIGN KEY (savings_id) REFERENCES savings(id) ON DELETE CASCADE,
  CONSTRAINT fk_savings_entries_txn FOREIGN KEY (source_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO categories (name)
VALUES ('Savings top-ups')
ON DUPLICATE KEY UPDATE name = VALUES(name);
