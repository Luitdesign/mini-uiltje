-- Add savings ledger support and overview flags

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS include_in_overview TINYINT(1) NOT NULL DEFAULT 1 AFTER is_internal_transfer;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS ignored TINYINT(1) NOT NULL DEFAULT 0 AFTER include_in_overview;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS created_source VARCHAR(10) NOT NULL DEFAULT 'import' AFTER ignored;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS savings_id INT UNSIGNED NULL AFTER auto_reason;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS savings_entry_type VARCHAR(10) NULL AFTER savings_id;

CREATE INDEX IF NOT EXISTS idx_transactions_date_overview ON transactions (txn_date, include_in_overview);
CREATE INDEX IF NOT EXISTS idx_transactions_date_ignored ON transactions (txn_date, ignored);
CREATE INDEX IF NOT EXISTS idx_transactions_category ON transactions (category_id);
CREATE INDEX IF NOT EXISTS idx_transactions_savings ON transactions (savings_id);

ALTER TABLE transactions
  ADD CONSTRAINT fk_transactions_savings FOREIGN KEY (savings_id) REFERENCES savings(id) ON DELETE SET NULL;

UPDATE transactions t
JOIN savings_entries se ON se.source_transaction_id = t.id
SET t.savings_id = se.savings_id,
    t.savings_entry_type = se.entry_type
WHERE t.savings_id IS NULL;

INSERT INTO categories (name)
VALUES ('Savings top-ups')
ON DUPLICATE KEY UPDATE name = VALUES(name);
