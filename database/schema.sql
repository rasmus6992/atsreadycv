-- Full database setup for a fresh installation.
-- Existing installations should import migration_v4.sql instead.

CREATE TABLE IF NOT EXISTS `cv_tailor_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_cv` LONGTEXT NOT NULL,
    `job_description` LONGTEXT NOT NULL,
    `tailored_cv` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
