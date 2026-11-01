<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lfg_dbc.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'achievement_creature_map.php';

function sahtout_admin_achievement_rewards_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'achievement_token_rewards'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_admin_achievement_catalog_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'achievement_catalog'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

/** @return list<string> */
function sahtout_admin_achievement_milestone_types(): array
{
    return ['level', 'quest_count', 'dungeon', 'manual'];
}

function sahtout_admin_normalize_milestone_type(string $type): ?string
{
    $type = strtolower(trim($type));
    if ($type === 'other') {
        return 'manual';
    }
    if (in_array($type, sahtout_admin_achievement_milestone_types(), true)) {
        return $type;
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function sahtout_admin_achievement_catalog_row(mysqli $siteDb, int $achievementId): ?array
{
    if ($achievementId <= 0 || !sahtout_admin_achievement_catalog_table_exists($siteDb)) {
        return null;
    }
    $stmt = $siteDb->prepare(
        'SELECT achievement_id, title_enus, map_id, criteria_type, criteria_value,
                boss_creature_id, instance_map_id
         FROM achievement_catalog WHERE achievement_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $achievementId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

/**
 * @return list<array<string, mixed>>
 */
function sahtout_admin_fetch_achievement_rewards(mysqli $siteDb): array
{
    if (!sahtout_admin_achievement_rewards_table_exists($siteDb)) {
        return [];
    }

    $joinCatalog = sahtout_admin_achievement_catalog_table_exists($siteDb);
    $sql = $joinCatalog
        ? 'SELECT r.achievement_id, r.tokens, r.milestone_type, r.milestone_value, r.instance_map_id,
                  r.dungeon_name, r.dungeon_min_level, r.dungeon_max_level, r.dungeon_target_level,
                  r.is_enabled, c.title_enus, c.description_enus, c.map_id AS catalog_map_id,
                  c.boss_creature_id, c.instance_map_id AS catalog_instance_map_id
           FROM achievement_token_rewards r
           LEFT JOIN achievement_catalog c ON c.achievement_id = r.achievement_id
           ORDER BY FIELD(r.milestone_type, \'level\', \'quest_count\', \'dungeon\', \'manual\'),
                    COALESCE(r.milestone_value, 999999), COALESCE(c.title_enus, \'\'), r.achievement_id'
        : 'SELECT achievement_id, tokens, milestone_type, milestone_value, instance_map_id,
                  dungeon_name, is_enabled
           FROM achievement_token_rewards
           ORDER BY FIELD(milestone_type, \'level\', \'quest_count\', \'dungeon\', \'manual\'),
                    COALESCE(milestone_value, 999999), achievement_id';

    $res = $siteDb->query($sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();

    return $rows;
}

/**
 * @param list<array<string, mixed>> $rewardRows
 * @return array<int, string> creature entry => name
 */
function sahtout_admin_creature_names_for_rewards(?mysqli $worldDb, array $rewardRows): array
{
    $ids = [];
    foreach ($rewardRows as $row) {
        $bossId = (int) ($row['boss_creature_id'] ?? 0);
        if ($bossId > 0) {
            $ids[] = $bossId;
        }
    }

    return sahtout_load_creature_template_names($worldDb, $ids);
}

/**
 * @return array{ok:bool, message:string, achievements:list<array<string,mixed>>}
 */
function sahtout_admin_search_achievement_catalog(mysqli $siteDb, string $query, int $limit = 25): array
{
    if (!sahtout_admin_achievement_catalog_table_exists($siteDb)) {
        return ['ok' => false, 'message' => 'catalog_missing', 'achievements' => []];
    }

    $query = trim($query);
    if ($query === '') {
        return ['ok' => true, 'message' => 'empty', 'achievements' => []];
    }

    $limit = max(1, min(50, $limit));

    if (ctype_digit($query)) {
        $id = (int) $query;
        $stmt = $siteDb->prepare(
            'SELECT achievement_id, title_enus, criteria_type, criteria_value, map_id,
                boss_creature_id, instance_map_id
             FROM achievement_catalog
             WHERE achievement_id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'search_failed', 'achievements' => []];
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = sahtout_admin_format_catalog_search_row($row);
        }
        $stmt->close();

        return ['ok' => true, 'message' => 'ok', 'achievements' => $items];
    }

    $like = '%' . $query . '%';
    $stmt = $siteDb->prepare(
        'SELECT achievement_id, title_enus, criteria_type, criteria_value, map_id,
                boss_creature_id, instance_map_id
         FROM achievement_catalog
         WHERE title_enus LIKE ?
            OR description_enus LIKE ?
         ORDER BY title_enus ASC
         LIMIT ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'search_failed', 'achievements' => []];
    }
    $stmt->bind_param('ssi', $like, $like, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = sahtout_admin_format_catalog_search_row($row);
    }
    $stmt->close();

    return ['ok' => true, 'message' => 'ok', 'achievements' => $items];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function sahtout_admin_format_catalog_search_row(array $row): array
{
    return [
        'id' => (int) ($row['achievement_id'] ?? 0),
        'title' => (string) ($row['title_enus'] ?? ''),
        'criteria_type' => isset($row['criteria_type']) ? (int) $row['criteria_type'] : null,
        'criteria_value' => isset($row['criteria_value']) ? (int) $row['criteria_value'] : null,
        'map_id' => (int) ($row['map_id'] ?? 0),
        'boss_creature_id' => isset($row['boss_creature_id']) ? (int) $row['boss_creature_id'] : null,
        'instance_map_id' => isset($row['instance_map_id']) ? (int) $row['instance_map_id'] : null,
    ];
}

/**
 * Resolve instance map when adding a dungeon reward (catalog + optional live world DB).
 */
function sahtout_admin_resolve_catalog_instance_map(mysqli $siteDb, ?mysqli $worldDb, array $catalogRow): ?int
{
    $instance = (int) ($catalogRow['instance_map_id'] ?? 0);
    if ($instance > 0) {
        return $instance;
    }
    $achMap = (int) ($catalogRow['map_id'] ?? 0);
    if ($achMap > 0) {
        return $achMap;
    }
    $bossId = isset($catalogRow['boss_creature_id']) ? (int) $catalogRow['boss_creature_id'] : 0;
    if ($bossId <= 0 || $worldDb === null || $worldDb->connect_error) {
        return null;
    }
    $maps = sahtout_load_creature_instance_maps($worldDb, [$bossId]);

    return sahtout_resolve_instance_map_id($achMap, $bossId, $maps, [], false);
}

/** Subtitle under milestone type pill (dungeon level from DB or LFGDungeons.dbc). */
function sahtout_admin_milestone_type_subtitle(array $row): ?string
{
    $type = (string) ($row['milestone_type'] ?? '');
    if ($type === 'dungeon') {
        $min = (int) ($row['dungeon_min_level'] ?? 0);
        $max = (int) ($row['dungeon_max_level'] ?? 0);
        $target = (int) ($row['dungeon_target_level'] ?? 0);
        if ($min > 0 || $max > 0 || $target > 0) {
            $caption = sahtout_format_dungeon_level_caption([
                'min' => $min,
                'max' => $max,
                'target' => $target,
            ]);
            if ($caption !== null) {
                return $caption;
            }
        }
        $mapId = (int) ($row['instance_map_id'] ?? 0);
        if ($mapId <= 0) {
            $mapId = (int) ($row['catalog_instance_map_id'] ?? 0);
        }
        if ($mapId <= 0) {
            $mapId = (int) ($row['catalog_map_id'] ?? 0);
        }
        if ($mapId > 0) {
            return sahtout_format_dungeon_level_caption(sahtout_lfg_dungeon_levels_for_map($mapId));
        }

        return null;
    }

    if ($type === 'level') {
        $value = isset($row['milestone_value']) ? (int) $row['milestone_value'] : 0;

        return $value > 0 ? 'Level ' . $value : null;
    }

    if ($type === 'quest_count') {
        $value = isset($row['milestone_value']) ? (int) $row['milestone_value'] : 0;

        return $value > 0 ? number_format($value) . ' quests' : null;
    }

    return null;
}

function sahtout_admin_reward_is_enabled(array $row): bool
{
    return (int) ($row['is_enabled'] ?? 0) === 1;
}

function sahtout_admin_reward_detail_label(array $row, array $creatureNames = []): string
{
    $type = (string) ($row['milestone_type'] ?? '');
    $value = isset($row['milestone_value']) ? (int) $row['milestone_value'] : null;

    return match ($type) {
        'level' => $value !== null ? 'Level ' . $value : '—',
        'quest_count' => $value !== null ? number_format($value) . ' quests' : '—',
        'dungeon' => (static function () use ($row, $creatureNames): string {
            $parts = [];
            $name = (string) ($row['dungeon_name'] ?? '');
            if ($name !== '') {
                $parts[] = $name;
            }
            $bossId = (int) ($row['boss_creature_id'] ?? 0);
            if ($bossId > 0) {
                $bossLabel = $creatureNames[$bossId] ?? null;
                $parts[] = ($bossLabel !== null && $bossLabel !== '') ? $bossLabel : ('Boss ' . $bossId);
            }
            if ($parts !== []) {
                return implode(' · ', $parts);
            }
            $mapId = (int) ($row['instance_map_id'] ?? 0);
            if ($mapId <= 0) {
                $mapId = (int) ($row['catalog_instance_map_id'] ?? 0);
            }

            return $mapId > 0 ? 'Map ' . $mapId : '—';
        })(),
        default => '—',
    };
}
