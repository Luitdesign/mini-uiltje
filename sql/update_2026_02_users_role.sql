ALTER TABLE users
  ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER password_hash;

UPDATE users
SET role = 'admin'
WHERE LOWER(username) = 'admin';
