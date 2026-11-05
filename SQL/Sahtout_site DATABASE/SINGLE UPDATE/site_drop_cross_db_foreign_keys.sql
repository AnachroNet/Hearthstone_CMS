-- Sahtout_site: remove foreign keys to acore_auth / acore_characters.
-- Site rows are linked by account_id in application code (see includes/site_account.php).
-- Run on sahtout_site after upgrading from releases that used cross-database FKs.

USE `sahtout_site`;

ALTER TABLE `user_currencies` DROP FOREIGN KEY IF EXISTS `user_currencies_ibfk_1`;
ALTER TABLE `website_activity_log` DROP FOREIGN KEY IF EXISTS `website_activity_log_ibfk_1`;
ALTER TABLE `character_teleport_log` DROP FOREIGN KEY IF EXISTS `character_teleport_log_ibfk_1`;
ALTER TABLE `character_teleport_log` DROP FOREIGN KEY IF EXISTS `character_teleport_log_ibfk_2`;
