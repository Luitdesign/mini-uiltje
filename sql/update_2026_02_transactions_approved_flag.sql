-- Add approved flag for transactions

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS approved TINYINT(1) NOT NULL DEFAULT 0 AFTER is_topup;
