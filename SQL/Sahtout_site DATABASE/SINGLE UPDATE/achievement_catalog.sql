-- Reference catalog imported from client DBC files (Achievement.dbc, etc.).
-- Data rows: run `php tools/import_achievement_catalog.php` then import the generated SQL file.
-- Token rewards use a separate table (achievement_token_rewards) when implemented.

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
