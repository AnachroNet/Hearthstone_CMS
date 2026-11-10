<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

const SAHTOUT_PLAYERMAP_FACTION_BOTH = 'both';
const SAHTOUT_PLAYERMAP_FACTION_ALLIANCE = 'alliance';
const SAHTOUT_PLAYERMAP_FACTION_HORDE = 'horde';

/** @return list<string> */
function sahtout_playermap_faction_modes(): array
{
    return [
        SAHTOUT_PLAYERMAP_FACTION_BOTH,
        SAHTOUT_PLAYERMAP_FACTION_ALLIANCE,
        SAHTOUT_PLAYERMAP_FACTION_HORDE,
    ];
}

/**
 * @return bool|null null = use site_settings
 */
function sahtout_playermap_config_override(): ?bool
{
    global $sahtout_local;
    $features = is_array($sahtout_local ?? null) ? ($sahtout_local['features'] ?? null) : null;
    if (!is_array($features) || !array_key_exists('playermap_enabled', $features)) {
        return null;
    }
    $v = $features['playermap_enabled'];
    if ($v === null) {
        return null;
    }

    return (bool) $v;
}

function sahtout_playermap_enabled(?mysqli $siteDb = null): bool
{
    $override = sahtout_playermap_config_override();
    if ($override !== null) {
        return $override;
    }
    global $sahtout_site_settings;

    if (!isset($sahtout_site_settings) || !is_array($sahtout_site_settings)) {
        return false;
    }

    return !empty($sahtout_site_settings['playermap.enabled']);
}

function sahtout_playermap_faction_mode(): string
{
    global $sahtout_site_settings;
    $mode = strtolower(trim((string) ($sahtout_site_settings['playermap.faction_visibility'] ?? SAHTOUT_PLAYERMAP_FACTION_BOTH)));
    if (!in_array($mode, sahtout_playermap_faction_modes(), true)) {
        return SAHTOUT_PLAYERMAP_FACTION_BOTH;
    }

    return $mode;
}

function sahtout_playermap_is_horde_race(int $race): bool
{
    return in_array($race, [2, 5, 6, 8, 10], true);
}

/** Whether a non-GM character should appear on the map for the configured faction filter. */
function sahtout_playermap_race_visible(int $race, ?string $mode = null): bool
{
    $mode = $mode ?? sahtout_playermap_faction_mode();
    if ($mode === SAHTOUT_PLAYERMAP_FACTION_BOTH) {
        return true;
    }
    $horde = sahtout_playermap_is_horde_race($race);
    if ($mode === SAHTOUT_PLAYERMAP_FACTION_HORDE) {
        return $horde;
    }
    if ($mode === SAHTOUT_PLAYERMAP_FACTION_ALLIANCE) {
        return !$horde;
    }

    return true;
}

/**
 * Legacy playermap both_factions flag derived from admin faction visibility.
 */
function sahtout_playermap_both_factions(): bool
{
    return sahtout_playermap_faction_mode() === SAHTOUT_PLAYERMAP_FACTION_BOTH;
}

/**
 * @return array<string, mixed>
 */
function sahtout_playermap_local_options(): array
{
    global $sahtout_local;
    $cfg = is_array($sahtout_local ?? null) ? ($sahtout_local['playermap'] ?? null) : null;

    return is_array($cfg) ? $cfg : [];
}

