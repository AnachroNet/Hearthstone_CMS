<?php

require_once dirname(__DIR__) . '/includes/playermap/bootstrap.php';
require_once __DIR__ . '/func.php';
require_once __DIR__ . '/libs/data_lib.php';

global $sahtout_playermap_default_realm_id;
$realm_id = (int) ($sahtout_playermap_default_realm_id ?? 1);
if (!isset($characters_db[$realm_id], $server[$realm_id])) {
    $realm_id = (int) array_key_first($characters_db);
}

$server_arr = $server;
$lang = $language;

// English-only for CMS integration (future: languages/en/playermap_map.php).
if (!is_file(__DIR__ . '/map_english.php') || !is_file(__DIR__ . '/zone_names_english.php')) {
    http_response_code(500);
    exit('Player map language data is missing.');
}
$lang = 'english';

$database_encoding = $site_encoding;

$server = $server_arr[$realm_id]['addr'];
$port = $server_arr[$realm_id]['game_port'];

$host = $characters_db[$realm_id]['addr'];
$user = $characters_db[$realm_id]['user'];
$password = $characters_db[$realm_id]['pass'];
$db = $characters_db[$realm_id]['name'];

$hostr = $realm_db['addr'];
$userr = $realm_db['user'];
$passwordr = $realm_db['pass'];
$dbr = $realm_db['name'];

$sql = new DBLayer($hostr, $userr, $passwordr, $dbr);
$query = $sql->query('SELECT name FROM realmlist WHERE id = ' . $realm_id);
$realm_name = $sql->fetch_assoc($query);
$realm_name = htmlentities((string) ($realm_name['name'] ?? 'Realm'));

$gm_show_online = $gm_online;
$gm_show_online_only_gmoff = $map_gm_show_online_only_gmoff;
$gm_show_online_only_gmvisible = $map_gm_show_online_only_gmvisible;
$gm_add_suffix = $map_gm_add_suffix;
$gm_include_online = $gm_online_count;
$show_status = $map_show_status;
$time_to_show_uptime = $map_time_to_show_uptime;
$time_to_show_maxonline = $map_time_to_show_maxonline;
$time_to_show_gmonline = $map_time_to_show_gmonline;
$status_gm_include_all = $map_status_gm_include_all;
$time = $map_time;
$show_time = $map_show_time;

$maps_for_points = '0,1,530,571,609';

global $sahtout_playermap_web_base;
$img_base = $sahtout_playermap_web_base . 'img/map/';
$img_base2 = $sahtout_playermap_web_base . 'img/c_icons/';

$PLAYER_FLAGS = CHAR_DATA_OFFSET_FLAGS;
