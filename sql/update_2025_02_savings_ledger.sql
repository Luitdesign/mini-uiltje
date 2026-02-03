-- Add savings ledger support

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS created_source VARCHAR(10) NOT NULL DEFAULT 'import' AFTER is_internal_transfer;

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS savings_id INT UNSIGNED NULL AFTER auto_reason;

CREATE INDEX IF NOT EXISTS idx_transactions_category ON transactions (category_id);
CREATE INDEX IF NOT EXISTS idx_transactions_savings ON transactions (savings_id);

ALTER TABLE transactions
  ADD CONSTRAINT fk_transactions_savings FOREIGN KEY (savings_id) REFERENCES savings(id) ON DELETE SET NULL;

UPDATE transactions t
JOIN savings_entries se ON se.source_transaction_id = t.id
SET t.savings_id = se.savings_id
WHERE t.savings_id IS NULL;

INSERT INTO categories (name)
VALUES ('Savings top-ups')
ON DUPLICATE KEY UPDATE name = VALUES(name);