/** Playermap tuning defaults (Admin → General controls enable + faction only). */
function sahtout_playermap_apply_tuning_defaults(): void
{
    $opts = sahtout_playermap_local_options();

    global $gm_online, $gm_online_count, $map_gm_show_online_only_gmoff, $map_gm_show_online_only_gmvisible;
    global $map_gm_add_suffix, $map_status_gm_include_all, $map_show_status, $map_show_time, $map_time;
    global $map_time_to_show_uptime, $map_time_to_show_maxonline, $map_time_to_show_gmonline;
    global $developer_test_mode, $multi_realm_mode, $language, $site_encoding, $db_type, $server_type;

    $language = 'english';
    $site_encoding = 'utf8';
    $db_type = 'MySQL';
    $server_type = 1;

    $gm_online = (bool) ($opts['gm_online'] ?? true);
    $gm_online_count = (int) ($opts['gm_online_count'] ?? 100);
    $map_gm_show_online_only_gmoff = (int) ($opts['map_gm_show_online_only_gmoff'] ?? 1);
    $map_gm_show_online_only_gmvisible = (int) ($opts['map_gm_show_online_only_gmvisible'] ?? 1);
    $map_gm_add_suffix = (int) ($opts['map_gm_add_suffix'] ?? 1);
    $map_status_gm_include_all = (int) ($opts['map_status_gm_include_all'] ?? 0);
    $map_show_status = (int) ($opts['map_show_status'] ?? 1);
    $map_show_time = (int) ($opts['map_show_time'] ?? 1);
    $map_time = (int) ($opts['map_time'] ?? 5);
    $map_time_to_show_uptime = (int) ($opts['map_time_to_show_uptime'] ?? 3000);
    $map_time_to_show_maxonline = (int) ($opts['map_time_to_show_maxonline'] ?? 3000);
    $map_time_to_show_gmonline = (int) ($opts['map_time_to_show_gmonline'] ?? 3000);
    $developer_test_mode = (bool) ($opts['developer_test_mode'] ?? false);
    $multi_realm_mode = (bool) ($opts['multi_realm_mode'] ?? false);
}

/**
 * Build legacy $realm_db, $characters_db, $server arrays for bundled playermap code.
 */
function sahtout_playermap_build_legacy_db_config(): array
{
    global $sahtout_local, $db_auth_host, $db_auth_port, $db_auth_user, $db_auth_pass, $db_auth_name;
    global $db_char_host, $db_char_port, $db_char_user, $db_char_pass, $db_char_name;

    $authAddr = $db_auth_host . ':' . (int) $db_auth_port;
    $charAddr = $db_char_host . ':' . (int) $db_char_port;

    $realmDb = [
        'addr' => $authAddr,
        'user' => $db_auth_user,
        'pass' => $db_auth_pass,
        'name' => $db_auth_name,
        'encoding' => 'utf8',
    ];

    $charactersDb = [];
    $server = [];
    $realmIdDefault = null;

    foreach ($sahtout_local['realms'] ?? [] as $realm) {
        if (!is_array($realm)) {
            continue;
        }
        $id = (int) ($realm['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if ($realmIdDefault === null) {
            $realmIdDefault = $id;
        }
        $charactersDb[$id] = [
            'addr' => $charAddr,
            'user' => $db_char_user,
            'pass' => $db_char_pass,
            'name' => $db_char_name,
            'encoding' => 'utf8',
        ];
        $server[$id] = [
            'addr' => (string) ($realm['check_address'] ?? $realm['address'] ?? '127.0.0.1'),
            'addr_wan' => (string) ($realm['address'] ?? $realm['check_address'] ?? '127.0.0.1'),
            'game_port' => (int) ($realm['port'] ?? 8085),
            'rev' => '',
            'both_factions' => sahtout_playermap_both_factions(),
        ];
    }

    if ($charactersDb === []) {
        $charactersDb[1] = [
            'addr' => $charAddr,
            'user' => $db_char_user,
            'pass' => $db_char_pass,
            'name' => $db_char_name,
            'encoding' => 'utf8',
        ];
        $server[1] = [
            'addr' => '127.0.0.1',
            'addr_wan' => '127.0.0.1',
            'game_port' => 8085,
            'rev' => '',
            'both_factions' => sahtout_playermap_both_factions(),
        ];
    }

    if ($realmIdDefault === null) {
        $realmIdDefault = 1;
    }

    return [
        'realm_db' => $realmDb,
        'characters_db' => $charactersDb,
        'server' => $server,
        'default_realm_id' => $realmIdDefault,
    ];
}
