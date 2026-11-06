-- Add PvP Kills milestone type (uses characters.totalKills, best character on account).

ALTER TABLE `character_stat_token_rewards`
  MODIFY `stat_type` enum('world_kills','pvp_kills','duels_won','deaths','hours_played')
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
