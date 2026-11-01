<?php

/**
 * Suggested shop tokens for achievement presets (1 token ≈ 1 gold in-game).
 * Piecewise scale tuned for one-time, account-wide grants.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lfg_dbc.php';

const SAHTOUT_MAP_TYPE_RAID = 2;

/** @var array<int, int> character level => suggested tokens */
const SAHTOUT_TOKEN_LEVEL_SCALE = [
    1 => 1,
    10 => 1,
    20 => 15,
    40 => 200,
    50 => 200,
    60 => 500,
    70 => 500,
    80 => 1000,
];

/** Absolute ceiling (import clamp); only top-tier heroic dungeon rows should reach this. */
const SAHTOUT_TOKEN_SUGGEST_MAX = 1500;

/** Cap for level scale, normal 80 dungeons, and raids. */
const SAHTOUT_TOKEN_CAP_STANDARD = 1000;

const SAHTOUT_TOKEN_SUGGEST_MIN = 1;

const SAHTOUT_LFG_DUNGEON_DIFFICULTY_HEROIC = 1;

/**
 * Endgame 5-man maps (3.3+) that may use the heroic maximum when the achievement is heroic.
 *
 * @var list<int>
 */
const SAHTOUT_TOP_TIER_DUNGEON_MAP_IDS = [
    632, // Forge of Souls
    658, // Pit of Saron
    668, // Halls of Reflection
    650, // Trial of the Champion
];

/**
 * Linear interpolation between level/token breakpoints.
 */
function sahtout_suggest_tokens_from_character_level(int $level): int
{
    $level = max(1, min(80, $level));
    $scale = SAHTOUT_TOKEN_LEVEL_SCALE;
    ksort($scale);

    $prevLevel = null;
    $prevTokens = null;
    foreach ($scale as $breakpointLevel => $tokens) {
        if ($level <= $breakpointLevel) {
            if ($prevLevel === null) {
                return sahtout_clamp_standard_tokens($tokens);
            }
            $span = $breakpointLevel - $prevLevel;
            if ($span <= 0) {
                return sahtout_clamp_standard_tokens($tokens);
            }
            $ratio = ($level - $prevLevel) / $span;
            $value = (int) round($prevTokens + ($tokens - $prevTokens) * $ratio);

            return sahtout_clamp_standard_tokens($value);
        }
        $prevLevel = $breakpointLevel;
        $prevTokens = $tokens;
    }

    $last = $scale[array_key_last($scale)];

    return sahtout_clamp_standard_tokens($last);
}

function sahtout_clamp_standard_tokens(int $tokens): int
{
    return max(SAHTOUT_TOKEN_SUGGEST_MIN, min(SAHTOUT_TOKEN_CAP_STANDARD, $tokens));
}

function sahtout_achievement_title_is_heroic(?string $title): bool
{
    if ($title === null || $title === '') {
        return false;
    }

    return (bool) preg_match('/\bheroic\b/i', $title);
}

function sahtout_is_top_tier_dungeon_map(int $mapId): bool
{
    return in_array($mapId, SAHTOUT_TOP_TIER_DUNGEON_MAP_IDS, true);
}

/**
 * @param array{map_type?:int, max_players?:int}|null $mapMeta
 */
function sahtout_qualifies_for_top_heroic_token_cap(int $mapId, ?array $mapMeta, ?string $achievementTitle): bool
{
    if ($mapId <= 0 || sahtout_map_entry_is_raid($mapMeta)) {
        return false;
    }
    if (!sahtout_is_top_tier_dungeon_map($mapId)) {
        return false;
    }
    if (!sahtout_achievement_title_is_heroic($achievementTitle)) {
        return false;
    }

    return sahtout_lfg_map_has_level80_heroic_entry($mapId);
}

/** @return bool true if LFGDungeons.dbc lists a level-80 heroic row for this map. */
function sahtout_lfg_map_has_level80_heroic_entry(int $mapId): bool
{
    if ($mapId <= 0) {
        return false;
    }

    $index = sahtout_lfg_dungeon_index_by_map();
    if (!isset($index[$mapId])) {
        return false;
    }

    foreach ($index[$mapId] as $entry) {
        if ((int) ($entry['difficulty'] ?? -1) !== SAHTOUT_LFG_DUNGEON_DIFFICULTY_HEROIC) {
            continue;
        }
        $min = (int) ($entry['min'] ?? 0);
        $max = (int) ($entry['max'] ?? 0);
        if ($min >= 80 && $max >= 80) {
            return true;
        }
    }

    return false;
}

