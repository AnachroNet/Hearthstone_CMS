<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** WoW 3.3.5 achievement statistics criteria (character_achievement_progress.criteria). */
const SAHTOUT_CRITERIA_TOTAL_DEATHS = 111;
const SAHTOUT_CRITERIA_DUELS_WON = 3629;
const SAHTOUT_CRITERIA_NPC_KILLS = 4944;

/**
 * @return list<int>
 */
function sahtout_character_lifetime_criteria_ids(): array
{
    return [
        SAHTOUT_CRITERIA_TOTAL_DEATHS,
        SAHTOUT_CRITERIA_DUELS_WON,
        SAHTOUT_CRITERIA_NPC_KILLS,
    ];
}

/**
 * @return array<int, int> criteria id => counter
 */
function sahtout_character_achievement_counters(mysqli $charDb, int $guid): array
{
    $criteriaIds = sahtout_character_lifetime_criteria_ids();
    if ($guid <= 0 || $criteriaIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($criteriaIds), '?'));
    $sql = "SELECT criteria, counter FROM character_achievement_progress WHERE guid = ? AND criteria IN ($placeholders)";
    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($charDb->error);
    }

    $types = 'i' . str_repeat('i', count($criteriaIds));
    $params = array_merge([$guid], $criteriaIds);
    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    if (!call_user_func_array([$stmt, 'bind_param'], $bind)) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException($err);
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException($err);
    }

    $result = $stmt->get_result();
    $out = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $out[(int) $row['criteria']] = (int) $row['counter'];
        }
    }
    $stmt->close();

    return $out;
}

function sahtout_playtime_hours_rounded(int $totaltimeSeconds): int
{
    if ($totaltimeSeconds <= 0) {
        return 0;
    }

    return (int) round($totaltimeSeconds / 3600);
}

function sahtout_format_playtime_dhm(int $totaltimeSeconds): string
{
    $totaltimeSeconds = max(0, $totaltimeSeconds);
    $days = intdiv($totaltimeSeconds, 86400);
    $hours = intdiv($totaltimeSeconds % 86400, 3600);
    $minutes = intdiv($totaltimeSeconds % 3600, 60);

    return sprintf(
        translate('playtime_dhm', '%d Days, %d Hours, %d Minutes'),
        $days,
        $hours,
        $minutes
    );
}
