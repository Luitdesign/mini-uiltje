-- Add top-up flag for transactions to avoid double counting

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS is_topup TINYINT(1) NOT NULL DEFAULT 0 AFTER savings_id;

UPDATE transactions
SET is_topup = 1
WHERE savings_id IS NOT NULL
  AND created_source = 'internal'
  AND description LIKE 'Top-up:%';
