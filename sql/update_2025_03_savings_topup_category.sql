-- Add per-saving top-up category support

ALTER TABLE savings
  ADD COLUMN IF NOT EXISTS topup_category_id INT UNSIGNED NULL AFTER monthly_amount;

CREATE INDEX IF NOT EXISTS idx_savings_topup_category ON savings (topup_category_id);

ALTER TABLE savings
  ADD CONSTRAINT fk_savings_topup_category FOREIGN KEY (topup_category_id) REFERENCES categories(id) ON DELETE SET NULL;
