-- Optional: set default server timezone for the CMS (IANA name).
-- Admin → Settings → General can change this without editing config.local.php.

INSERT INTO `site_settings` (`setting_key`, `setting_value`, `value_type`, `category`, `label`, `is_public`)
VALUES ('site.timezone', 'America/New_York', 'string', 'general', 'Server timezone', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
