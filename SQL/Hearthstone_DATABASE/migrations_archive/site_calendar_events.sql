-- Custom home / in-game style calendar events (hearthstone_site).
-- Run once on the site database.

CREATE TABLE IF NOT EXISTS `site_calendar_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0',
  `recurrence_days` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Bitmask Mon=1 Tue=2 Wed=4 Thu=8 Fri=16 Sat=32 Sun=64',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_site_calendar_events_dates` (`start_date`, `end_date`),
  KEY `idx_site_calendar_events_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
