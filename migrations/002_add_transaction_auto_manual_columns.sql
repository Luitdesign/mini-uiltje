-- Add auto/manual categorization tracking columns to transactions
SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND column_name = 'auto_category_id'
);
SET @sql := IF(
  @column_exists = 0,
  'ALTER TABLE transactions ADD COLUMN auto_category_id INT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND column_name = 'manual_category_id'
);
SET @sql := IF(
  @column_exists = 0,
  'ALTER TABLE transactions ADD COLUMN manual_category_id INT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND column_name = 'auto_rule_id'
);
SET @sql := IF(
  @column_exists = 0,
  'ALTER TABLE transactions ADD COLUMN auto_rule_id INT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'transactions'
    AND column_name = 'is_confirmed'
);
SET @sql := IF(
  @column_exists = 0,
  'ALTER TABLE transactions ADD COLUMN is_confirmed TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
