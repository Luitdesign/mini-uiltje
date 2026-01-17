ALTER TABLE categories
  ADD COLUMN parent_id INT UNSIGNED NULL AFTER id;

ALTER TABLE categories
  ADD CONSTRAINT fk_categories_parent
  FOREIGN KEY (parent_id) REFERENCES categories(id)
  ON DELETE SET NULL;

ALTER TABLE categories
  ADD INDEX idx_categories_parent (parent_id);

ALTER TABLE categories
  DROP INDEX uq_categories_name,
  ADD UNIQUE KEY uq_categories_parent_name (parent_id, name);
