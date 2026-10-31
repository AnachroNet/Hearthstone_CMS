-- For sites that already ran achievement_catalog.sql before token rewards were added.

CREATE TABLE IF NOT EXISTS `achievement_token_rewards` (
  `achievement_id` smallint unsigned NOT NULL,
  `tokens` int unsigned NOT NULL DEFAULT '0',
  `milestone_type` enum('level','quest_count','dungeon','manual') NOT NULL DEFAULT 'manual',
  `milestone_value` int unsigned DEFAULT NULL COMMENT 'Level or quest count when milestone_type is level/quest_count',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`achievement_id`),
  KEY `idx_milestone` (`milestone_type`, `milestone_value`),
  KEY `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
