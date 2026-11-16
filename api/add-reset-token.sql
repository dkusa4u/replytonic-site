-- Run in phpMyAdmin → database u922271352_replytonic → SQL tab
ALTER TABLE users
  ADD COLUMN reset_token VARCHAR(64) NULL DEFAULT NULL,
  ADD COLUMN reset_token_expires DATETIME NULL DEFAULT NULL;

-- Optional: index for fast token lookups
CREATE INDEX idx_reset_token ON users (reset_token);
