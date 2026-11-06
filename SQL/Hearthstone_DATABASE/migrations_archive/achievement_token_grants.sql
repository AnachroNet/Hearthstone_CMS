-- Ledger: one row per account per achievement (account-wide rewards, not per character).

CREATE TABLE IF NOT EXISTS `achievement_token_grants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `achievement_id` smallint unsigned NOT NULL,
  `tokens_awarded` int unsigned NOT NULL,
  `granted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_achievement` (`account_id`, `achievement_id`),
  KEY `idx_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
