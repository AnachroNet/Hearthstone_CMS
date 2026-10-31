-- Boss-kill criteria and resolved instance map (e.g. Deadmines via creature spawn lookup).

ALTER TABLE `achievement_catalog`
  ADD COLUMN `boss_creature_id` int unsigned DEFAULT NULL COMMENT 'Achievement criteria type 0 (kill creature)' AFTER `criteria_value`;

ALTER TABLE `achievement_catalog`
  ADD COLUMN `instance_map_id` int DEFAULT NULL COMMENT 'Resolved 5-man instance map (achievement map or world creature)' AFTER `boss_creature_id`;
