-- Add top-up flag for transactions to avoid double counting

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS is_topup TINYINT(1) NOT NULL DEFAULT 0 AFTER savings_entry_type;

UPDATE transactions
SET is_topup = 1
WHERE savings_entry_type = 'topup';
