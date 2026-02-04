-- Add split transaction metadata columns and indexes

ALTER TABLE transactions
  ADD COLUMN split_group_id BIGINT UNSIGNED NULL AFTER created_source,
  ADD COLUMN parent_transaction_id BIGINT UNSIGNED NULL AFTER split_group_id,
  ADD COLUMN is_split_source TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_transaction_id,
  ADD COLUMN is_split_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_split_source;

UPDATE transactions
SET is_split_source = 0
WHERE is_split_source IS NULL;

UPDATE transactions
SET is_split_active = 1
WHERE is_split_active IS NULL;

ALTER TABLE transactions
  ADD KEY idx_transactions_split_group (split_group_id),
  ADD KEY idx_transactions_parent (parent_transaction_id),
  ADD CONSTRAINT fk_transactions_parent FOREIGN KEY (parent_transaction_id)
    REFERENCES transactions(id) ON DELETE SET NULL;
