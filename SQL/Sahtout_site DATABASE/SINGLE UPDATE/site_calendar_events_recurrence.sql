-- Weekly recurrence for site_calendar_events (sahtout_site). Run once after site_calendar_events.sql.

ALTER TABLE `site_calendar_events`
  ADD COLUMN `is_recurring` tinyint(1) NOT NULL DEFAULT '0' AFTER `end_date`,
  ADD COLUMN `recurrence_days` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Bitmask Mon=1 Tue=2 Wed=4 Thu=8 Fri=16 Sat=32 Sun=64' AFTER `is_recurring`;
