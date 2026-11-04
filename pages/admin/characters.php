<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/admin_comp_item_ui.php';
require_once $project_root . 'includes/admin_teleport_locations.php';
require_once $project_root . 'includes/admin_table_alignment.php';
require_once $project_root . 'includes/admin_pagination.php';

$page_class = 'characters';

$soapConfigured = sahtout_soap_is_configured();
$defaultSendItemsSubject = translate('admin_chars_send_items_default_subject', 'Items from the GM team');
$defaultSendItemsBody = translate('admin_chars_send_items_default_body', 'Please find your items attached.');

define('DB_AUTH', $db_auth_name);
define('DB_CHAR', $db_char_name);
define('DB_WORLD', $db_world_name);
define('DB_SITE', $db_site_name);

if (!isset($_SESSION['user_id'])) {
    header("Location: {$base_path}login");
    exit;
}

global $site_db, $auth_db, $char_db;

$user_id = $_SESSION['user_id'];
$role_query = "SELECT role FROM " . DB_SITE . ".user_currencies WHERE account_id = ?";
$stmt = $site_db->prepare($role_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $_SESSION['role'] = $row['role'];
} else {
    $_SESSION['role'] = 'player';
}
$stmt->close();

if (!in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

// Handle search, online filter, level filter, and sort
$search_char_name = isset($_GET['search_char_name']) ? trim($_GET['search_char_name']) : '';
$search_username = isset($_GET['search_username']) ? trim($_GET['search_username']) : '';
$online_filter = isset($_GET['online_filter']) && in_array($_GET['online_filter'], ['online', 'offline']) ? $_GET['online_filter'] : '';
$min_level = isset($_GET['min_level']) && trim($_GET['min_level']) !== '' && is_numeric($_GET['min_level']) ? max(1, min(255, (int)$_GET['min_level'])) : '';
$max_level = isset($_GET['max_level']) && trim($_GET['max_level']) !== '' && is_numeric($_GET['max_level']) ? max(1, min(255, (int)$_GET['max_level'])) : '';
$sort_id = isset($_GET['sort_id']) && in_array($_GET['sort_id'], ['asc', 'desc']) ? $_GET['sort_id'] : 'asc';

$chars_per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $chars_per_page;

$teleportLocationsReady = isset($site_db) && $site_db instanceof mysqli && sahtout_admin_teleport_locations_table_exists($site_db);
$teleportLocations = $teleportLocationsReady ? sahtout_fetch_admin_teleport_locations($site_db, true) : [];

// Handle form submissions
$update_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manage_character') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_csrf_error', 'CSRF token validation failed.') . '</div>';
    } else {
        $guid = (int)$_POST['guid'];
        $char_action = $_POST['char_action'] ?? '';
        $success = false;

        $stmt = $char_db->prepare("SELECT name, online FROM " . DB_CHAR . ".characters WHERE guid = ?");
        $stmt->bind_param("i", $guid);
        $stmt->execute();
        $char = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$char) {
            $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_not_found', 'Character not found.') . '</div>';
        } else {
            $char_name = $char['name'];

            if ($char_action === 'add_gold') {
                if ($char['online'] == 0) {
                    $gold = isset($_POST['gold']) ? (int)$_POST['gold'] : 0;
                    if ($gold >= 0) {
                        $gold_in_copper = $gold * 10000;
                        $stmt = $char_db->prepare("UPDATE " . DB_CHAR . ".characters SET money = money + ? WHERE guid = ?");
                        $stmt->bind_param("ii", $gold_in_copper, $guid);
                        if ($stmt->execute()) {
                            $success = true;
                            $update_message = '<div class="bg-green-900/40 border border-green-500/50 text-green-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_add_gold_success', 'Added %d gold to %s successfully.'), $gold, htmlspecialchars($char_name)) . '</div>';
                        } else {
                            $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_add_gold_failed', 'Failed to add gold to %s.'), htmlspecialchars($char_name)) . '</div>';
                        }
                        $stmt->close();
                    } else {
                        $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_gold_negative', 'Gold amount must be a non-negative number.') . '</div>';
                    }
                } else {
                    $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_gold_online', 'Cannot add gold to %s: Character is online.'), htmlspecialchars($char_name)) . '</div>';
                }
            } elseif ($char_action === 'change_level') {
                if ($char['online'] == 0) {
                    $level = isset($_POST['level']) ? (int)$_POST['level'] : 0;
                    if ($level >= 1 && $level <= 255) {
                        $stmt = $char_db->prepare("UPDATE " . DB_CHAR . ".characters SET level = ? WHERE guid = ?");
                        $stmt->bind_param("ii", $level, $guid);
                        if ($stmt->execute()) {
                            $success = true;
                            $update_message = '<div class="bg-green-900/40 border border-green-500/50 text-green-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_level_success', 'Level changed to %d for %s successfully.'), $level, htmlspecialchars($char_name)) . '</div>';
                        } else {
                            $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_level_failed', 'Failed to change level for %s.'), htmlspecialchars($char_name)) . '</div>';
                        }
                        $stmt->close();
                    } else {
                        $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_level_invalid', 'Level must be between 1 and 255.') . '</div>';
                    }
                } else {
                    $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_level_online', 'Cannot change level for %s: Character is online.'), htmlspecialchars($char_name)) . '</div>';
                }
            } elseif ($char_action === 'teleport') {
                if ($char['online'] == 0) {
                    $map = isset($_POST['map']) ? (int)$_POST['map'] : 0;
                    $x = isset($_POST['x']) ? (float)$_POST['x'] : 0;
                    $y = isset($_POST['y']) ? (float)$_POST['y'] : 0;
                    $z = isset($_POST['z']) ? (float)$_POST['z'] : 0;
                    if ($map >= 0 && $x != 0 && $y != 0) {
                        $stmt = $char_db->prepare("UPDATE " . DB_CHAR . ".characters SET map = ?, position_x = ?, position_y = ?, position_z = ? WHERE guid = ?");
                        $stmt->bind_param("idddi", $map, $x, $y, $z, $guid);
                        if ($stmt->execute()) {
                            $success = true;
                            $update_message = '<div class="bg-green-900/40 border border-green-500/50 text-green-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_teleport_success', 'Teleported %s successfully.'), htmlspecialchars($char_name)) . '</div>';
                        } else {
                            $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_teleport_failed', 'Failed to teleport %s.'), htmlspecialchars($char_name)) . '</div>';
                        }
                        $stmt->close();
                    } else {
                        $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_teleport_invalid', 'Invalid coordinates. Map must be ≥ 0 and X/Y cannot be 0.') . '</div>';
                    }
                } else {
                    $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_teleport_online', 'Cannot teleport %s: Character is online.'), htmlspecialchars($char_name)) . '</div>';
                }
            } elseif ($char_action === 'teleport_directly') {
                if ($char['online'] == 0) {
                    if (!$teleportLocationsReady) {
                        $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_teleport_locations_unavailable', 'Teleport locations are not configured. Run the site database migration or add locations under Admin → Teleport Locations.') . '</div>';
                    } else {
                        $locationId = (int) ($_POST['teleport_location_id'] ?? 0);
                        $loc = sahtout_admin_teleport_location_by_id($site_db, $locationId, true);
                        if ($loc !== null) {
                            $stmt = $char_db->prepare("UPDATE " . DB_CHAR . ".characters SET map = ?, position_x = ?, position_y = ?, position_z = ?, orientation = ? WHERE guid = ?");
                            $stmt->bind_param(
                                'iddddi',
                                $loc['map'],
                                $loc['position_x'],
                                $loc['position_y'],
                                $loc['position_z'],
                                $loc['orientation'],
                                $guid
                            );
                            if ($stmt->execute()) {
                                $success = true;
                                $update_message = '<div class="bg-green-900/40 border border-green-500/50 text-green-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_teleport_direct_success', 'Teleported %s to %s.'), htmlspecialchars($char_name), htmlspecialchars($loc['name'])) . '</div>';
                            } else {
                                $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_teleport_failed', 'Failed to teleport %s.'), htmlspecialchars($char_name)) . '</div>';
                            }
                            $stmt->close();
                        } else {
                            $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_location_invalid', 'Invalid location selected.') . '</div>';
                        }
                    }
                } else {
                    $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_teleport_online', 'Cannot teleport %s: Character is online.'), htmlspecialchars($char_name)) . '</div>';
                }
            } elseif ($char_action === 'send_items') {
                if (!$soapConfigured) {
                    $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_send_items_soap_missing', 'SOAP is not configured. Set it up under Admin → Settings → SOAP to send in-game items.') . '</div>';
                } else {
                    $itemEntries = isset($_POST['comp_item_entry']) && is_array($_POST['comp_item_entry'])
                        ? $_POST['comp_item_entry']
                        : [];
                    $itemQuantities = isset($_POST['comp_item_qty']) && is_array($_POST['comp_item_qty'])
                        ? $_POST['comp_item_qty']
                        : [];
                    $mailSubject = (string) ($_POST['mail_subject'] ?? $defaultSendItemsSubject);
                    $mailBody = (string) ($_POST['mail_body'] ?? $defaultSendItemsBody);

                    $normalized = sahtout_compensation_normalize_item_rows($itemEntries, $itemQuantities);
                    if (!$normalized['ok']) {
                        $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_tickets_comp_invalid_item', 'Each item row needs an entry ID and a quantity (1–200), or leave both empty.') . '</div>';
                    } elseif ($normalized['items'] === []) {
                        $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . translate('admin_chars_send_items_none', 'Add at least one item before sending.') . '</div>';
                    } else {
                        $sendResult = sahtout_admin_soap_send_to_character(
                            $char_name,
                            $mailSubject,
                            $mailBody,
                            $normalized['items']
                        );
                        if ($sendResult['ok']) {
                            $success = true;
                            $update_message = '<div class="bg-green-900/40 border border-green-500/50 text-green-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . sprintf(translate('admin_chars_send_items_success', 'In-game item mail was sent to %s.'), htmlspecialchars($char_name)) . '</div>';
                        } else {
                            $errorText = match ($sendResult['message']) {
                                'invalid_mail_text' => translate('admin_tickets_comp_invalid_mail', 'Mail subject and body are required.'),
                                'invalid_character' => translate('admin_tickets_comp_invalid_character', 'This ticket character name cannot be used for mail commands.'),
                                'soap_failed' => translate('admin_tickets_comp_soap_failed', 'The worldserver rejected the mail command. Check SOAP logs.'),
                                default => translate('admin_chars_send_items_failed', 'Failed to send items.'),
                            };
                            $update_message = '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6 flex items-center gap-3">' . $errorText . '</div>';
                        }
                    }
                }
            }
        }
    }
}

