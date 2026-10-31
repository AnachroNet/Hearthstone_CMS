<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

function sahtout_admin_teleport_locations_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'admin_teleport_locations'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

/**
 * @return list<array<string, mixed>>
 */
function sahtout_fetch_admin_teleport_locations(mysqli $siteDb, bool $activeOnly = true): array
{
    if (!sahtout_admin_teleport_locations_table_exists($siteDb)) {
        return [];
    }

    $sql = 'SELECT id, name, map, position_x, position_y, position_z, orientation, sort_order, is_active
            FROM admin_teleport_locations';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $res = $siteDb->query($sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = sahtout_normalize_admin_teleport_location_row($row);
    }
    $res->free();

    return $rows;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sahtout_normalize_admin_teleport_location_row(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'map' => (int) ($row['map'] ?? 0),
        'position_x' => (float) ($row['position_x'] ?? 0),
        'position_y' => (float) ($row['position_y'] ?? 0),
        'position_z' => (float) ($row['position_z'] ?? 0),
        'orientation' => (float) ($row['orientation'] ?? 0),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'is_active' => (int) ($row['is_active'] ?? 0) === 1,
    ];
}

function sahtout_admin_teleport_location_by_id(mysqli $siteDb, int $id, bool $activeOnly = true): ?array
{
    if ($id <= 0 || !sahtout_admin_teleport_locations_table_exists($siteDb)) {
        return null;
    }

    $sql = 'SELECT id, name, map, position_x, position_y, position_z, orientation, sort_order, is_active
            FROM admin_teleport_locations WHERE id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }

    $stmt = $siteDb->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) {
        $res->free();
    }
    $stmt->close();

    return $row ? sahtout_normalize_admin_teleport_location_row($row) : null;
}
