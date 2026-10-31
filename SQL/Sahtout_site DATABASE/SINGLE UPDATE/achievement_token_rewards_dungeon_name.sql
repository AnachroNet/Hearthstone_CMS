-- Add dungeon display fields to existing achievement_token_rewards tables.
-- Run once. If columns already exist, MySQL will report "Duplicate column name" — safe to ignore.

ALTER TABLE `achievement_token_rewards`
  ADD COLUMN `instance_map_id` int unsigned DEFAULT NULL COMMENT 'Dungeon map ID from Achievement.dbc / Map.dbc' AFTER `milestone_value`;

ALTER TABLE `achievement_token_rewards`
  ADD COLUMN `dungeon_name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Instance name from Map.dbc (dungeon presets)' AFTER `instance_map_id`;
