#!/usr/bin/env php
<?php
/**
 * Build achievement_catalog import SQL from WotLK DBC files in data/dbc/.
 *
 * Copy from your worldserver data folder (same 3.3.5a client extract):
 *   Achievement.dbc
 *   Achievement_Criteria.dbc
 *   Achievement_Category.dbc
 *   Map.dbc (recommended — distinguishes 5-player dungeons from raids)
 *
 * Usage (from Web/SahtoutCMS):
 *   php tools/import_achievement_catalog.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line: php tools/import_achievement_catalog.php\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'dbc_reader.php';

/** @see AzerothCore DBCEnums.h */
const CRITERIA_REACH_LEVEL = 5;
const CRITERIA_COMPLETE_QUEST_COUNT = 9;
const MAP_TYPE_INSTANCE = 1;
const LEVEL_STEP = 10;
const QUEST_STEP = 50;
const MAX_PRESET_LEVEL = 80;
const DUNGEON_MAX_PLAYERS = 5;

/** AchievementEntry field indices (3.3.5, matches AchievementEntry in DBCStructure.h). */
const ACH_FIELD_ID = 0;
const ACH_FIELD_FACTION = 1;
const ACH_FIELD_MAP = 2;
const ACH_FIELD_TITLE_ENUS = 4;
const ACH_FIELD_DESC_ENUS = 21;
const ACH_FIELD_CATEGORY = 38;
const ACH_FIELD_POINTS = 39;
const ACH_FIELD_REF = 61;

const CRIT_FIELD_ACHIEVEMENT = 1;
const CRIT_FIELD_TYPE = 2;
const CRIT_FIELD_ASSET = 3;
const CRIT_FIELD_QUANTITY = 4;

const CAT_FIELD_ID = 0;
const CAT_FIELD_NAME_ENUS = 2;

const MAP_FIELD_ID = 0;
const MAP_FIELD_TYPE = 2;
const MAP_FIELD_MAX_PLAYERS = 65;

$dbcDir = sahtout_data_dbc_dir();
if ($dbcDir === null) {
    fwrite(STDERR, "data/dbc directory not found under {$projectRoot}\n");
    exit(1);
}

$achievementPath = sahtout_find_dbc_file($dbcDir, 'Achievement.dbc');
$criteriaPath = sahtout_find_dbc_file($dbcDir, 'Achievement_Criteria.dbc');
$categoryPath = sahtout_find_dbc_file($dbcDir, 'Achievement_Category.dbc');
$mapPath = sahtout_find_dbc_file($dbcDir, 'Map.dbc');

if ($achievementPath === null) {
    fwrite(STDERR, "Missing Achievement.dbc in {$dbcDir}\n");
    exit(1);
}

