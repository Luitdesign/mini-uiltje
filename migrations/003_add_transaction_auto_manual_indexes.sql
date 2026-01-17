-- Add indexes for auto/manual categorization tracking on transactions
SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND index_name = 'idx_transactions_auto_category'
);
SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND column_name = 'auto_category_id'
);
SET @sql := IF(
  @index_exists = 0 AND @column_exists > 0,
  'CREATE INDEX idx_transactions_auto_category ON transactions (auto_category_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND index_name = 'idx_transactions_manual_category'
);
SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND column_name = 'manual_category_id'
);
SET @sql := IF(
  @index_exists = 0 AND @column_exists > 0,
  'CREATE INDEX idx_transactions_manual_category ON transactions (manual_category_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND index_name = 'idx_transactions_is_confirmed'
);
SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND column_name = 'is_confirmed'
);
SET @sql := IF(
  @index_exists = 0 AND @column_exists > 0,
  'CREATE INDEX idx_transactions_is_confirmed ON transactions (is_confirmed)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND index_name = 'idx_transactions_tx_date'
);
SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND column_name = 'tx_date'
);
SET @sql := IF(
  @index_exists = 0 AND @column_exists > 0,
  'CREATE INDEX idx_transactions_tx_date ON transactions (tx_date)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
