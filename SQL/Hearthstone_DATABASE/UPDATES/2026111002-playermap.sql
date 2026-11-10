-- Hearthstone CMS v1.1 release 2026111002 (2026-11-10, update 02) — Live player map settings
-- Run against your site database (hearthstone_site).

INSERT INTO `site_settings` (
  `setting_key`, `setting_value`, `value_type`, `category`, `label`, `is_public`, `updated_by`
) VALUES
  ('playermap.enabled', '0', 'bool', 'general', 'Live player map enabled', 0, NULL),
  ('playermap.faction_visibility', 'both', 'string', 'general', 'Player map faction filter', 0, NULL),
  ('site.release', '2026111002', 'int', 'general', 'Applied CMS release', 0, NULL)
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `value_type` = VALUES(`value_type`),
  `label` = VALUES(`label`),
  `is_public` = VALUES(`is_public`);
