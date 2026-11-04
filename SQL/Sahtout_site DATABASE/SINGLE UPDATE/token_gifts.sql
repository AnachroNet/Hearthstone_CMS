-- In-game "token gift" letters: site catalog, account grants, deployed loot rows.

CREATE TABLE IF NOT EXISTS `token_gifts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `letter_text` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tokens` int unsigned NOT NULL DEFAULT '0',
  `item_entry` int unsigned NOT NULL DEFAULT '0',
  `quest_id` int unsigned NOT NULL DEFAULT '0',
  `page_text_id` int unsigned NOT NULL DEFAULT '0',
  `page_text_page_count` tinyint unsigned NOT NULL DEFAULT '0',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `deploy_world_chests` tinyint(1) NOT NULL DEFAULT '1',
  `loot_chance` decimal(5,2) NOT NULL DEFAULT '2.00',
  `extra_creature_entries` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extra_go_loot_ids` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_gift_item` (`item_entry`),
  UNIQUE KEY `uq_token_gift_quest` (`quest_id`),
  KEY `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `token_gift_grants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `token_gift_id` int unsigned NOT NULL,
  `quest_id` int unsigned NOT NULL,
  `tokens_awarded` int unsigned NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_gift` (`account_id`, `token_gift_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `token_gift_loot_deployments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token_gift_id` int unsigned NOT NULL,
  `loot_kind` enum('gameobject','creature') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `loot_entry` int unsigned NOT NULL,
  `item_entry` int unsigned NOT NULL,
  `chance` decimal(5,2) NOT NULL,
  `min_count` tinyint unsigned NOT NULL DEFAULT '1',
  `max_count` tinyint unsigned NOT NULL DEFAULT '1',
  `group_id` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gift_loot_target` (`token_gift_id`, `loot_kind`, `loot_entry`, `item_entry`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
