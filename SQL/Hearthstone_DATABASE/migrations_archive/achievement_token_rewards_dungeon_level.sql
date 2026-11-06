-- LFG level bands from LFGDungeons.dbc (optional; admin UI can also read DBC at runtime).

ALTER TABLE `achievement_token_rewards`
  ADD COLUMN `dungeon_min_level` tinyint unsigned DEFAULT NULL AFTER `dungeon_name`;

ALTER TABLE `achievement_token_rewards`
  ADD COLUMN `dungeon_max_level` tinyint unsigned DEFAULT NULL AFTER `dungeon_min_level`;

ALTER TABLE `achievement_token_rewards`
  ADD COLUMN `dungeon_target_level` tinyint unsigned DEFAULT NULL AFTER `dungeon_max_level`;
