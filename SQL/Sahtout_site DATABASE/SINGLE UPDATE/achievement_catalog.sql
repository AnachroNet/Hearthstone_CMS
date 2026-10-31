-- Reference catalog imported from client DBC files (Achievement.dbc, etc.).
-- Data rows: run `php tools/import_achievement_catalog.php` then import the generated SQL file.

CREATE TABLE IF NOT EXISTS `achievement_catalog` (
  `achievement_id` smallint unsigned NOT NULL,
  `title_enus` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `description_enus` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` int unsigned NOT NULL DEFAULT '0',
  `category_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `points` tinyint unsigned NOT NULL DEFAULT '0',
  `required_faction` tinyint NOT NULL DEFAULT '-1' COMMENT '-1 any, 0 Horde, 1 Alliance',
  `map_id` int NOT NULL DEFAULT '-1',
  `ref_achievement` int unsigned NOT NULL DEFAULT '0',
  `criteria_type` smallint unsigned DEFAULT NULL COMMENT 'Primary milestone criterion (e.g. 5=level, 9=quest count)',
  `criteria_value` int unsigned DEFAULT NULL,
  `imported_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`achievement_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_criteria` (`criteria_type`, `criteria_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-selected milestones (import script refreshes non-manual rows). Set tokens and is_enabled when ready.
CREATE TABLE IF NOT EXISTS `achievement_token_rewards` (
  `achievement_id` smallint unsigned NOT NULL,
  `tokens` int unsigned NOT NULL DEFAULT '0',
  `milestone_type` enum('level','quest_count','dungeon','manual') NOT NULL DEFAULT 'manual',
  `milestone_value` int unsigned DEFAULT NULL COMMENT 'Level or quest count when milestone_type is level/quest_count',
  `instance_map_id` int unsigned DEFAULT NULL COMMENT 'Dungeon map ID from Achievement.dbc / Map.dbc',
  `dungeon_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Instance name from Map.dbc (dungeon presets)',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`achievement_id`),
  KEY `idx_milestone` (`milestone_type`, `milestone_value`),
  KEY `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
