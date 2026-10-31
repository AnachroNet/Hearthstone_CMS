-- For sites that already ran achievement_catalog.sql before token rewards were added.

CREATE TABLE IF NOT EXISTS `achievement_token_rewards` (
  `achievement_id` smallint unsigned NOT NULL,
  `tokens` int unsigned NOT NULL DEFAULT '0',
  `milestone_type` enum('level','quest_count','dungeon','manual') NOT NULL DEFAULT 'manual',
  `milestone_value` int unsigned DEFAULT NULL COMMENT 'Level or quest count when milestone_type is level/quest_count',
  `instance_map_id` int unsigned DEFAULT NULL COMMENT 'Dungeon map ID from Achievement.dbc / Map.dbc',
  `dungeon_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Instance name from Map.dbc (dungeon presets)',
  `dungeon_min_level` tinyint unsigned DEFAULT NULL COMMENT 'From LFGDungeons.dbc when available',
  `dungeon_max_level` tinyint unsigned DEFAULT NULL,
  `dungeon_target_level` tinyint unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`achievement_id`),
  KEY `idx_milestone` (`milestone_type`, `milestone_value`),
  KEY `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
