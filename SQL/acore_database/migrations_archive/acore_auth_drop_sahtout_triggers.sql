-- Optional cleanup for acore_auth after upgrading SahtoutCMS.
-- New installs do not need any SQL on acore_auth for website shop accounts.
-- Legacy installs may have created these triggers from acore_auth_sahtout_site.sql.

USE `acore_auth`;

DROP TRIGGER IF EXISTS `after_account_insert`;
DROP TRIGGER IF EXISTS `after_account_update`;
