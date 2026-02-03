-- Remove ignored flag from transactions

ALTER TABLE transactions
  DROP COLUMN IF EXISTS ignored;

DROP INDEX IF EXISTS idx_transactions_date_ignored ON transactions;