// Count total characters
$count_query = "SELECT COUNT(*) as total FROM " . DB_CHAR . ".characters c JOIN " . DB_AUTH . ".account a ON c.account = a.id WHERE 1=1";
$params = [];
$types = '';
if ($search_char_name) {
    $count_query .= " AND LOWER(c.name) LIKE LOWER(?)";
    $params[] = "%$search_char_name%";
    $types .= 's';
}
if ($search_username) {
    $count_query .= " AND a.username LIKE ?";
    $params[] = "%$search_username%";
    $types .= 's';
}
if ($online_filter !== '') {
    $count_query .= " AND c.online = ?";
    $params[] = $online_filter === 'online' ? 1 : 0;
    $types .= 'i';
}
if ($min_level !== '') {
    $count_query .= " AND c.level >= ?";
    $params[] = $min_level;
    $types .= 'i';
}
if ($max_level !== '') {
    $count_query .= " AND c.level <= ?";
    $params[] = $max_level;
    $types .= 'i';
}
$stmt = $char_db->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_chars = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = ceil($total_chars / $chars_per_page);

// Fetch characters
$chars_query = "SELECT c.guid, c.account, c.name, c.race, c.class, c.gender, c.level, c.map, c.online, a.username 
                FROM " . DB_CHAR . ".characters c JOIN " . DB_AUTH . ".account a ON c.account = a.id WHERE 1=1";
