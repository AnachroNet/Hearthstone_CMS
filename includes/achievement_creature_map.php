<?php

/**
 * Resolve dungeon instance maps for boss-kill achievements (DBC + optional world DB).
 */

const SAHTOUT_CRITERIA_KILL_CREATURE = 0;

/** Map.dbc instance type for dungeons/raids. */
const SAHTOUT_MAP_TYPE_INSTANCE = 1;

/** Treat 5-man and unset maxPlayers (0) as dungeons; exclude typical raid sizes. */
function sahtout_is_dungeon_instance_map(int $mapType, int $maxPlayers): bool
{
    if ($mapType !== SAHTOUT_MAP_TYPE_INSTANCE) {
        return false;
    }
    if (in_array($maxPlayers, [20, 25, 40], true)) {
        return false;
    }
    if ($maxPlayers === 0 || $maxPlayers <= 10) {
        return true;
    }

    return false;
}

function sahtout_normalize_dungeon_label(string $label): string
{
    $label = strtolower(trim($label));
    $label = preg_replace('/^the\s+/', '', $label) ?? $label;
    $label = preg_replace('/[^a-z0-9]+/', '', $label) ?? $label;

    return $label;
}

/**
 * Match achievement title to Map.dbc name (e.g. "Deadmines" ↔ "The Deadmines").
 *
 * @param array<int, string> $mapNames
 * @param array<int, bool> $dungeonMapIds empty = any map id in $mapNames
 */
function sahtout_guess_instance_map_from_title(string $title, array $mapNames, array $dungeonMapIds): ?int
{
    $titleNorm = sahtout_normalize_dungeon_label($title);
    if ($titleNorm === '' || strlen($titleNorm) < 4) {
        return null;
    }

    $bestId = null;
    $bestLen = 0;
    foreach ($mapNames as $mapId => $name) {
        if ($dungeonMapIds !== [] && !isset($dungeonMapIds[$mapId])) {
            continue;
        }
        $nameNorm = sahtout_normalize_dungeon_label($name);
        if ($nameNorm === '' || strlen($nameNorm) < 4) {
            continue;
        }
        $matches = $titleNorm === $nameNorm
            || str_contains($titleNorm, $nameNorm)
            || str_contains($nameNorm, $titleNorm);
        if (!$matches) {
            continue;
        }
        $len = strlen($nameNorm);
        if ($len > $bestLen) {
            $bestLen = $len;
            $bestId = $mapId;
        }
    }

    return sahtout_sanitize_map_id($bestId);
}

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
        if ($qty >= 0 && $qty <= 1) {
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
            $mid = sahtout_sanitize_map_id((int) ($row['map'] ?? 0));
            if ($cid > 0 && $mid !== null) {
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
    $achievementMapId = sahtout_sanitize_map_id($achievementMapId) ?? 0;
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
            $mapId = sahtout_sanitize_map_id($mapId);
            if ($mapId !== null && isset($dungeonMapIds[$mapId])) {
                return $mapId;
            }
        }

        return null;
    }

    return sahtout_sanitize_map_id($maps[0]);
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
