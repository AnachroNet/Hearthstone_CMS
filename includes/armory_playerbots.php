<?php
if (!defined('ALLOWED_ACCESS')) { exit('Forbidden'); }

/**
 * Playerbots schema name from config.local.php (database.playerbots.name).
 * Cross-database reads use the characters DB connection; no separate mysqli link.
 */
function sahtout_playerbots_db_name(): string
{
    global $db_playerbots_name;

    return trim((string) ($db_playerbots_name ?? ''));
}

function sahtout_playerbots_db_configured(): bool
{
    return sahtout_playerbots_db_name() !== '';
}

function sahtout_sql_quoted_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/** Fully qualified `database`.`table` for playerbots_account_type (empty if not configured). */
function sahtout_playerbots_account_type_table(string $table = 'playerbots_account_type'): string
{
    $db = sahtout_playerbots_db_name();
    if ($db === '') {
        return '';
    }

    return sahtout_sql_quoted_identifier($db) . '.' . sahtout_sql_quoted_identifier($table);
}

/**
 * Safely checks whether the Playerbots table used by the "humans_only"
 * filter exists (playerbots_account_type by default).
 *
 * Never throws and never exposes database details: any failure — including a
 * missing database, missing table or unusable connection — simply returns
 * false so callers can fall back gracefully (public pages) or show a warning
 * (admin settings page). Result is memoized per request.
 *
 * @param mysqli|mixed $char_db Active characters database connection.
 * @param string|null  $schema  Optional schema override (used by tests).
 * @param string       $table   Optional table override (used by tests).
 * @return bool
 */
function armory_playerbots_table_exists($char_db, ?string $schema = null, string $table = 'playerbots_account_type'): bool
{
    if ($schema === null) {
        if (!sahtout_playerbots_db_configured()) {
            return false;
        }
        $schema = sahtout_playerbots_db_name();
    }

    static $cache = [];
    $cacheKey = $schema . '.' . $table;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $exists = false;

    try {
        if ($char_db instanceof mysqli && empty($char_db->connect_error)) {
            $stmt = $char_db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('ss', $schema, $table);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) {
                        $exists = $result->num_rows > 0;
                        $result->free();
                    }
                }
                $stmt->close();
            }
        }
    } catch (Throwable $e) {
        error_log('Armory Playerbots table probe failed: ' . $e->getMessage());
        $exists = false;
    }

    return $cache[$cacheKey] = $exists;
}

/**
 * SQL AND fragment for Solo PvP bot filtering when display mode is humans_only.
 *
 * @param bool $includeScoredBots When true, also include playerbots with totalKills > 0.
 *
 * @return string Empty when mode is "all" or Playerbots metadata is unavailable.
 */
function armory_solo_pvp_bot_filter_sql($char_db, string $displayMode, bool $includeScoredBots = true): string
{
    if ($displayMode !== 'humans_only') {
        return '';
    }
    if (!($char_db instanceof mysqli) || !empty($char_db->connect_error)) {
        return '';
    }
    if (!armory_playerbots_table_exists($char_db)) {
        return '';
    }

    $patTable = sahtout_playerbots_account_type_table();
    if ($patTable === '') {
        return '';
    }

    if ($includeScoredBots) {
        return " AND (
            NOT EXISTS (
                SELECT 1 FROM {$patTable} pat
                WHERE pat.account_id = c.account
            )
            OR c.totalKills > 0
        ) ";
    }

    return " AND NOT EXISTS (
        SELECT 1 FROM {$patTable} pat
        WHERE pat.account_id = c.account
    ) ";
}

/** LEFT JOIN clause for arena leaderboards when filtering playerbot captains. */
function armory_playerbots_account_type_join_sql(string $characterAccountColumn = 'c.account'): string
{
    $patTable = sahtout_playerbots_account_type_table();
    if ($patTable === '') {
        return '';
    }

    return " LEFT JOIN {$patTable} pat ON pat.account_id = {$characterAccountColumn} ";
}
