-- Sahtout_site: remove foreign keys to acore_auth / acore_characters.
-- Site rows are linked by account_id in application code (see includes/site_account.php).
-- Run on hearthstone_site after upgrading from releases that used cross-database FKs.
-- Compatible with MySQL 5.7+ and MariaDB (no DROP FOREIGN KEY IF EXISTS).

USE `hearthstone_site`;

SET @drop_fk = (
  SELECT IF(
    (
      SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'user_currencies'
        AND CONSTRAINT_NAME = 'user_currencies_ibfk_1'
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) > 0,
    'ALTER TABLE `user_currencies` DROP FOREIGN KEY `user_currencies_ibfk_1`',
    'SELECT 1'
  )
);
PREPARE _sahtout_drop_fk FROM @drop_fk;
EXECUTE _sahtout_drop_fk;
DEALLOCATE PREPARE _sahtout_drop_fk;

SET @drop_fk = (
  SELECT IF(
    (
      SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'website_activity_log'
        AND CONSTRAINT_NAME = 'website_activity_log_ibfk_1'
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) > 0,
    'ALTER TABLE `website_activity_log` DROP FOREIGN KEY `website_activity_log_ibfk_1`',
    'SELECT 1'
  )
);
PREPARE _sahtout_drop_fk FROM @drop_fk;
EXECUTE _sahtout_drop_fk;
DEALLOCATE PREPARE _sahtout_drop_fk;

SET @drop_fk = (
  SELECT IF(
    (
      SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'character_teleport_log'
        AND CONSTRAINT_NAME = 'character_teleport_log_ibfk_1'
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) > 0,
    'ALTER TABLE `character_teleport_log` DROP FOREIGN KEY `character_teleport_log_ibfk_1`',
    'SELECT 1'
  )
);
PREPARE _sahtout_drop_fk FROM @drop_fk;
EXECUTE _sahtout_drop_fk;
DEALLOCATE PREPARE _sahtout_drop_fk;

SET @drop_fk = (
  SELECT IF(
    (
      SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE()
        AND TABLE_NAME = 'character_teleport_log'
        AND CONSTRAINT_NAME = 'character_teleport_log_ibfk_2'
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ) > 0,
    'ALTER TABLE `character_teleport_log` DROP FOREIGN KEY `character_teleport_log_ibfk_2`',
    'SELECT 1'
  )
);
PREPARE _sahtout_drop_fk FROM @drop_fk;
EXECUTE _sahtout_drop_fk;
DEALLOCATE PREPARE _sahtout_drop_fk;
