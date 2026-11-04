-- GM-only teleport destinations (visible to website moderators/admins on Account → Characters).
-- Safe to run once on existing installs.

ALTER TABLE `admin_teleport_locations`
  ADD COLUMN `is_gm_only` tinyint(1) NOT NULL DEFAULT '0' AFTER `is_active`;

UPDATE `admin_teleport_locations`
SET `is_gm_only` = 1
WHERE `name` = 'GM Island';
