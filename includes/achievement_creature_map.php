<?php

/**
 * Resolve dungeon instance maps for boss-kill achievements (DBC + optional world DB).
 */

const SAHTOUT_CRITERIA_KILL_CREATURE = 0;

/** CLI / import: connect to world DB when config.local.php exists. */
function sahtout_import_connect_world_db(string $projectRoot): ?mysqli
{
    if (!defined('ALLOWED_ACCESS')) {
        define('ALLOWED_ACCESS', true);
    }

    $localPath = $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.local.php';
    if (!is_file($localPath)) {
        return null;
    }

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'config_local_io.php';
    $local = sahtout_load_local_config();
    $d = $local['database']['world'] ?? [];
    $host = (string) ($d['host'] ?? 'localhost');
    $port = (int) ($d['port'] ?? 3306);
    $user = (string) ($d['user'] ?? '');
    $pass = (string) ($d['pass'] ?? '');
    $name = (string) ($d['name'] ?? 'acore_world');

    $db = @new mysqli($host, $user, $pass, $name, $port);
    if ($db->connect_error) {
        fwrite(STDERR, "Note: world DB unavailable for creature map lookup: {$db->connect_error}\n");

        return null;
    }

    return $db;
}

/**
 * @param list<array{type:int, asset:int, quantity:int}> $criteria
 */
function sahtout_pick_boss_creature_id(array $criteria): ?int
{
    $candidates = [];
    foreach ($criteria as $c) {
        if (($c['type'] ?? -1) !== SAHTOUT_CRITERIA_KILL_CREATURE) {
            continue;
        }
        $entry = (int) ($c['asset'] ?? 0);
        $qty = (int) ($c['quantity'] ?? 0);
        if ($entry <= 0) {
            continue;
        }
        if ($qty === 1 || $qty === 0) {
            $candidates[] = $entry;
        }
    }

    if ($candidates === []) {
        return null;
    }

    if (count($candidates) === 1) {
        return $candidates[0];
    }

    return max($candidates);
}

/**
 * @param list<int> $creatureIds
 * @return array<int, list<int>> creature entry => map ids from world.creature spawns
 */
function sahtout_load_creature_instance_maps(?mysqli $worldDb, array $creatureIds): array
{
    $creatureIds = array_values(array_unique(array_filter(array_map('intval', $creatureIds), static fn (int $id): bool => $id > 0)));
    if ($worldDb === null || $creatureIds === []) {
        return [];
    }

    $tableCheck = $worldDb->query("SHOW TABLES LIKE 'creature'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        $tableCheck?->free();

        return [];
    }
    $tableCheck->free();

    $map = [];
    $chunkSize = 200;
    for ($i = 0, $n = count($creatureIds); $i < $n; $i += $chunkSize) {
        $chunk = array_slice($creatureIds, $i, $chunkSize);
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT DISTINCT id, map FROM creature WHERE id IN ($placeholders) AND map > 0";
        $stmt = $worldDb->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $types = str_repeat('i', count($chunk));
        $stmt->bind_param($types, ...$chunk);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $cid = (int) ($row['id'] ?? 0);
            $mid = (int) ($row['map'] ?? 0);
            if ($cid > 0 && $mid > 0) {
                $map[$cid][] = $mid;
            }
        }
        $stmt->close();
    }

    foreach ($map as $cid => $maps) {
        $map[$cid] = array_values(array_unique($maps));
    }

    return $map;
}

/**
 * @param array<int, list<int>> $creatureToMaps
 * @param array<int, bool> $dungeonMapIds
 */
function sahtout_resolve_instance_map_id(
    int $achievementMapId,
    ?int $bossCreatureId,
    array $creatureToMaps,
    array $dungeonMapIds,
    bool $requireDungeonMap
): ?int {
    if ($achievementMapId > 0) {
        if (!$requireDungeonMap || isset($dungeonMapIds[$achievementMapId])) {
            return $achievementMapId;
        }
    }

    if ($bossCreatureId === null || $bossCreatureId <= 0) {
        return null;
    }

    $maps = $creatureToMaps[$bossCreatureId] ?? [];
    if ($maps === []) {
        return null;
    }

    if ($requireDungeonMap && $dungeonMapIds !== []) {
        foreach ($maps as $mapId) {
            if (isset($dungeonMapIds[$mapId])) {
                return $mapId;
            }
        }

        return null;
    }

    return $maps[0];
}

/**
 * @param array<int, list<array{type:int, asset:int, quantity:int}>> $criteriaByAchievement
 * @return list<int>
 */
function sahtout_collect_boss_creature_ids(array $criteriaByAchievement): array
{
    $ids = [];
    foreach ($criteriaByAchievement as $list) {
        $boss = sahtout_pick_boss_creature_id($list);
        if ($boss !== null) {
            $ids[] = $boss;
        }
    }

    return $ids;
}
