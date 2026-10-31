<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

/**
 * WotLK 3.3.5 character talents (character_talent + optional Talent/TalentTab DBC).
 */

function sahtout_find_dbc_file(string $dir, string $fileName): ?string
{
    $path = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (is_readable($path)) {
        return $path;
    }
    if (!is_dir($dir)) {
        return null;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (strcasecmp($entry, $fileName) === 0) {
            $match = $dir . DIRECTORY_SEPARATOR . $entry;
            return is_readable($match) ? $match : null;
        }
    }
    return null;
}

function sahtout_talent_dbc_dir(): ?string
{
    $candidates = [];
    if (defined('TALENT_DBC_DIR') && TALENT_DBC_DIR !== '') {
        $candidates[] = rtrim(TALENT_DBC_DIR, '/\\');
    }
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'dbc';

    foreach ($candidates as $dir) {
        if (sahtout_find_dbc_file($dir, 'Talent.dbc') !== null
            && sahtout_find_dbc_file($dir, 'TalentTab.dbc') !== null) {
            return $dir;
        }
    }
    return null;
}

function sahtout_read_wdbc_records(string $path): array
{
    $blob = @file_get_contents($path);
    if ($blob === false || strlen($blob) < 20) {
        throw new RuntimeException('Unreadable DBC: ' . basename($path));
    }
    $magic = substr($blob, 0, 4);
    if ($magic !== 'WDBC') {
        throw new RuntimeException('Not WDBC: ' . basename($path));
    }
    $header = unpack('Vrec/Vfield/VrecSize/VstrSize', substr($blob, 4, 16));
    $nRec = (int) $header['rec'];
    $nField = (int) $header['field'];
    $recSize = (int) $header['recSize'];
    $strSize = (int) $header['strSize'];
    $body = 20;
    $strings = substr($blob, $body + $nRec * $recSize, $strSize);
    $records = [];
    for ($i = 0; $i < $nRec; $i++) {
        $offset = $body + $i * $recSize;
        $chunk = substr($blob, $offset, $recSize);
        $vals = unpack('V' . $nField, $chunk);
        $records[] = array_values($vals);
    }
    return [$records, $strings];
}

function sahtout_dbc_string(string $strings, int $offset): string
{
    if ($offset < 0 || $offset >= strlen($strings)) {
        return '';
    }
    $end = strpos($strings, "\0", $offset);
    if ($end === false) {
        $end = strlen($strings);
    }
    return substr($strings, $offset, $end - $offset);
}

function sahtout_talent_spell_map(): array
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }

    $cacheFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'wotlk_talent_map.php';
    if (is_readable($cacheFile)) {
        try {
            $data = include $cacheFile;
            if (is_array($data) && isset($data['spell_tab'], $data['tab_info'])) {
                return $loaded = $data;
            }
        } catch (Throwable $e) {
            error_log('[character_talents] talent map cache invalid: ' . $e->getMessage());
            @unlink($cacheFile);
        }
    }

    $empty = ['spell_tab' => [], 'tab_info' => []];
    $dbcDir = sahtout_talent_dbc_dir();
    if ($dbcDir === null) {
        return $loaded = $empty;
    }

    $talentDbc = sahtout_find_dbc_file($dbcDir, 'Talent.dbc');
    $tabDbc = sahtout_find_dbc_file($dbcDir, 'TalentTab.dbc');
    if ($talentDbc === null || $tabDbc === null) {
        return $loaded = $empty;
    }

    try {
        [$tal, ] = sahtout_read_wdbc_records($talentDbc);
        [$tabs, $tabStrings] = sahtout_read_wdbc_records($tabDbc);
    } catch (Throwable $e) {
        error_log('[character_talents] DBC read failed: ' . $e->getMessage());
        return $loaded = $empty;
    }

    $spellTab = [];
    foreach ($tal as $row) {
        if (count($row) < 13) {
            continue;
        }
        $tabId = (int) $row[1];
        for ($rankIndex = 0; $rankIndex < 9; $rankIndex++) {
            $spellId = (int) $row[4 + $rankIndex];
            if ($spellId > 0) {
                $spellTab[$spellId] = [$tabId, $rankIndex + 1];
            }
        }
    }

    $tabInfo = [];
    foreach ($tabs as $row) {
        if (count($row) < 23) {
            continue;
        }
        $tabId = (int) $row[0];
        $tabInfo[$tabId] = [
            'name' => sahtout_dbc_string($tabStrings, (int) $row[1]),
            'page' => (int) $row[22],
            'classMask' => (int) $row[20],
            'iconId' => (int) $row[18],
        ];
    }

    $loaded = ['spell_tab' => $spellTab, 'tab_info' => $tabInfo];

    $cacheDir = dirname($cacheFile);
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        $export = var_export($loaded, true);
        @file_put_contents($cacheFile, "<?php\nreturn {$export};\n");
    }

    return $loaded;
}