$params = [];
$types = '';
if ($search_char_name) {
    $chars_query .= " AND LOWER(c.name) LIKE LOWER(?)";
    $params[] = "%$search_char_name%";
    $types .= 's';
}
if ($search_username) {
    $chars_query .= " AND a.username LIKE ?";
    $params[] = "%$search_username%";
    $types .= 's';
}
if ($online_filter !== '') {
    $chars_query .= " AND c.online = ?";
    $params[] = $online_filter === 'online' ? 1 : 0;
    $types .= 'i';
}
if ($min_level !== '') {
    $chars_query .= " AND c.level >= ?";
    $params[] = $min_level;
    $types .= 'i';
}
if ($max_level !== '') {
    $chars_query .= " AND c.level <= ?";
    $params[] = $max_level;
    $types .= 'i';
}
$chars_query .= " ORDER BY c.guid " . ($sort_id === 'desc' ? 'DESC' : 'ASC') . " LIMIT ? OFFSET ?";
$params[] = $chars_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $char_db->prepare($chars_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$chars_result = $stmt->get_result();
$characters = [];
while ($char = $chars_result->fetch_assoc()) {
    $characters[] = $char;
}
$stmt->close();

$chars_list_return_query = http_build_query(array_filter([
    'page' => $page > 1 ? $page : null,
    'search_char_name' => $search_char_name !== '' ? $search_char_name : null,
    'search_username' => $search_username !== '' ? $search_username : null,
    'online_filter' => $online_filter !== '' ? $online_filter : null,
    'min_level' => $min_level !== '' ? $min_level : null,
    'max_level' => $max_level !== '' ? $max_level : null,
    'sort_id' => $sort_id !== 'asc' ? $sort_id : null,
], static function ($v) {
    return $v !== null && $v !== '';
}));
$chars_list_return_url = $base_path . 'admin/characters' . ($chars_list_return_query !== '' ? '?' . $chars_list_return_query : '');

$map_names = [
    0 => 'Eastern Kingdoms', 1 => 'Kalimdor', 530 => 'Outland', 571 => 'Northrend',
    33 => 'Shadowfang Keep', 34 => 'The Stockade', 36 => 'Deadmines', 43 => 'Wailing Caverns',
    47 => 'Razorfen Kraul', 48 => 'Blackfathom Deeps', 70 => 'Uldaman', 90 => 'Gnomeregan',
    109 => 'Sunken Temple', 129 => 'Razorfen Downs', 189 => 'Scarlet Monastery', 209 => 'Zulfarrak',
    229 => 'Blackrock Spire', 230 => 'Blackrock Depths', 249 => 'Onyxias Lair', 269 => 'The Black Morass',
    289 => 'Scholomance', 309 => 'Zulgurub', 329 => 'Stratholme', 349 => 'Maraudon',
    389 => 'Ragefire Chasm', 409 => 'Molten Core', 429 => 'Dire Maul', 469 => 'Blackwing Lair',
    509 => 'Ruins of Ahnqiraj', 531 => 'Temple of Ahnqiraj', 532 => 'Karazhan', 533 => 'Naxxramas',
    534 => 'Hyjal', 540 => 'Shattered Halls', 542 => 'Blood Furnace', 543 => 'Hellfire Ramparts',
    544 => 'Magtheridons Lair', 545 => 'Steam Vault', 546 => 'The Underbog', 547 => 'The Slave Pens',
    548 => 'Serpent Shrine', 550 => 'The Eye', 552 => 'Arcatraz', 553 => 'The Botanica',
    554 => 'Mechanar', 555 => 'Shadow Labyrinth', 556 => 'Sethekk Halls', 557 => 'Mana Tombs',
    558 => 'Auchenai Crypts', 560 => 'Old Hillsbrad', 564 => 'Black Temple', 565 => 'Gruuls Lair',
    568 => 'Zulaman', 574 => 'Utgarde Keep', 575 => 'Utgarde Pinnacle', 576 => 'Nexus',
    578 => 'Oculus', 580 => 'Sunwell Plateau', 585 => 'Magisters Terrace', 595 => 'Culling of Stratholme',
    599 => 'Halls of Stone', 600 => 'Drak Tharon Keep', 601 => 'Azjol Nerub', 602 => 'Halls of Lightning',
    603 => 'Ulduar', 604 => 'Gundrak', 608 => 'Violet Hold', 615 => 'Obsidian Sanctum',
    616 => 'Eye of Eternity', 619 => 'Ahnkahet', 624 => 'Vault of Archavon', 631 => 'Icecrown Citadel',
    632 => 'Forge of Souls', 649 => 'Trial of the Crusader', 650 => 'Trial of the Champion',
    658 => 'Pit of Saron', 668 => 'Halls of Reflection', 724 => 'Ruby Sanctum'
];

function getRaceIcon($race, $gender) {
    global $base_path;
    $races = [1 => 'human', 2 => 'orc', 3 => 'dwarf', 4 => 'nightelf', 5 => 'undead', 6 => 'tauren', 7 => 'gnome', 8 => 'troll', 10 => 'bloodelf', 11 => 'draenei'];
    $gender_folder = ($gender == 1) ? 'female' : 'male';
    $race_name = $races[$race] ?? 'default';
    return '<img src="' . $base_path . 'img/accountimg/race/' . $gender_folder . '/' . $race_name . '.png" alt="Race" class="w-5 h-5 inline-block">';
}

function getClassIcon($class) {
    global $base_path;
    $icons = [1 => 'warrior.webp', 2 => 'paladin.webp', 3 => 'hunter.webp', 4 => 'rogue.webp', 5 => 'priest.webp', 6 => 'deathknight.webp', 7 => 'shaman.webp', 8 => 'mage.webp', 9 => 'warlock.webp', 11 => 'druid.webp'];
    return '<img src="' . $base_path . 'img/accountimg/class/' . ($icons[$class] ?? 'default.jpg') . '" alt="Class" class="w-5 h-5 inline-block">';
}

function getFactionIcon($race) {
    global $base_path;
    $allianceRaces = [1, 3, 4, 7, 11];
    $faction = in_array($race, $allianceRaces) ? 'alliance.png' : 'horde.png';
    return '<img src="' . $base_path . 'img/accountimg/faction/' . $faction . '" alt="Faction" class="w-4 h-4 inline-block">';
}

function getOnlineStatus($online) {
    return $online 
        ? '<span class="text-green-400 font-semibold"><i class="fas fa-circle text-green-400 text-xs mr-1"></i> ' . translate('admin_chars_status_online', 'Online') . '</span>'
        : '<span class="text-red-400 font-semibold"><i class="fas fa-circle text-red-400 text-xs mr-1"></i> ' . translate('admin_chars_status_offline', 'Offline') . '</span>';
}

?>
<?php
$page_title = translate('admin_chars_page_title', 'Character Management');
$page_meta_description = translate('admin_chars_meta_description', 'Character Management for Sahtout WoW Server');
$page_meta_robots = 'noindex';

ob_start();
?>
<style>
        * { font-family: 'Inter', sans-serif; }

        body {
            min-height: 100vh;
            color: #d8d8d8;
            background: #05070b;
            background-image:
                radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.14), transparent 65%),
                radial-gradient(800px 600px at -5% 85%, rgba(124,58,237,.10), transparent 70%),
                linear-gradient(180deg, #0a0e16 0%, #060810 45%, #03040a 100%);
            background-attachment: fixed;
        }

        .panel {
            position: relative;
            background: linear-gradient(180deg, rgba(22,25,32,.92), rgba(8,10,14,.9));
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 12px 32px rgba(0,0,0,.55), inset 0 0 60px rgba(0,0,0,.45);
        }

        .panel::before {
            content: '';
            position: absolute;
            inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }

        .panel::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(#e8c552,#e8c552) left top / 18px 2px,
                linear-gradient(#e8c552,#e8c552) left top / 2px 18px,
                linear-gradient(#e8c552,#e8c552) right top / 18px 2px,
                linear-gradient(#e8c552,#e8c552) right top / 2px 18px,
                linear-gradient(#e8c552,#e8c552) left bottom / 18px 2px,
                linear-gradient(#e8c552,#e8c552) left bottom / 2px 18px,
                linear-gradient(#e8c552,#e8c552) right bottom / 18px 2px,
                linear-gradient(#e8c552,#e8c552) right bottom / 2px 18px;
            background-repeat: no-repeat;
        }

        .wow-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 3px 6px rgba(0,0,0,.85));
        }

        .section-title {
            font-family: 'Cinzel', serif;
            font-weight: 700;
            color: #f2cf5b;
            text-shadow: 0 0 12px rgba(201,162,39,.35), 0 2px 4px rgba(0,0,0,.8);
        }

        .btn-gold {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .75rem 1.5rem;
            font-weight: 800;
            font-size: .85rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            transition: transform .2s ease;
            background: linear-gradient(180deg, #f6d478 0%, #c9a227 48%, #8a6a14 100%);
            color: #1a1200;
            text-shadow: 0 1px 0 rgba(255,255,255,.35);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.28), inset 0 -8px 14px rgba(0,0,0,.25);
            border: none;
            cursor: pointer;
        }
        .btn-gold:hover { transform: translateY(-2px) scale(1.02); }

        .btn-iron {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .75rem 1.5rem;
            font-weight: 800;
            font-size: .85rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            transition: transform .2s ease;
            background: linear-gradient(180deg, #3a404d 0%, #232833 55%, #141821 100%);
            color: #cfe1ff;
            box-shadow: inset 0 0 0 1px rgba(120,160,255,.25), inset 0 -8px 14px rgba(0,0,0,.4);
            border: none;
            cursor: pointer;
        }
        .btn-iron:hover { transform: translateY(-2px) scale(1.02); }
        .btn-iron-sm { padding: .4rem 1rem; font-size: .75rem; }

        .input-dark {
            background: rgba(10, 14, 22, 0.8);
            border: 1px solid rgba(201,162,39,.3);
            color: #e5e7eb;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            outline: none;
            width: 100%;
        }
        .input-dark:focus {
            border-color: #f2cf5b;
            box-shadow: 0 0 10px rgba(242,207,82,.2);
            background: rgba(15, 20, 30, 0.9);
        }
        .input-dark::placeholder { color: rgba(150, 170, 200, 0.4); }
        .input-dark option { background: #0a0e16; }

        .table-dark th {
            background: rgba(10, 14, 22, 0.9);
            color: #f2cf5b;
            font-family: 'Cinzel', serif;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 1rem;
            border-bottom: 2px solid rgba(201,162,39,.4);
            text-align: left;
        }
        .table-dark td {
            padding: 1rem;
            border-bottom: 1px solid rgba(201,162,39,.1);
            color: #d8d8d8;
            background: rgba(22, 25, 32, 0.6);
        }
        .table-dark tr:hover td {
            background: rgba(30, 35, 45, 0.8);
        }
        <?php sahtout_admin_table_alignment_css(); ?>

        .modal-backdrop {
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
        }

        .action-field {
            display: none;
        }
        .action-field.active {
            display: block;
        }

        .char-name-link {
            color: #ffffff;
            text-decoration: none;
            transition: color 0.2s ease;
            font-weight: 600;
        }
        .char-name-link:hover {
            color: #f2cf5b;
        }

        /* Main content area - works with sidebar */
        .main-content-area {
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 72px);
            width: 100%;
        }

        /* Content wrapper with proper spacing */
        .content-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        @media (min-width: 640px) {
            .content-wrapper {
                padding: 0 1.5rem;
            }
        }

        @media (min-width: 1024px) {
            .content-wrapper {
                padding: 0 2rem;
            }
        }

        @media (min-width: 1280px) {
            .content-wrapper {
                padding: 0 2.5rem;
            }
        }

        @media (min-width: 1024px) {
            .main-content-area.lg\:ml-0 {
                margin-left: 0;
            }
            .main-content-area.lg\:ml-\[280px\] {
                margin-left: 280px;
            }
        }

        @media (max-width: 1023px) {
            .main-content-area {
                margin-left: 0 !important;
                padding: 1rem;
            }
            .content-wrapper {
                padding: 0 0.5rem;
            }
        }
    </style>
<?php
sahtout_admin_comp_item_styles();
$page_head = ob_get_clean();
?>

<?php include $project_root . 'includes/header.php'; ?>

    <!-- Main Content Area with Sidebar -->
    <div class="flex relative min-h-screen">
        
        <!-- Sidebar -->
        <?php include $project_root . 'includes/admin_sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content-area flex-1 p-3 sm:p-4 md:p-6 lg:p-8 transition-all duration-300 lg:ml-70">
            <div class="content-wrapper">
                <div class="space-y-4 md:space-y-6 lg:space-y-8">
                    
                    <h1 class="wow-title text-2xl md:text-3xl lg:text-4xl"><?php echo translate('admin_chars_title', 'Character Management'); ?></h1>
                    
                    <?php echo $update_message; ?>

                    <!-- Search Form -->
                    <div class="panel p-4 md:p-6 lg:p-8">
                        <form method="GET" action="<?php echo $base_path; ?>admin/characters">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_char_name', 'Character Name'); ?></label>
                                    <input type="text" name="search_char_name" class="input-dark" value="<?php echo htmlspecialchars($search_char_name); ?>" placeholder="<?php echo translate('admin_chars_placeholder_char_name', 'Enter character name'); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_username', 'Username'); ?></label>
                                    <input type="text" name="search_username" class="input-dark" value="<?php echo htmlspecialchars($search_username); ?>" placeholder="<?php echo translate('admin_chars_placeholder_username', 'Enter username'); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_min_level', 'Min Level'); ?></label>
                                    <input type="number" name="min_level" class="input-dark" value="<?php echo htmlspecialchars($min_level); ?>" placeholder="1" min="1" max="255">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_max_level', 'Max Level'); ?></label>
                                    <input type="number" name="max_level" class="input-dark" value="<?php echo htmlspecialchars($max_level); ?>" placeholder="255" min="1" max="255">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 md:gap-4 mt-4">
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_online_status', 'Online Status'); ?></label>
                                    <select name="online_filter" class="input-dark">
                                        <option value="" <?php echo $online_filter === '' ? 'selected' : ''; ?>><?php echo translate('admin_chars_option_all', 'All'); ?></option>
                                        <option value="online" <?php echo $online_filter === 'online' ? 'selected' : ''; ?>><?php echo translate('admin_chars_option_online', 'Online'); ?></option>
                                        <option value="offline" <?php echo $online_filter === 'offline' ? 'selected' : ''; ?>><?php echo translate('admin_chars_option_offline', 'Offline'); ?></option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_sort_id', 'Sort by ID'); ?></label>
                                    <select name="sort_id" class="input-dark">
                                        <option value="asc" <?php echo $sort_id === 'asc' ? 'selected' : ''; ?>><?php echo translate('admin_chars_option_sort_asc', 'Ascending'); ?></option>
                                        <option value="desc" <?php echo $sort_id === 'desc' ? 'selected' : ''; ?>><?php echo translate('admin_chars_option_sort_desc', 'Descending'); ?></option>
                                    </select>
                                </div>
                                <div class="flex items-end gap-2">
                                    <button type="submit" class="btn-gold w-full justify-center">
                                        <i class="fas fa-search"></i> <?php echo translate('admin_chars_search_button', 'Search'); ?>
                                    </button>
                                    <?php if ($search_char_name || $search_username || $online_filter !== '' || $min_level !== '' || $max_level !== ''): ?>
                                        <a href="<?php echo $base_path; ?>admin/characters" class="btn-iron btn-iron-sm whitespace-nowrap">
                                            <i class="fas fa-times"></i> <?php echo translate('admin_chars_clear_filters', 'Clear'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Characters Table -->
                    <div class="panel p-4 md:p-6 lg:p-8">
                        <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6 flex items-center gap-3">
                            <i class="fas fa-users text-[#f2cf5b]"></i>
                            <?php echo translate('admin_chars_table_header', 'Characters'); ?>
                            <span class="text-sm text-gray-400 font-normal">(<?php echo $total_chars; ?> <?php echo translate('admin_chars_total', 'total'); ?>)</span>
                        </h2>

                        <div class="overflow-x-auto -mx-4 md:-mx-6 lg:-mx-8 px-4 md:px-6 lg:px-8">
                            <table class="w-full table-dark">
                                <thead>
                                    <tr>
                                        <th class="col-num"><?php echo translate('admin_chars_table_char_id', 'ID'); ?></th>
                                        <th><?php echo translate('admin_chars_table_name', 'Name'); ?></th>
                                        <th class="hidden md:table-cell"><?php echo translate('admin_chars_table_username', 'Username'); ?></th>
                                        <th class="align-center"><?php echo translate('admin_chars_table_race', 'Race'); ?></th>
                                        <th class="align-center"><?php echo translate('admin_chars_table_class', 'Class'); ?></th>
                                        <th class="hidden lg:table-cell"><?php echo translate('admin_chars_table_map', 'Map'); ?></th>
                                        <th class="col-num"><?php echo translate('admin_chars_table_level', 'Level'); ?></th>
                                        <th class="align-center"><?php echo translate('admin_chars_table_online', 'Online'); ?></th>
                                        <th class="col-actions align-right"><?php echo translate('admin_chars_table_action', 'Action'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($characters)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-gray-400 py-6 md:py-8">
                                                <i class="fas fa-user-slash text-2xl md:text-3xl text-gray-600 block mb-2"></i>
                                                <?php echo translate('admin_chars_no_chars_found', 'No characters found.'); ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($characters as $char): ?>
                                            <tr>
                                                <td class="col-num text-sm md:text-base"><?php echo htmlspecialchars($char['guid']); ?></td>
                                                <td>
                                                    <a href="<?php echo $base_path; ?>character?guid=<?php echo (int) $char['guid']; ?>&amp;return=<?php echo urlencode($chars_list_return_url); ?>" class="char-name-link text-sm md:text-base">
                                                        <?php echo htmlspecialchars($char['name']); ?>
                                                    </a>
                                                </td>
                                                <td class="hidden md:table-cell text-gray-400 text-sm"><?php echo htmlspecialchars($char['username']); ?></td>
                                                <td class="align-center"><?php echo getRaceIcon($char['race'], $char['gender']); ?></td>
                                                <td class="align-center"><?php echo getClassIcon($char['class']); ?></td>
                                                <td class="hidden lg:table-cell text-sm text-gray-400"><?php echo htmlspecialchars(isset($map_names[$char['map']]) ? $map_names[$char['map']] : $char['map']); ?></td>
                                                <td class="col-num text-sm md:text-base"><?php echo htmlspecialchars($char['level']); ?></td>
                                                <td class="align-center"><?php echo getOnlineStatus($char['online']); ?></td>
                                                <td class="actions-cell">
                                                    <div class="action-buttons">
                                                    <button class="btn-iron btn-iron-sm" onclick="openModal('manageModal-<?php echo $char['guid']; ?>')">
                                                        <i class="fas fa-cog"></i> <?php echo translate('admin_chars_manage_button', 'Manage'); ?>
                                                    </button>
                                                    </div>
                                                </td>
                                            </tr>

                                            <!-- Manage Modal -->
                                            <div id="manageModal-<?php echo $char['guid']; ?>" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-backdrop">
                                                <div class="panel w-full max-w-xl p-6 md:p-8 relative max-h-[90vh] overflow-y-auto">
                                                    <button class="absolute top-4 right-4 text-gray-400 hover:text-white text-2xl" onclick="closeModal('manageModal-<?php echo $char['guid']; ?>')">&times;</button>
                                                    <h3 class="wow-title text-2xl mb-6"><?php echo translate('admin_chars_manage_modal_title', 'Manage Character: ') . htmlspecialchars($char['name']); ?></h3>
                                                    
                                                    <form method="POST" action="<?php echo $base_path; ?>admin/characters">
                                                        <input type="hidden" name="action" value="manage_character">
                                                        <input type="hidden" name="guid" value="<?php echo $char['guid']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        
                                                        <div class="mb-4">
                                                            <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_action', 'Action'); ?></label>
                                                            <select name="char_action" class="input-dark" id="charAction-<?php echo $char['guid']; ?>" onchange="toggleActionFields('<?php echo $char['guid']; ?>')">
                                                                <option value="add_gold"><?php echo translate('admin_chars_action_add_gold', 'Add Gold'); ?></option>
                                                                <option value="change_level"><?php echo translate('admin_chars_action_change_level', 'Change Level'); ?></option>
                                                                <option value="teleport"><?php echo translate('admin_chars_action_teleport', 'Teleport (Custom)'); ?></option>
                                                                <option value="teleport_directly"><?php echo translate('admin_chars_action_teleport_direct', 'Teleport Directly'); ?></option>
                                                                <option value="send_items" <?php echo $soapConfigured ? '' : 'disabled'; ?>><?php echo translate('admin_chars_action_send_items', 'Send Items'); ?></option>
                                                            </select>
                                                        </div>
                                                        
                                                        <div id="goldFields-<?php echo $char['guid']; ?>" class="action-field active">
                                                            <div class="mb-4">
                                                                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_gold', 'Gold Amount'); ?></label>
                                                                <input type="number" name="gold" class="input-dark" placeholder="<?php echo translate('admin_chars_placeholder_gold', 'Enter gold amount'); ?>" min="0" max="99999" maxlength="5">
                                                            </div>
                                                        </div>
                                                        
                                                        <div id="levelFields-<?php echo $char['guid']; ?>" class="action-field">
                                                            <div class="mb-4">
                                                                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_level', 'Level (1-255)'); ?></label>
                                                                <input type="number" name="level" class="input-dark" placeholder="<?php echo translate('admin_chars_placeholder_level', 'Enter level'); ?>" min="1" max="255" maxlength="3">
                                                            </div>
                                                        </div>
                                                        
                                                        <div id="teleportFields-<?php echo $char['guid']; ?>" class="action-field">
                                                            <div class="mb-4">
                                                                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide"><?php echo translate('admin_chars_label_map', 'Map'); ?></label>
                                                                <select name="map" class="input-dark">
                                                                    <?php foreach ($map_names as $id => $name): ?>
                                                                        <option value="<?php echo $id; ?>"><?php echo $id . ' - ' . htmlspecialchars($name); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="grid grid-cols-3 gap-4">
                                                                <div>
                                                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide">X</label>
                                                                    <input type="number" step="0.000001" name="x" class="input-dark" placeholder="X">
                                                                </div>
                                                                <div>
                                                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide">Y</label>
                                                                    <input type="number" step="0.000001" name="y" class="input-dark" placeholder="Y">
                                                                </div>
                                                                <div>
                                                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-1 font-['Cinzel'] tracking-wide">Z</label>
                                                                    <input type="number" step="0.000001" name="z" class="input-dark" placeholder="Z">
                                                                </div>
                                                            </div>
                                                            <div class="mt-3 p-2 bg-[rgba(201,162,39,0.05)] border border-[rgba(201,162,39,.1)] rounded-sm">
                                                                <p class="text-xs text-gray-400"><i class="fas fa-info-circle text-[#f2cf5b]"></i> <?php echo translate('admin_chars_teleport_tip', 'Tip: Use .gps command in game to get coordinates.'); ?></p>
                                                            </div>
                                                        </div>
                                                        
                                                        <div id="teleportDirectlyFields-<?php echo $char['guid']; ?>" class="action-field">
                                                            <div class="mb-4">
                                                                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="teleport-loc-<?php echo $char['guid']; ?>"><?php echo translate('admin_chars_label_destination', 'Destination'); ?></label>
                                                                <?php if (!$teleportLocationsReady): ?>
                                                                    <p class="text-xs text-amber-300/90"><?php echo translate('admin_chars_teleport_locations_unavailable', 'Teleport locations are not configured. Run the site database migration or add locations under Admin → Teleport Locations.'); ?></p>
                                                                <?php elseif ($teleportLocations === []): ?>
                                                                    <p class="text-xs text-gray-400"><?php echo translate('admin_chars_teleport_locations_empty', 'No active teleport locations. Add some under Admin → Teleport Locations.'); ?></p>
                                                                <?php else: ?>
                                                                    <select id="teleport-loc-<?php echo $char['guid']; ?>" name="teleport_location_id" class="input-dark" required>
                                                                        <?php foreach ($teleportLocations as $teleportLoc): ?>
                                                                            <option value="<?php echo (int) $teleportLoc['id']; ?>"><?php echo htmlspecialchars($teleportLoc['name']); ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>

                                                        <div id="sendItemsFields-<?php echo $char['guid']; ?>" class="action-field space-y-4">
                                                            <?php if (!$soapConfigured): ?>
                                                                <p class="text-amber-300/90 text-xs"><?php echo translate('admin_chars_send_items_soap_missing', 'SOAP is not configured. Set it up under Admin → Settings → SOAP to send in-game items.'); ?></p>
                                                            <?php else: ?>
                                                                <p class="text-xs text-gray-500"><?php echo translate('admin_chars_send_items_help', 'Items are sent via in-game mail (SOAP). The character may be online or offline.'); ?></p>
                                                                <div>
                                                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="mail-subject-<?php echo $char['guid']; ?>"><?php echo translate('admin_tickets_comp_mail_subject', 'Mail subject'); ?></label>
                                                                    <input type="text" id="mail-subject-<?php echo $char['guid']; ?>" name="mail_subject" class="input-dark" maxlength="128" value="<?php echo htmlspecialchars($defaultSendItemsSubject); ?>">
                                                                </div>
                                                                <div>
                                                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="mail-body-<?php echo $char['guid']; ?>"><?php echo translate('admin_tickets_comp_mail_body', 'Mail message'); ?></label>
                                                                    <textarea id="mail-body-<?php echo $char['guid']; ?>" name="mail_body" class="input-dark textarea-dark min-h-[5rem] resize-y" rows="3" maxlength="800"><?php echo htmlspecialchars($defaultSendItemsBody); ?></textarea>
                                                                </div>
                                                                <div>
                                                                    <span class="block text-xs text-gray-400 mb-2"><?php echo translate('admin_chars_send_items_items_heading', 'Items (up to 5)'); ?></span>
                                                                    <?php sahtout_admin_comp_item_attachment_grid(true); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <div class="flex justify-end gap-4 pt-4 mt-4 border-t border-[rgba(201,162,39,.1)]">
                                                            <button type="button" class="btn-iron" onclick="closeModal('manageModal-<?php echo $char['guid']; ?>')"><?php echo translate('admin_chars_cancel_button', 'Cancel'); ?></button>
                                                            <button type="submit" class="btn-gold"><?php echo translate('admin_chars_apply_button', 'Apply'); ?></button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php
                        sahtout_admin_render_pagination_nav([
                            'current_page' => $page,
                            'total_pages' => (int) $total_pages,
                            'base_path' => $base_path,
                            'route' => 'admin/characters',
                            'query' => [
                                'search_char_name' => $search_char_name,
                                'search_username' => $search_username,
                                'online_filter' => $online_filter,
                                'min_level' => $min_level,
                                'max_level' => $max_level,
                                'sort_id' => $sort_id,
                            ],
                            'aria_label' => translate('admin_chars_pagination_aria', 'Character pagination'),
                            'prev_label' => translate('admin_chars_previous', 'Previous'),
                            'next_label' => translate('admin_chars_next', 'Next'),
                        ]);
                        ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php sahtout_admin_comp_item_lookup_modal(); ?>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
            document.getElementById(id).classList.add('flex');
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }

        function toggleActionFields(id) {
            const action = document.getElementById('charAction-' + id);
            const goldFields = document.getElementById('goldFields-' + id);
            const levelFields = document.getElementById('levelFields-' + id);
            const teleportFields = document.getElementById('teleportFields-' + id);
            const teleportDirectlyFields = document.getElementById('teleportDirectlyFields-' + id);
            const sendItemsFields = document.getElementById('sendItemsFields-' + id);
            
            // Hide all
            [goldFields, levelFields, teleportFields, teleportDirectlyFields, sendItemsFields].forEach(el => {
                if (el) el.classList.remove('active');
            });
            
            // Show selected
            if (action.value === 'add_gold' && goldFields) {
                goldFields.classList.add('active');
            } else if (action.value === 'change_level' && levelFields) {
                levelFields.classList.add('active');
            } else if (action.value === 'teleport' && teleportFields) {
                teleportFields.classList.add('active');
            } else if (action.value === 'teleport_directly' && teleportDirectlyFields) {
                teleportDirectlyFields.classList.add('active');
            } else if (action.value === 'send_items' && sendItemsFields) {
                sendItemsFields.classList.add('active');
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('[id^="charAction-"]').forEach(function(el) {
                const id = el.id.replace('charAction-', '');
                toggleActionFields(id);
            });
        });

        // Enforce maxlength and max on number inputs (browsers ignore maxlength for type="number")
        document.addEventListener('input', function(e) {
            const el = e.target;
            if (el.type !== 'number') return;

            const maxLength = parseInt(el.getAttribute('maxlength'), 10);
            if (maxLength && el.value.length > maxLength) {
                el.value = el.value.slice(0, maxLength);
            }

            const max = parseFloat(el.getAttribute('max'));
            if (!isNaN(max) && el.value !== '' && parseFloat(el.value) > max) {
                el.value = max;
            }
        });
    </script>
    <?php sahtout_admin_comp_item_lookup_script($base_path); ?>
<?php
$site_db->close();
$auth_db->close();
$char_db->close();
?>
</body>
</html>