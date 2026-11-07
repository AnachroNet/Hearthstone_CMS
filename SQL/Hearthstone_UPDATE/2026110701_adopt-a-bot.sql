-- Hearthstone CMS release 2026110701 (2026-11-07, update 01) — Adopt a Bot
-- Run against your site database (hearthstone_site) as a user with CREATE/ALTER privilege.
-- Idempotent: safe whether the table is missing, legacy (pending-only), or partially upgraded.

CREATE TABLE IF NOT EXISTS `adopt_a_bot_claims` (
  `claim_id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `purchase_id` int unsigned DEFAULT NULL,
  `status` enum('draft','pending','processing','completed','cancelled') NOT NULL DEFAULT 'draft',
  `bot_character_guid` int unsigned DEFAULT NULL,
  `bot_character_name` varchar(12) DEFAULT NULL,
  `progress_message` varchar(500) DEFAULT NULL,
  `progress_log` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`claim_id`),
  UNIQUE KEY `uq_purchase` (`purchase_id`),
  KEY `idx_account_status` (`account_id`, `status`),
  CONSTRAINT `fk_adopt_claim_account` FOREIGN KEY (`account_id`) REFERENCES `user_currencies` (`account_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_adopt_claim_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`purchase_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @hs_db := DATABASE();

SET @hs_sql := (
  SELECT IF(
    (
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @hs_db
        AND TABLE_NAME = 'adopt_a_bot_claims'
        AND COLUMN_NAME = 'progress_message'
    ) = 0,
    'ALTER TABLE `adopt_a_bot_claims` ADD COLUMN `progress_message` varchar(500) DEFAULT NULL AFTER `bot_character_name`',
    'SELECT 1'
  )
);
PREPARE hs_stmt FROM @hs_sql;
EXECUTE hs_stmt;
DEALLOCATE PREPARE hs_stmt;

SET @hs_sql := (
  SELECT IF(
    (
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @hs_db
        AND TABLE_NAME = 'adopt_a_bot_claims'
        AND COLUMN_NAME = 'progress_log'
    ) = 0,
    'ALTER TABLE `adopt_a_bot_claims` ADD COLUMN `progress_log` mediumtext DEFAULT NULL AFTER `progress_message`',
    'SELECT 1'
  )
);
PREPARE hs_stmt FROM @hs_sql;
EXECUTE hs_stmt;
DEALLOCATE PREPARE hs_stmt;

ALTER TABLE `adopt_a_bot_claims`
  MODIFY `purchase_id` int unsigned DEFAULT NULL,
  MODIFY `status` enum('draft','pending','processing','completed','cancelled') NOT NULL DEFAULT 'draft';

INSERT INTO `site_settings` (
  `setting_key`, `setting_value`, `value_type`, `category`, `label`, `is_public`, `updated_by`
) VALUES (
  'site.release', '2026110701', 'int', 'general', 'Applied CMS release', 0, NULL
)
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `value_type` = VALUES(`value_type`),
  `label` = VALUES(`label`),
  `is_public` = VALUES(`is_public`);
