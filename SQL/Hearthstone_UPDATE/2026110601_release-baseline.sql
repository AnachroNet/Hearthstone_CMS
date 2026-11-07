-- Hearthstone CMS release 2026110601 (2026-11-06, update 01)
-- Run against your site database (hearthstone_site). No schema changes — records the code release
-- that was deployed before numbered SQL updates were introduced.
--
-- If you already shipped adopt-a-bot code on this date without a site.release row, apply this once
-- so later updates (e.g. 2026110701_adopt-a-bot.sql) know your starting point.

INSERT INTO `site_settings` (
  `setting_key`, `setting_value`, `value_type`, `category`, `label`, `is_public`, `updated_by`
) VALUES (
  'site.release', '2026110601', 'int', 'general', 'Applied CMS release', 0, NULL
)
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `value_type` = VALUES(`value_type`),
  `label` = VALUES(`label`),
  `is_public` = VALUES(`is_public`);
