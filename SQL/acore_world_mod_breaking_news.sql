-- In-game breaking news (character select), read by mod-breaking-news-override when BreakingNews.Source is db or auto.
-- Apply on acore_world. SahtoutCMS admin News page writes this table.

USE `acore_world`;

CREATE TABLE IF NOT EXISTS `mod_breaking_news` (
  `id` tinyint unsigned NOT NULL DEFAULT '1',
  `title` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Breaking News',
  `body_source` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Editor source: paragraphs, |cAARRGGBB color codes',
  `body_html` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SimpleHTML document for the client',
  `enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `mod_breaking_news` (`id`, `title`, `body_source`, `body_html`, `enabled`, `updated_by`)
VALUES (
  1,
  'Breaking News',
  '',
  '<html><body></body></html>',
  0,
  'install'
) ON DUPLICATE KEY UPDATE `id` = `id`;
