-- Adopt a Bot redemption tracking (hearthstone_site). Run on your site database if not created automatically.
CREATE TABLE IF NOT EXISTS `adopt_a_bot_claims` (
  `claim_id` int unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int unsigned NOT NULL,
  `purchase_id` int unsigned NOT NULL,
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `bot_character_guid` int unsigned DEFAULT NULL,
  `bot_character_name` varchar(12) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`claim_id`),
  UNIQUE KEY `uq_purchase` (`purchase_id`),
  KEY `idx_account_status` (`account_id`, `status`),
  CONSTRAINT `fk_adopt_claim_account` FOREIGN KEY (`account_id`) REFERENCES `user_currencies` (`account_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_adopt_claim_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`purchase_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
