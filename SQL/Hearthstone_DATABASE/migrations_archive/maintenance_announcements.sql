-- Short-lived maintenance / status lines for the home page (website only).

CREATE TABLE IF NOT EXISTS `maintenance_announcements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `visible_from` datetime NOT NULL,
  `visible_until` datetime NOT NULL,
  `posted_by` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_maintenance_visible` (`visible_from`, `visible_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
