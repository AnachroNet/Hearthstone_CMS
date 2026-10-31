-- Fixed teleport destinations for Admin → Character Management → Teleport Directly.
-- Also managed under Admin → Teleport Locations.

CREATE TABLE IF NOT EXISTS `admin_teleport_locations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `map` smallint unsigned NOT NULL DEFAULT '0',
  `position_x` double NOT NULL,
  `position_y` double NOT NULL,
  `position_z` double NOT NULL DEFAULT '0',
  `orientation` float NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admin_teleport_locations` (`name`, `map`, `position_x`, `position_y`, `position_z`, `orientation`, `sort_order`, `is_active`)
SELECT `name`, `map`, `position_x`, `position_y`, `position_z`, `orientation`, `sort_order`, `is_active`
FROM (
    SELECT 'Stormwind City' AS `name`, 0 AS `map`, -8913.23 AS `position_x`, 554.633 AS `position_y`, 94.7944 AS `position_z`, 0.0 AS `orientation`, 10 AS `sort_order`, 1 AS `is_active`
    UNION ALL SELECT 'Orgrimmar', 1, 1552.5, -4420.66, 8.94802, 0.0, 20, 1
    UNION ALL SELECT 'Shattrath City', 530, -1850.21, 5435.82, -10.9614, 3.40339, 30, 1
    UNION ALL SELECT 'Dalaran (Northrend)', 571, 5804.15, 624.771, 647.767, 1.64, 40, 1
    UNION ALL SELECT 'GM Island', 1, 16222.1, 16252.1, 12.5872, 0.0, 50, 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `admin_teleport_locations` LIMIT 1);
