<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'character_lifetime_stats.php';

function sahtout_character_stat_rewards_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'character_stat_token_rewards'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_character_stat_grants_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'character_stat_token_grants'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

/** @return list<string> */
function sahtout_character_stat_reward_types(): array
{
    return ['world_kills', 'duels_won', 'deaths', 'hours_played'];
}

function sahtout_normalize_character_stat_type(string $type): ?string
{
    $type = strtolower(trim($type));

    return in_array($type, sahtout_character_stat_reward_types(), true) ? $type : null;
}

function sahtout_character_stat_type_label_key(string $type): string
{
    return match ($type) {
        'world_kills' => 'admin_stat_reward_type_world_kills',
        'duels_won' => 'admin_stat_reward_type_duels_won',
        'deaths' => 'admin_stat_reward_type_deaths',
        'hours_played' => 'admin_stat_reward_type_hours_played',
        default => 'admin_stat_reward_type_other',
    };
}

/**
 * @return list<array<string, mixed>>
 */
function sahtout_admin_fetch_character_stat_rewards(mysqli $siteDb): array
{
    if (!sahtout_character_stat_rewards_table_exists($siteDb)) {
        return [];
    }
    $res = $siteDb->query(
        'SELECT id, stat_type, threshold, tokens, is_enabled, updated_at
         FROM character_stat_token_rewards
         ORDER BY stat_type, threshold ASC'
    );
    if (!$res) {
        return [];
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();

    return $rows ?: [];
}

/**
 * @return array{ok: bool, message?: string, id?: int}
 */
function sahtout_admin_add_character_stat_reward(
    mysqli $siteDb,
    string $statType,
    int $threshold,
    int $tokens
): array {
    if (!sahtout_character_stat_rewards_table_exists($siteDb)) {
        return ['ok' => false, 'message' => 'table_missing'];
    }
    $statType = sahtout_normalize_character_stat_type($statType);
    if ($statType === null || $threshold <= 0 || $threshold > 999999999) {
        return ['ok' => false, 'message' => 'invalid_rule'];
    }
    if ($tokens <= 0 || $tokens > 9999999) {
        return ['ok' => false, 'message' => 'invalid_tokens'];
    }

    $stmt = $siteDb->prepare(
        'INSERT INTO character_stat_token_rewards (stat_type, threshold, tokens, is_enabled)
         VALUES (?, ?, ?, 0)'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'db_error'];
    }
    $stmt->bind_param('sii', $statType, $threshold, $tokens);
    $ok = $stmt->execute();
    $id = $ok ? (int) $stmt->insert_id : 0;
    $errno = $siteDb->errno;
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'message' => $errno === 1062 ? 'duplicate' : 'db_error'];
    }

    return ['ok' => true, 'id' => $id];
}

/**
 * @return array{ok: bool, message?: string}
 */
function sahtout_admin_update_character_stat_reward(
    mysqli $siteDb,
    int $rewardId,
    int $tokens,
    bool $isEnabled
): array {
    if ($rewardId <= 0 || !sahtout_character_stat_rewards_table_exists($siteDb)) {
        return ['ok' => false, 'message' => 'invalid'];
    }
    $tokens = max(0, min(9999999, $tokens));
    $enabled = $isEnabled ? 1 : 0;

    $stmt = $siteDb->prepare(
        'UPDATE character_stat_token_rewards SET tokens = ?, is_enabled = ? WHERE id = ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'db_error'];
    }
    $stmt->bind_param('iii', $tokens, $enabled, $rewardId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? ['ok' => true] : ['ok' => false, 'message' => 'db_error'];
}

/**
 * @return array{ok: bool, message?: string}
 */
function sahtout_admin_delete_character_stat_reward(mysqli $siteDb, int $rewardId): array
{
    if ($rewardId <= 0 || !sahtout_character_stat_rewards_table_exists($siteDb)) {
        return ['ok' => false, 'message' => 'invalid'];
    }

    $stmt = $siteDb->prepare('DELETE FROM character_stat_token_rewards WHERE id = ?');
    if (!$stmt) {
        return ['ok' => false, 'message' => 'db_error'];
    }
    $stmt->bind_param('i', $rewardId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? ['ok' => true] : ['ok' => false, 'message' => 'db_error'];
}

/**
 * Best value across all characters on the account.
 *
 * @return array{world_kills: int, duels_won: int, deaths: int, hours_played: int}
 */
function sahtout_account_character_stat_maximums(mysqli $charDb, int $accountId): array
{
    $out = [
        'world_kills' => 0,
        'duels_won' => 0,
        'deaths' => 0,
        'hours_played' => 0,
    ];
    if ($accountId <= 0 || $charDb->connect_error) {
        return $out;
    }

    $stmt = $charDb->prepare('SELECT guid, totaltime FROM characters WHERE account = ?');
    if (!$stmt) {
        return $out;
    }
    $stmt->bind_param('i', $accountId);
    if (!$stmt->execute()) {
        $stmt->close();

        return $out;
    }
    $res = $stmt->get_result();
    $guids = [];
    $maxPlaytime = 0;
    while ($row = $res->fetch_assoc()) {
        $guids[] = (int) $row['guid'];
        $maxPlaytime = max($maxPlaytime, (int) ($row['totaltime'] ?? 0));
    }
    $stmt->close();

    $out['hours_played'] = sahtout_playtime_hours_rounded($maxPlaytime);

    foreach ($guids as $guid) {
        try {
            $counters = sahtout_character_achievement_counters($charDb, $guid);
        } catch (Throwable $e) {
            continue;
        }
        $out['world_kills'] = max($out['world_kills'], (int) ($counters[SAHTOUT_CRITERIA_NPC_KILLS] ?? 0));
        $out['duels_won'] = max($out['duels_won'], (int) ($counters[SAHTOUT_CRITERIA_DUELS_WON] ?? 0));
        $out['deaths'] = max($out['deaths'], (int) ($counters[SAHTOUT_CRITERIA_TOTAL_DEATHS] ?? 0));
    }

    return $out;
}