function sahtout_character_talents_table_exists(mysqli $charDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $exists = false;
    $res = $charDb->query("SHOW TABLES LIKE 'character_talent'");
    if ($res && $res->num_rows > 0) {
        $exists = true;
    }
    if ($res) {
        $res->free();
    }
    return $exists;
}

function sahtout_wow_class_mask(int $classId): int
{
    if ($classId < 1 || $classId > 11) {
        return 0;
    }
    return 1 << ($classId - 1);
}

function sahtout_fetch_spell_meta(mysqli $worldDb, array $spellIds): array
{
    if ($spellIds === []) {
        return [];
    }
    $tableCheck = $worldDb->query("SHOW TABLES LIKE 'armory_spell'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        if ($tableCheck) {
            $tableCheck->free();
        }
        return [];
    }
    $tableCheck->free();

    $spellIds = array_values(array_unique(array_map('intval', $spellIds)));
    $placeholders = implode(',', array_fill(0, count($spellIds), '?'));
    $stmt = $worldDb->prepare(
        "SELECT id, SpellName_en_gb, Rank_1, SpellIconID FROM armory_spell WHERE id IN ($placeholders)"
    );
    if (!$stmt) {
        return [];
    }
    $types = str_repeat('i', count($spellIds));
    $stmt->bind_param($types, ...$spellIds);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int) $row['id']] = $row;
    }
    $res->free();
    $stmt->close();
    return $out;
}

/**
 * @return array<string, mixed>
 */