try {
    [$achRows, $achStrings] = sahtout_read_wdbc_records($achievementPath);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$categories = [];
if ($categoryPath !== null) {
    try {
        [$catRows, $catStrings] = sahtout_read_wdbc_records($categoryPath);
        foreach ($catRows as $row) {
            $id = (int) ($row[CAT_FIELD_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $categories[$id] = sahtout_dbc_string($catStrings, (int) ($row[CAT_FIELD_NAME_ENUS] ?? 0));
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, "Warning: " . $e->getMessage() . " — continuing without category names.\n");
    }
}

$criteriaByAchievement = [];
if ($criteriaPath !== null) {
    try {
        [$critRows, ] = sahtout_read_wdbc_records($criteriaPath);
        foreach ($critRows as $row) {
            $achId = (int) ($row[CRIT_FIELD_ACHIEVEMENT] ?? 0);
            if ($achId <= 0) {
                continue;
            }
            $criteriaByAchievement[$achId][] = [
                'type' => (int) ($row[CRIT_FIELD_TYPE] ?? 0),
                'asset' => (int) ($row[CRIT_FIELD_ASSET] ?? 0),
                'quantity' => (int) ($row[CRIT_FIELD_QUANTITY] ?? 0),
            ];
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, "Warning: " . $e->getMessage() . " — continuing without criteria.\n");
    }
} else {
    fwrite(STDERR, "Note: Achievement_Criteria.dbc not found — criteria and presets will be limited.\n");
}

$dungeonMapIds = [];
if ($mapPath !== null) {
    try {
        [$mapRows, ] = sahtout_read_wdbc_records($mapPath);
        foreach ($mapRows as $row) {
            $mapId = (int) ($row[MAP_FIELD_ID] ?? 0);
            $mapType = (int) ($row[MAP_FIELD_TYPE] ?? 0);
            $maxPlayers = (int) ($row[MAP_FIELD_MAX_PLAYERS] ?? 0);
            if ($mapId <= 0) {
                continue;
            }
            if ($mapType === MAP_TYPE_INSTANCE && $maxPlayers > 0 && $maxPlayers <= DUNGEON_MAX_PLAYERS) {
                $dungeonMapIds[$mapId] = true;
            }
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, "Warning: " . $e->getMessage() . " — dungeon presets will use map_id only.\n");
    }
} else {
    fwrite(STDERR, "Note: Map.dbc not found — dungeon presets include any achievement with map_id > 0.\n");
}

/**
 * @param list<array{type:int, asset:int, quantity:int}> $list
 * @return array{type:?int, value:?int}
 */
function sahtout_pick_primary_criterion(array $list): array
{
    $level = null;
    $quests = null;
    foreach ($list as $c) {
        $type = $c['type'];
        if ($type === CRITERIA_REACH_LEVEL) {
            $val = $c['quantity'];
            if ($level === null || $val > $level) {
                $level = $val;
            }
        } elseif ($type === CRITERIA_COMPLETE_QUEST_COUNT) {
            $val = $c['quantity'];
            if ($quests === null || $val > $quests) {
                $quests = $val;
            }
        }
    }
    if ($level !== null) {
        return ['type' => CRITERIA_REACH_LEVEL, 'value' => $level];
    }
    if ($quests !== null) {
        return ['type' => CRITERIA_COMPLETE_QUEST_COUNT, 'value' => $quests];
    }
    return ['type' => null, 'value' => null];
}

/**
 * @param list<int> $ids
 * @param array<int, array{title:string, faction:int, points:int}> $meta
 */
function sahtout_pick_best_achievement_candidate(array $ids, array $meta, int $milestoneValue, string $kind): ?int
{
    if ($ids === []) {
        return null;
    }

    $bestId = null;
    $bestScore = PHP_INT_MIN;

    foreach ($ids as $id) {
        if (!isset($meta[$id])) {
            continue;
        }
        $m = $meta[$id];
        $title = $m['title'];
        $score = 0;

        if ($m['faction'] === -1) {
            $score += 100;
        }
        $score += min(50, (int) $m['points']);

        if ($kind === 'level') {
            if (preg_match('/\blevel\s+' . preg_quote((string) $milestoneValue, '/') . '\b/i', $title)) {
                $score += 200;
            }
            if (preg_match('/^Level ' . preg_quote((string) $milestoneValue, '/') . '$/i', trim($title))) {
                $score += 100;
            }
        } elseif ($kind === 'quest') {
            if (preg_match('/\b' . preg_quote((string) $milestoneValue, '/') . '\b/', $title)
                && preg_match('/quest/i', $title)) {
                $score += 200;
            }
        }

        $score -= (int) ($id / 10000);

        if ($score > $bestScore || ($score === $bestScore && ($bestId === null || $id < $bestId))) {
            $bestScore = $score;
            $bestId = $id;
        }
    }

    return $bestId;
}

/** @return array<int, array{title:string, faction:int, points:int, map_id:int, ref:int}> */
function sahtout_build_achievement_meta(array $achRows, string $achStrings): array
{
    $meta = [];
    foreach ($achRows as $row) {
        $id = (int) ($row[ACH_FIELD_ID] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $meta[$id] = [
            'title' => sahtout_dbc_string($achStrings, (int) ($row[ACH_FIELD_TITLE_ENUS] ?? 0)),
            'faction' => (int) ($row[ACH_FIELD_FACTION] ?? -1),
            'points' => (int) ($row[ACH_FIELD_POINTS] ?? 0),
            'map_id' => (int) ($row[ACH_FIELD_MAP] ?? -1),
            'ref' => (int) ($row[ACH_FIELD_REF] ?? 0),
        ];
    }
    return $meta;
}

/**
 * @param array<int, list<array{type:int, asset:int, quantity:int}>> $criteriaByAchievement
 * @return list<array{achievement_id:int, milestone_type:string, milestone_value:?int}>
 */
function sahtout_build_preset_rewards(
    array $criteriaByAchievement,
    array $meta,
    array $dungeonMapIds,
    bool $hasMapDbc
): array {
    $levelBuckets = [];
    $questBuckets = [];
    $maxQuest = 0;

    foreach ($criteriaByAchievement as $achId => $list) {
        foreach ($list as $c) {
            if ($c['type'] === CRITERIA_REACH_LEVEL) {
                $lvl = $c['quantity'];
                if ($lvl >= LEVEL_STEP && $lvl <= MAX_PRESET_LEVEL && $lvl % LEVEL_STEP === 0) {
                    $levelBuckets[$lvl][] = (int) $achId;
                }
            } elseif ($c['type'] === CRITERIA_COMPLETE_QUEST_COUNT) {
                $q = $c['quantity'];
                if ($q >= QUEST_STEP && $q % QUEST_STEP === 0) {
                    $questBuckets[$q][] = (int) $achId;
                    $maxQuest = max($maxQuest, $q);
                }
            }
        }
    }

    $presets = [];
    $seen = [];

    for ($lvl = LEVEL_STEP; $lvl <= MAX_PRESET_LEVEL; $lvl += LEVEL_STEP) {
        $pick = sahtout_pick_best_achievement_candidate($levelBuckets[$lvl] ?? [], $meta, $lvl, 'level');
        if ($pick !== null && !isset($seen[$pick])) {
            $presets[] = [
                'achievement_id' => $pick,
                'milestone_type' => 'level',
                'milestone_value' => $lvl,
            ];
            $seen[$pick] = true;
        }
    }

    for ($q = QUEST_STEP; $q <= $maxQuest; $q += QUEST_STEP) {
        $pick = sahtout_pick_best_achievement_candidate($questBuckets[$q] ?? [], $meta, $q, 'quest');
        if ($pick !== null && !isset($seen[$pick])) {
            $presets[] = [
                'achievement_id' => $pick,
                'milestone_type' => 'quest_count',
                'milestone_value' => $q,
            ];
            $seen[$pick] = true;
        }
    }

    foreach ($meta as $achId => $m) {
        if ($m['ref'] !== 0 || $m['points'] <= 0) {
            continue;
        }
        $mapId = $m['map_id'];
        if ($mapId <= 0) {
            continue;
        }
        if ($hasMapDbc) {
            if (!isset($dungeonMapIds[$mapId])) {
                continue;
            }
        }
        if (isset($seen[$achId])) {
            continue;
        }
        if (!isset($criteriaByAchievement[$achId]) || $criteriaByAchievement[$achId] === []) {
            continue;
        }
        $presets[] = [
            'achievement_id' => $achId,
            'milestone_type' => 'dungeon',
            'milestone_value' => null,
        ];
        $seen[$achId] = true;
    }

    usort($presets, static function (array $a, array $b): int {
        $order = ['level' => 0, 'quest_count' => 1, 'dungeon' => 2];
        $ta = $order[$a['milestone_type']] ?? 9;
        $tb = $order[$b['milestone_type']] ?? 9;
        if ($ta !== $tb) {
            return $ta <=> $tb;
        }
        $va = $a['milestone_value'] ?? 0;
        $vb = $b['milestone_value'] ?? 0;
        if ($va !== $vb) {
            return $va <=> $vb;
        }
        return $a['achievement_id'] <=> $b['achievement_id'];
    });

    return $presets;
}

$achievementMeta = sahtout_build_achievement_meta($achRows, $achStrings);
$presets = sahtout_build_preset_rewards(
    $criteriaByAchievement,
    $achievementMeta,
    $dungeonMapIds,
    $mapPath !== null
);

$outDir = $projectRoot . DIRECTORY_SEPARATOR . 'SQL' . DIRECTORY_SEPARATOR . 'Sahtout_site DATABASE'
    . DIRECTORY_SEPARATOR . 'SINGLE UPDATE';
$outFile = $outDir . DIRECTORY_SEPARATOR . 'achievement_catalog_import.sql';

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create output directory: {$outDir}\n");
    exit(1);
}

$lines = [];
$lines[] = '-- Generated by tools/import_achievement_catalog.php — do not edit by hand.';
$lines[] = '-- Source DBC dir: ' . str_replace('\\', '/', $dbcDir);
$lines[] = '-- Records: ' . count($achRows);
$lines[] = '';
$lines[] = 'SET NAMES utf8mb4;';
$lines[] = 'TRUNCATE TABLE `achievement_catalog`;';
$lines[] = '';

$insertPrefix = 'INSERT INTO `achievement_catalog` (`achievement_id`, `title_enus`, `description_enus`, '
    . '`category_id`, `category_name`, `points`, `required_faction`, `map_id`, `ref_achievement`, '
    . '`criteria_type`, `criteria_value`) VALUES';

$valueRows = [];
$milestoneCount = 0;
foreach ($achRows as $row) {
    $id = (int) ($row[ACH_FIELD_ID] ?? 0);
    if ($id <= 0) {
        continue;
    }

    $title = sahtout_dbc_string($achStrings, (int) ($row[ACH_FIELD_TITLE_ENUS] ?? 0));
    $desc = sahtout_dbc_string($achStrings, (int) ($row[ACH_FIELD_DESC_ENUS] ?? 0));
    $categoryId = (int) ($row[ACH_FIELD_CATEGORY] ?? 0);
    $categoryName = $categories[$categoryId] ?? null;
    $points = (int) ($row[ACH_FIELD_POINTS] ?? 0);
    $faction = (int) ($row[ACH_FIELD_FACTION] ?? -1);
    $mapId = (int) ($row[ACH_FIELD_MAP] ?? -1);
    $refAch = (int) ($row[ACH_FIELD_REF] ?? 0);

    $primary = sahtout_pick_primary_criterion($criteriaByAchievement[$id] ?? []);
    $critType = $primary['type'];
    $critVal = $primary['value'];
    if ($critType !== null) {
        $milestoneCount++;
    }

    $titleSql = "'" . sahtout_sql_escape($title) . "'";
    if ($desc !== '' && strlen($desc) > 500) {
        $desc = substr($desc, 0, 500);
    }
    $descSql = $desc === '' ? 'NULL' : ("'" . sahtout_sql_escape($desc) . "'");
    $catNameSql = ($categoryName === null || $categoryName === '')
        ? 'NULL'
        : ("'" . sahtout_sql_escape($categoryName) . "'");
    $critTypeSql = $critType === null ? 'NULL' : (string) $critType;
    $critValSql = $critVal === null ? 'NULL' : (string) $critVal;

    $valueRows[] = sprintf(
        '(%u, %s, %s, %u, %s, %u, %d, %d, %u, %s, %s)',
        $id,
        $titleSql,
        $descSql,
        $categoryId,
        $catNameSql,
        min(255, max(0, $points)),
        $faction,
        $mapId,
        $refAch,
        $critTypeSql,
        $critValSql
    );
}

$batchSize = 100;
for ($i = 0, $n = count($valueRows); $i < $n; $i += $batchSize) {
    $chunk = array_slice($valueRows, $i, $batchSize);
    $lines[] = $insertPrefix;
    $lines[] = implode(",\n", $chunk) . ';';
    $lines[] = '';
}

$lines[] = '-- Preset token reward rows (tokens=0, is_enabled=0 until configured). Manual rows are kept.';
$lines[] = "DELETE FROM `achievement_token_rewards` WHERE `milestone_type` IN ('level', 'quest_count', 'dungeon');";
$lines[] = '';

if ($presets !== []) {
    $rewardPrefix = 'INSERT INTO `achievement_token_rewards` (`achievement_id`, `tokens`, `milestone_type`, `milestone_value`, `is_enabled`) VALUES';
    $rewardRows = [];
    foreach ($presets as $p) {
        $valSql = $p['milestone_value'] === null ? 'NULL' : (string) $p['milestone_value'];
        $rewardRows[] = sprintf(
            '(%u, 0, %s, %s, 0)',
            $p['achievement_id'],
            "'" . sahtout_sql_escape($p['milestone_type']) . "'",
            $valSql
        );
    }
    for ($i = 0, $n = count($rewardRows); $i < $n; $i += $batchSize) {
        $chunk = array_slice($rewardRows, $i, $batchSize);
        $lines[] = $rewardPrefix;
        $lines[] = implode(",\n", $chunk) . ';';
        $lines[] = '';
    }
}

$sql = implode("\n", $lines);
if (file_put_contents($outFile, $sql) === false) {
    fwrite(STDERR, "Failed to write {$outFile}\n");
    exit(1);
}

$presetCounts = ['level' => 0, 'quest_count' => 0, 'dungeon' => 0];
foreach ($presets as $p) {
    $presetCounts[$p['milestone_type']]++;
}

fwrite(STDOUT, "Wrote " . count($valueRows) . " achievements to:\n  {$outFile}\n");
fwrite(STDOUT, "{$milestoneCount} catalog rows have criteria_type 5 (level) or 9 (quest count).\n");
fwrite(STDOUT, sprintf(
    "Preset rewards: %d level (every %d), %d quest (every %d), %d dungeon.\n",
    $presetCounts['level'],
    LEVEL_STEP,
    $presetCounts['quest_count'],
    QUEST_STEP,
    $presetCounts['dungeon']
));
fwrite(STDOUT, "Run achievement_catalog.sql on sahtout_site once, then import achievement_catalog_import.sql.\n");

exit(0);
