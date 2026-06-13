-- Import this once when upgrading from the previously working portal.
-- It does not modify or delete the existing CV transaction table or records.

CREATE TABLE IF NOT EXISTS `cv_tailor_rate_limits` (
    `ip_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` DATETIME NOT NULL,
    `last_attempt_at` DATETIME NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`ip_hash`),
    INDEX `idx_rate_limit_window` (`window_started_at`),
    INDEX `idx_rate_limit_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