function sahtout_suggest_tokens_for_level_milestone(int $milestoneLevel): int
{
    return sahtout_suggest_tokens_from_character_level($milestoneLevel);
}

/** Map total quests completed to an approximate character level for the same scale. */
function sahtout_quest_milestone_effective_level(int $questCount): int
{
    $questCount = max(0, $questCount);

    return min(80, 10 + (int) ($questCount / 8));
}

function sahtout_suggest_tokens_for_quest_milestone(int $questCount): int
{
    return sahtout_suggest_tokens_from_character_level(sahtout_quest_milestone_effective_level($questCount));
}

/**
 * @param array{min?:int|null, max?:int|null, target?:int|null}|null $lfgLevels
 */
function sahtout_dungeon_effective_level(?array $lfgLevels): int
{
    if ($lfgLevels === null) {
        return 20;
    }
    $target = (int) ($lfgLevels['target'] ?? 0);
    if ($target > 0) {
        return min(80, $target);
    }
    $min = (int) ($lfgLevels['min'] ?? 0);
    $max = (int) ($lfgLevels['max'] ?? 0);
    if ($min > 0 && $max > 0) {
        return min(80, (int) round(($min + $max) / 2));
    }
    if ($max > 0) {
        return min(80, $max);
    }
    if ($min > 0) {
        return min(80, $min);
    }

    return 20;
}

/**
 * @param array{map_type?:int, max_players?:int}|null $mapMeta from Map.dbc
 */
function sahtout_map_entry_is_raid(?array $mapMeta): bool
{
    if ($mapMeta === null) {
        return false;
    }
    $mapType = (int) ($mapMeta['map_type'] ?? 0);
    $maxPlayers = (int) ($mapMeta['max_players'] ?? 0);

    return $mapType === SAHTOUT_MAP_TYPE_RAID || $maxPlayers >= 10;
}

/**
 * @param array{min?:int|null, max?:int|null, target?:int|null}|null $lfgLevels
 * @param array{map_type?:int, max_players?:int}|null $mapMeta
 */
function sahtout_suggest_tokens_for_dungeon(
    ?array $lfgLevels,
    ?array $mapMeta,
    int $mapId = 0,
    ?string $achievementTitle = null
): int {
    if (sahtout_qualifies_for_top_heroic_token_cap($mapId, $mapMeta, $achievementTitle)) {
        return SAHTOUT_TOKEN_SUGGEST_MAX;
    }

    if (sahtout_map_entry_is_raid($mapMeta)) {
        return SAHTOUT_TOKEN_CAP_STANDARD;
    }

    $level = sahtout_dungeon_effective_level($lfgLevels);
    if ($level >= 80) {
        return SAHTOUT_TOKEN_CAP_STANDARD;
    }

    return sahtout_suggest_tokens_from_character_level($level);
}

/**
 * @param array<string, mixed> $preset row from import preset builder
 * @param array<int, array{map_type:int, max_players:int}> $mapMetaById
 * @param array<int, array{title:string}> $achievementMeta
 */
function sahtout_suggest_tokens_for_preset(array $preset, array $mapMetaById, array $achievementMeta = []): int
{
    $type = (string) ($preset['milestone_type'] ?? '');
    if ($type === 'level') {
        $lvl = (int) ($preset['milestone_value'] ?? 0);

        return $lvl > 0 ? sahtout_suggest_tokens_for_level_milestone($lvl) : SAHTOUT_TOKEN_SUGGEST_MIN;
    }
    if ($type === 'quest_count') {
        $q = (int) ($preset['milestone_value'] ?? 0);

        return $q > 0 ? sahtout_suggest_tokens_for_quest_milestone($q) : SAHTOUT_TOKEN_SUGGEST_MIN;
    }
    if ($type === 'dungeon') {
        $mapId = (int) ($preset['instance_map_id'] ?? 0);
        $lfg = null;
        $min = (int) ($preset['dungeon_min_level'] ?? 0);
        $max = (int) ($preset['dungeon_max_level'] ?? 0);
        $target = (int) ($preset['dungeon_target_level'] ?? 0);
        if ($min > 0 || $max > 0 || $target > 0) {
            $lfg = ['min' => $min, 'max' => $max, 'target' => $target];
        }
        $meta = $mapId > 0 ? ($mapMetaById[$mapId] ?? null) : null;
        $achId = (int) ($preset['achievement_id'] ?? 0);
        $title = (string) ($achievementMeta[$achId]['title'] ?? '');

        return sahtout_suggest_tokens_for_dungeon($lfg, $meta, $mapId, $title);
    }

    return SAHTOUT_TOKEN_SUGGEST_MIN;
}
