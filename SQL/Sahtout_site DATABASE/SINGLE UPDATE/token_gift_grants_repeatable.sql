-- Repeatable sealed-letter website grants (one payout per in-game quest reward cycle).

ALTER TABLE `token_gift_grants`
  DROP INDEX `uq_account_gift`;

ALTER TABLE `token_gift_grants`
  ADD COLUMN `character_guid` int unsigned NOT NULL DEFAULT '0' AFTER `token_gift_id`,
  ADD KEY `idx_account_gift` (`account_id`, `token_gift_id`);

CREATE TABLE IF NOT EXISTS `token_gift_reward_sync_state` (
  `account_id` int unsigned NOT NULL,
  `token_gift_id` int unsigned NOT NULL,
  `character_guid` int unsigned NOT NULL,
  `last_seen_rewarded_active` tinyint unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `token_gift_id`, `character_guid`),
  KEY `idx_gift` (`token_gift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
