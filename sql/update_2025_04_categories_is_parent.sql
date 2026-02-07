-- Add is_parent column to categories for parent grouping support

ALTER TABLE categories
  ADD COLUMN is_parent TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_id;

UPDATE categories
SET is_parent = 0
WHERE is_parent IS NULL;