function sahtout_character_talent_view(mysqli $charDb, ?mysqli $worldDb, int $guid, int $classId, int $level): array
{
    $view = [
        'available' => false,
        'has_dual_spec' => false,
        'active_spec' => 0,
        'specs' => [],
        'flat_only' => true,
        'message' => null,
    ];

    if ($guid <= 0 || !sahtout_character_talents_table_exists($charDb)) {
        $view['message'] = 'missing_table';
        error_log('[character_talents] character_talent table missing or invalid guid');
        return $view;
    }

    $charRow = null;
    $stmt = $charDb->prepare('SELECT activeTalentGroup, talentGroupsCount FROM characters WHERE guid = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $guid);
        $stmt->execute();
        $res = $stmt->get_result();
        $charRow = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();
    } else {
        error_log('[character_talents] characters spec query failed: ' . $charDb->error);
        $stmt = $charDb->prepare('SELECT activeTalentGroup FROM characters WHERE guid = ? LIMIT 1');
        if (!$stmt) {
            $view['message'] = 'query_failed';
            error_log('[character_talents] characters fallback query failed: ' . $charDb->error);
            return $view;
        }
        $stmt->bind_param('i', $guid);
        $stmt->execute();
        $res = $stmt->get_result();
        $charRow = $res ? $res->fetch_assoc() : null;
        if ($res) {
            $res->free();
        }
        $stmt->close();
    }

    $activeSpec = (int) ($charRow['activeTalentGroup'] ?? 0);
    $talentGroupsCount = max(1, (int) ($charRow['talentGroupsCount'] ?? 1));
    $hasDual = $talentGroupsCount > 1;
    $view['active_spec'] = max(0, min(1, $activeSpec));
    $view['has_dual_spec'] = $hasDual;

    $specIndices = $hasDual ? [0, 1] : [0];
    $map = sahtout_talent_spell_map();
    $spellTab = $map['spell_tab'];
    $tabInfo = $map['tab_info'];
    $view['flat_only'] = ($spellTab === [] || $tabInfo === []);
    $classMask = sahtout_wow_class_mask($classId);

    $allSpellIds = [];
    $rowsBySpec = [];
    foreach ($specIndices as $specIndex) {
        $stmt = $charDb->prepare(
            'SELECT spell, specMask FROM character_talent WHERE guid = ?'
        );
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $guid);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $specMask = (int) $row['specMask'];
            $bit = 1 << $specIndex;
            $inSpec = ($specMask & $bit) !== 0;
            if (!$inSpec && $specMask === 0 && $specIndex === 0) {
                $inSpec = true;
            }
            if ($inSpec) {
                $rows[] = (int) $row['spell'];
            }
        }
        if ($res) {
            $res->free();
        }
        $stmt->close();
        $rowsBySpec[$specIndex] = $rows;
        foreach ($rows as $sid) {
            $allSpellIds[] = $sid;
        }
    }

    $spellMeta = ($worldDb instanceof mysqli) ? sahtout_fetch_spell_meta($worldDb, $allSpellIds) : [];

    foreach ($specIndices as $specIndex) {
        $spentByTab = [];
        $talentRows = [];
        foreach ($rowsBySpec[$specIndex] as $spellId) {
            $meta = $spellMeta[$spellId] ?? null;
            $name = trim((string) ($meta['SpellName_en_gb'] ?? ''));
            if ($name === '') {
                $name = 'Spell #' . $spellId;
            }
            $rankLabel = trim((string) ($meta['Rank_1'] ?? ''));
            $rank = 1;
            if (preg_match('/(\d+)/', $rankLabel, $m)) {
                $rank = max(1, (int) $m[1]);
            }
            $tabId = null;
            $tabPage = 0;
            if (isset($spellTab[$spellId])) {
                [$tabId, $rank] = $spellTab[$spellId];
                $tabPage = (int) ($tabInfo[$tabId]['page'] ?? 0);
            }
            $talentRows[] = [
                'spell_id' => $spellId,
                'name' => $name,
                'rank' => $rank,
                'rank_label' => $rankLabel,
                'tab_id' => $tabId,
                'tab_page' => $tabPage,
                'icon_id' => (int) ($meta['SpellIconID'] ?? 0),
            ];
            if ($tabId !== null) {
                $spentByTab[$tabId] = ($spentByTab[$tabId] ?? 0) + $rank;
            }
        }

        $pages = [0, 0, 0];
        $trees = [];
        if (!$view['flat_only']) {
            foreach ($tabInfo as $tabId => $info) {
                if ($classMask > 0 && ((int) $info['classMask'] & $classMask) === 0) {
                    continue;
                }
                $page = (int) ($info['page'] ?? 0);
                if ($page < 0 || $page > 2) {
                    continue;
                }
                $points = (int) ($spentByTab[$tabId] ?? 0);
                $pages[$page] += $points;
                $trees[$tabId] = [
                    'name' => $info['name'],
                    'page' => $page,
                    'points' => $points,
                    'talents' => [],
                ];
            }
            foreach ($talentRows as $tr) {
                $tid = $tr['tab_id'];
                if ($tid !== null && isset($trees[$tid])) {
                    $trees[$tid]['talents'][] = $tr;
                }
            }
            usort($trees, static function ($a, $b) {
                return ($a['page'] <=> $b['page']) ?: strcmp($a['name'], $b['name']);
            });
            foreach ($trees as &$tree) {
                usort($tree['talents'], static function ($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
            }
            unset($tree);
        } else {
            usort($talentRows, static function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
            $trees = [[
                'name' => '',
                'page' => 0,
                'points' => array_sum(array_column($talentRows, 'rank')),
                'talents' => $talentRows,
            ]];
        }

        $primaryTree = '';
        if ($spentByTab !== []) {
            $bestTab = array_keys($spentByTab, max($spentByTab), true)[0];
            $primaryTree = $tabInfo[$bestTab]['name'] ?? '';
        }

        $pointsTotal = array_sum($pages);
        if ($pointsTotal === 0 && $talentRows !== []) {
            $pointsTotal = array_sum(array_column($talentRows, 'rank'));
        }

        $view['specs'][$specIndex] = [
            'points' => $pages,
            'points_total' => $pointsTotal,
            'primary_tree' => $primaryTree,
            'trees' => array_values($trees),
            'talent_count' => count($rowsBySpec[$specIndex]),
        ];
    }

    $view['available'] = true;
    if ($allSpellIds === []) {
        $view['message'] = 'no_talents';
    }

    return $view;
}
