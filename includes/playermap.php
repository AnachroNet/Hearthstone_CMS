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

/** CMS language code for playermap (only `en` shipped until site locale selection exists). */
function sahtout_playermap_locale_code(): string
{
    return 'en';
}

/**
 * Area/zone names for playermap tooltips (characters.zone → AreaTable ID).
 */
function sahtout_playermap_load_zone_names(?mysqli $siteDb = null): void
{
    global $zones;
    $zones = [];

    if ($siteDb instanceof mysqli && !$siteDb->connect_error) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'area_dbc.php';
        $zones = sahtout_area_dbc_name_map($siteDb);
    }

    if ($zones !== []) {
        return;
    }

    $zonesFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'en'
        . DIRECTORY_SEPARATOR . 'playermap_zones.php';
    if (is_readable($zonesFile)) {
        $zoneData = include $zonesFile;
        if (is_array($zoneData)) {
            $zones = $zoneData;
        }
    }
}

/**
 * Load playermap UI strings and zone names into globals expected by upstream map code.
 */
function sahtout_playermap_load_locale(?string $langCode = null, ?mysqli $siteDb = null): void
{
    global $character_race, $character_class, $lang_defs;

    $langCode = $langCode ?? sahtout_playermap_locale_code();
    $langCode = preg_replace('/[^a-z0-9_-]/i', '', $langCode) ?? 'en';
    if ($langCode === '') {
        $langCode = 'en';
    }

    $langRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . $langCode;
    $uiFile = $langRoot . DIRECTORY_SEPARATOR . 'playermap.php';

    if (!is_readable($uiFile)) {
        if ($langCode !== 'en') {
            sahtout_playermap_load_locale('en', $siteDb);

            return;
        }
        http_response_code(500);
        exit('Player map language data is missing.');
    }

    $ui = include $uiFile;
    if (!is_array($ui)) {
        http_response_code(500);
        exit('Player map language data is invalid.');
    }

    $character_race = $ui['character_race'] ?? [];
    $character_class = $ui['character_class'] ?? [];
    $lang_defs = $ui['lang_defs'] ?? [];

    sahtout_playermap_load_zone_names($siteDb);
}

/** @return list<array{0:int,1:int}> */
function sahtout_playermap_empty_faction_counts(int $mapsCount): array
{
    return array_fill(0, $mapsCount, [0, 0]);
}

/**
 * @param list<array{0:int,1:int}> $counts
 * @param list<array<string,mixed>> $players
 * @return list<mixed>
 */
function sahtout_playermap_merge_online_payload(array $counts, array $players): array
{
    usort($players, 'sort_players');

    return array_merge($counts, $players);
}

function sahtout_playermap_realm_display_name(int $realmId, $authSqlLayer = null): string
{
    global $sahtout_local;

    $fallback = 'Realm';
    foreach ($sahtout_local['realms'] ?? [] as $realm) {
        if (!is_array($realm)) {
            continue;
        }
        if ((int) ($realm['id'] ?? 0) === $realmId) {
            $name = trim((string) ($realm['name'] ?? ''));
            if ($name !== '') {
                $fallback = $name;
            }
            break;
        }
    }

    if (is_object($authSqlLayer) && method_exists($authSqlLayer, 'isValid') && $authSqlLayer->isValid()) {
        $query = $authSqlLayer->query('SELECT name FROM realmlist WHERE id = ' . (int) $realmId);
        $row = $authSqlLayer->fetch_assoc($query);
        if (is_array($row)) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $fallback = $name;
            }
        }
    }

    return htmlentities($fallback, ENT_QUOTES, 'UTF-8');
}

/** @return list<int> */
function sahtout_playermap_outland_instance_map_ids(): array
{
    return [540, 542, 543, 544, 545, 546, 547, 548, 550, 552, 553, 554, 555, 556, 557, 558, 559, 562, 564, 565];
}

/** @return list<int> */
function sahtout_playermap_northrend_instance_map_ids(): array
{
    return [533, 574, 575, 576, 578, 599, 600, 601, 602, 603, 604, 608, 615, 616, 617, 619, 624, 631, 632, 649, 650, 658, 668, 724];
}

/** Playermap continent tab index: 0 Azeroth, 1 Outland, 2 Northrend. */
function sahtout_playermap_continent_index(int $mapId, float $positionY): int
{
    if (($mapId === 530 && $positionY > -1000) || in_array($mapId, sahtout_playermap_outland_instance_map_ids(), true)) {
        return 1;
    }
    if ($mapId === 571 || in_array($mapId, sahtout_playermap_northrend_instance_map_ids(), true)) {
        return 2;
    }

    return 0;
}

/**
 * URL to open /playermap/ with a highlighted character (live pin or last-known coords).
 *
 * @param array{name?:string,online?:mixed,map?:mixed,position_x?:mixed,position_y?:mixed} $character
 */
function sahtout_playermap_character_highlight_url(string $basePath, array $character, ?mysqli $siteDb = null): ?string
{
    if ($siteDb instanceof mysqli && $siteDb->connect_error) {
        $siteDb = null;
    }
    if (!sahtout_playermap_enabled($siteDb)) {
        return null;
    }

    $name = trim((string) ($character['name'] ?? ''));
    if ($name === '' || strlen($name) > 64) {
        return null;
    }

    $query = ['player' => $name];
    if (empty($character['online'])) {
        $mapId = (int) ($character['map'] ?? 0);
        $posX = (float) ($character['position_x'] ?? 0);
        $posY = (float) ($character['position_y'] ?? 0);
        $query['map'] = $mapId;
        $query['x'] = $posX;
        $query['y'] = $posY;
    }

    return rtrim($basePath, '/') . '/playermap/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/** Sanitize ?player= from playermap query string. */
function sahtout_playermap_sanitize_highlight_name(string $raw): string
{
    $name = trim($raw);
    if ($name === '' || strlen($name) > 64) {
        return '';
    }
    if (preg_match('/^[\p{L}\p{N}\x20\'\-\.]+$/u', $name) !== 1) {
        return '';
    }

    return $name;
}

/**
 * @return ?array{map:int,x:float,y:float,ext:int}
 */
function sahtout_playermap_snapshot_from_request(): ?array
{
    $player = sahtout_playermap_sanitize_highlight_name((string) ($_GET['player'] ?? ''));
    if ($player === '' || !isset($_GET['map'], $_GET['x'], $_GET['y'])) {
        return null;
    }

    $mapId = (int) $_GET['map'];
    $posX = (float) $_GET['x'];
    $posY = (float) $_GET['y'];

    return [
        'map' => $mapId,
        'x' => $posX,
        'y' => $posY,
        'ext' => sahtout_playermap_continent_index($mapId, $posY),
    ];
}
