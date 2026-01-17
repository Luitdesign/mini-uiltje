-- Create rules table for auto categorization
CREATE TABLE IF NOT EXISTS rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  match_text VARCHAR(255) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  active_from DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_rules_user (user_id),
  KEY idx_rules_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'rules'
    AND index_name = 'idx_rules_active_position'
);
SET @column_active := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'rules'
    AND column_name = 'is_active'
);
SET @column_position := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'rules'
    AND column_name = 'position'
);
SET @sql := IF(
  @index_exists = 0 AND @column_active > 0 AND @column_position > 0,
  'CREATE INDEX idx_rules_active_position ON rules (is_active, position)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE()
    AND table_name = 'rules'
    AND index_name = 'idx_rules_active_from'
);
SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE()
    AND table_name = 'rules'
    AND column_name = 'active_from'
);
SET @sql := IF(
  @index_exists = 0 AND @column_exists > 0,
  'CREATE INDEX idx_rules_active_from ON rules (active_from)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
