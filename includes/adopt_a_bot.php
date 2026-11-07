<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'site_settings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'soap_client.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'website_activity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'account_admin_actions.php';

const SAHTOUT_ADOPT_A_BOT_MAX_CHARACTERS = 10;
/** Max levels a pool bot may be above the buyer's highest character (inclusive). */
const SAHTOUT_ADOPT_A_BOT_LEVEL_ABOVE_CAP = 5;
/** Seconds to wait for a pool character to go offline after SOAP kick before adoption. */
const SAHTOUT_ADOPT_A_BOT_OFFLINE_WAIT_SECONDS = 25;
const SAHTOUT_ADOPT_A_BOT_DEFAULT_TOKEN_COST = 100;
const SAHTOUT_ADOPT_A_BOT_DEFAULT_POOL_USERNAME_PREFIX = 'RNDBOT';
const SAHTOUT_ADOPT_A_BOT_SHOP_IMAGE = 'img/shopimg/services/adopt-a-bot.jpg';
const SAHTOUT_ADOPT_A_BOT_SHOP_NAME = 'Adopt a Bot';

function sahtout_adopt_a_bot_config_override(): ?bool
{
    global $sahtout_local;
    $features = is_array($sahtout_local ?? null) ? ($sahtout_local['features'] ?? null) : null;
    if (!is_array($features) || !array_key_exists('adopt_a_bot_enabled', $features)) {
        return null;
    }
    $v = $features['adopt_a_bot_enabled'];
    if ($v === null) {
        return null;
    }

    return (bool) $v;
}

function sahtout_adopt_a_bot_enabled(mysqli $siteDb): bool
{
    global $sahtout_site_settings;
    $override = sahtout_adopt_a_bot_config_override();
    if ($override !== null) {
        return $override;
    }

    return !empty($sahtout_site_settings['shop.adopt_a_bot_enabled']);
}

/** Username prefix for bot pool auth accounts (e.g. RNDBOT matches RNDBOT123). */
function sahtout_adopt_a_bot_pool_username_prefix(mysqli $siteDb): string
{
    global $sahtout_site_settings;
    $prefix = trim((string) ($sahtout_site_settings['shop.adopt_a_bot_pool_username_prefix'] ?? ''));
    if ($prefix === '') {
        $legacy = trim((string) ($sahtout_site_settings['shop.adopt_a_bot_pool_username'] ?? ''));
        if ($legacy !== '') {
            return $legacy;
        }

        return SAHTOUT_ADOPT_A_BOT_DEFAULT_POOL_USERNAME_PREFIX;
    }

    return $prefix;
}

/**
 * @return list<int>
 */
function sahtout_adopt_a_bot_pool_account_ids(mysqli $authDb, mysqli $siteDb): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $prefix = sahtout_adopt_a_bot_pool_username_prefix($siteDb);
    if ($prefix === '') {
        $cache = [];

        return $cache;
    }

    $like = $prefix . '%';
    $stmt = $authDb->prepare('SELECT id FROM account WHERE username LIKE ? ORDER BY id ASC');
    if (!$stmt) {
        $cache = [];

        return $cache;
    }
    $stmt->bind_param('s', $like);
    if (!$stmt->execute()) {
        $stmt->close();
        $cache = [];

        return $cache;
    }
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    $stmt->close();
    $cache = $ids;

    return $cache;
}

function sahtout_adopt_a_bot_pool_configured(mysqli $authDb, mysqli $siteDb): bool
{
    return sahtout_adopt_a_bot_pool_account_ids($authDb, $siteDb) !== [];
}

/**
 * @param list<int> $poolAccountIds
 */
function sahtout_adopt_a_bot_sql_account_in_list(array $poolAccountIds): string
{
    $poolAccountIds = array_values(array_unique(array_filter(array_map('intval', $poolAccountIds), static fn (int $id): bool => $id > 0)));
    if ($poolAccountIds === []) {
        return '0';
    }

    return implode(',', $poolAccountIds);
}

function sahtout_adopt_a_bot_claims_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'adopt_a_bot_claims'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_adopt_a_bot_claims_column_exists(mysqli $siteDb, string $column): bool
{
    $column = trim($column);
    if ($column === '') {
        return false;
    }
    $res = $siteDb->query(
        "SHOW COLUMNS FROM adopt_a_bot_claims LIKE '" . $siteDb->real_escape_string($column) . "'"
    );

    return $res && $res->num_rows > 0;
}

function sahtout_adopt_a_bot_claims_upgrade_schema(mysqli $siteDb): void
{
    static $upgraded = false;
    if ($upgraded || !sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return;
    }
    $upgraded = true;

    if (!sahtout_adopt_a_bot_claims_column_exists($siteDb, 'progress_message')) {
        $siteDb->query(
            'ALTER TABLE adopt_a_bot_claims
                MODIFY status enum(\'pending\',\'processing\',\'completed\',\'cancelled\') NOT NULL DEFAULT \'pending\',
                ADD COLUMN progress_message varchar(500) DEFAULT NULL AFTER bot_character_name,
                ADD COLUMN progress_log text DEFAULT NULL AFTER progress_message'
        );
    }
}

function sahtout_adopt_a_bot_claims_ensure_schema(mysqli $siteDb): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        $ok = (bool) $siteDb->query(
            'CREATE TABLE IF NOT EXISTS adopt_a_bot_claims (
            claim_id int unsigned NOT NULL AUTO_INCREMENT,
            account_id int unsigned NOT NULL,
            purchase_id int unsigned NOT NULL,
            status enum(\'pending\',\'processing\',\'completed\',\'cancelled\') NOT NULL DEFAULT \'pending\',
            bot_character_guid int unsigned DEFAULT NULL,
            bot_character_name varchar(12) DEFAULT NULL,
            progress_message varchar(500) DEFAULT NULL,
            progress_log text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at timestamp NULL DEFAULT NULL,
            PRIMARY KEY (claim_id),
            UNIQUE KEY uq_purchase (purchase_id),
            KEY idx_account_status (account_id, status),
            CONSTRAINT fk_adopt_claim_account FOREIGN KEY (account_id) REFERENCES user_currencies (account_id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_adopt_claim_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (purchase_id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        if (!$ok) {
            return false;
        }
    }

    sahtout_adopt_a_bot_claims_upgrade_schema($siteDb);

    return true;
}

function sahtout_adopt_a_bot_configured_realm_id(): int
{
    global $realmlist;
    if (!empty($realmlist[0]['id'])) {
        return (int) $realmlist[0]['id'];
    }

    return 1;
}

function sahtout_adopt_a_bot_resolve_shop_item_id(mysqli $siteDb): int
{
    global $sahtout_site_settings;
    $stored = (int) ($sahtout_site_settings['shop.adopt_a_bot_item_id'] ?? 0);
    if ($stored <= 0) {
        return 0;
    }
    $stmt = $siteDb->prepare('SELECT item_id FROM shop_items WHERE item_id = ? LIMIT 1');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $stored);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows === 1;
    $stmt->close();

    return $ok ? $stored : 0;
}

function sahtout_adopt_a_bot_is_shop_item_id(mysqli $siteDb, int $itemId): bool
{
    if ($itemId <= 0) {
        return false;
    }

    return $itemId === sahtout_adopt_a_bot_resolve_shop_item_id($siteDb);
}

/**
 * Create or remove the catalog row when the feature is toggled. Price is set only on insert (default 100); edit in A-Shop afterward.
 */
function sahtout_adopt_a_bot_sync_shop_item(mysqli $siteDb, bool $enabled, int $editorId = 0): bool
{
    if (!sahtout_adopt_a_bot_claims_ensure_schema($siteDb)) {
        return false;
    }

    $itemId = sahtout_adopt_a_bot_resolve_shop_item_id($siteDb);

    if (!$enabled) {
        if ($itemId > 0) {
            $stmt = $siteDb->prepare('DELETE FROM shop_items WHERE item_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $itemId);
                $stmt->execute();
                $stmt->close();
            }
        }
        sahtout_site_setting_set($siteDb, 'shop.adopt_a_bot_item_id', 0, 'int', $editorId);

        return true;
    }

    if ($itemId > 0) {
        return true;
    }

    $category = 'Service';
    $name = SAHTOUT_ADOPT_A_BOT_SHOP_NAME;
    $description = 'Purchase an adoption slot, then choose a ready-made character from the bot pool on the website.';
    $image = SAHTOUT_ADOPT_A_BOT_SHOP_IMAGE;
    $tokenCost = SAHTOUT_ADOPT_A_BOT_DEFAULT_TOKEN_COST;
    $stock = null;
    $entry = null;
    $goldAmount = 0;
    $levelBoost = null;
    $atLogin = 0;
    $isItem = 0;
    $isSet = 0;
    $itemsetId = null;

    $sql = 'INSERT INTO shop_items (category, name, description, image, token_cost, stock, entry, gold_amount, level_boost, at_login_flags, is_item, is_set, itemset_id)
            VALUES (?, ?, ?, ?, ?, NULL, NULL, 0, NULL, 0, 0, 0, NULL)';
    $stmt = $siteDb->prepare($sql);
    if (!$stmt) {
        error_log('adopt_a_bot: shop insert prepare failed: ' . $siteDb->error);

        return false;
    }
    $stmt->bind_param('ssssi', $category, $name, $description, $image, $tokenCost);
    if (!$stmt->execute()) {
        error_log('adopt_a_bot: shop insert failed: ' . $stmt->error);
        $stmt->close();

        return false;
    }
    $newId = (int) $siteDb->insert_id;
    $stmt->close();

    return sahtout_site_setting_set($siteDb, 'shop.adopt_a_bot_item_id', $newId, 'int', $editorId);
}

function sahtout_adopt_a_bot_character_count(mysqli $charDb, int $accountId): int
{
    $stmt = $charDb->prepare('SELECT COUNT(*) AS c FROM characters WHERE account = ?');
    if (!$stmt) {
        return SAHTOUT_ADOPT_A_BOT_MAX_CHARACTERS;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $c = (int) ($res->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $c;
}

function sahtout_adopt_a_bot_has_character_slot(mysqli $charDb, int $accountId): bool
{
    return sahtout_adopt_a_bot_character_count($charDb, $accountId) < SAHTOUT_ADOPT_A_BOT_MAX_CHARACTERS;
}

/** Highest level among characters on the buyer account (0 if they have none). */
function sahtout_adopt_a_bot_highest_character_level(mysqli $charDb, int $accountId): int
{
    $stmt = $charDb->prepare('SELECT COALESCE(MAX(level), 0) AS mx FROM characters WHERE account = ?');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $mx = (int) ($res->fetch_assoc()['mx'] ?? 0);
    $stmt->close();

    return max(0, $mx);
}

function sahtout_adopt_a_bot_max_adoptable_bot_level(mysqli $charDb, int $accountId): int
{
    return sahtout_adopt_a_bot_highest_character_level($charDb, $accountId) + SAHTOUT_ADOPT_A_BOT_LEVEL_ABOVE_CAP;
}

function sahtout_adopt_a_bot_is_bot_level_allowed(int $botLevel, int $buyerHighestLevel): bool
{
    return $botLevel <= $buyerHighestLevel + SAHTOUT_ADOPT_A_BOT_LEVEL_ABOVE_CAP;
}

function sahtout_adopt_a_bot_pool_has_eligible_bot(
    mysqli $charDb,
    mysqli $authDb,
    mysqli $siteDb,
    int $buyerAccountId
): bool {
    $poolAccountIds = sahtout_adopt_a_bot_pool_account_ids($authDb, $siteDb);
    if ($poolAccountIds === []) {
        return false;
    }
    $maxLevel = sahtout_adopt_a_bot_max_adoptable_bot_level($charDb, $buyerAccountId);
    $inList = sahtout_adopt_a_bot_sql_account_in_list($poolAccountIds);
    $sql = 'SELECT guid FROM characters WHERE account IN (' . $inList . ') AND level <= ? LIMIT 1';
    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $maxLevel);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

function sahtout_adopt_a_bot_has_completed_claim(mysqli $siteDb, int $accountId): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return false;
    }
    $stmt = $siteDb->prepare(
        "SELECT claim_id FROM adopt_a_bot_claims WHERE account_id = ? AND status = 'completed' LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $has = $res && $res->num_rows > 0;
    $stmt->close();

    return $has;
}

/** False when the account already finished Adopt a Bot (completed claim). Pending claims are resumed, not rebought. */
function sahtout_adopt_a_bot_account_may_purchase(mysqli $siteDb, int $accountId): bool
{
    return !sahtout_adopt_a_bot_has_completed_claim($siteDb, $accountId);
}

function sahtout_adopt_a_bot_get_pending_claim(mysqli $siteDb, int $accountId): ?array
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return null;
    }
    $stmt = $siteDb->prepare(
        "SELECT claim_id, purchase_id, created_at, status, bot_character_guid, bot_character_name, progress_message, progress_log
         FROM adopt_a_bot_claims WHERE account_id = ? AND status = 'pending' ORDER BY claim_id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

/** Pending or in-progress adoption for this account. */
function sahtout_adopt_a_bot_get_open_claim(mysqli $siteDb, int $accountId): ?array
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return null;
    }
    sahtout_adopt_a_bot_claims_upgrade_schema($siteDb);
    $stmt = $siteDb->prepare(
        "SELECT claim_id, purchase_id, created_at, status, bot_character_guid, bot_character_name, progress_message, progress_log
         FROM adopt_a_bot_claims WHERE account_id = ? AND status IN ('pending', 'processing') ORDER BY claim_id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function sahtout_adopt_a_bot_resume_url(?array $claim, string $basePath): string
{
    if ($claim === null) {
        return $basePath . 'adopt-a-bot';
    }
    $status = (string) ($claim['status'] ?? '');
    $guid = (int) ($claim['bot_character_guid'] ?? 0);
    if ($guid > 0 && $status === 'processing') {
        return $basePath . 'character?guid=' . $guid . '&adopt=processing';
    }
    if ($guid > 0 && $status === 'pending') {
        return $basePath . 'character?guid=' . $guid . '&adopt=1';
    }

    return $basePath . 'adopt-a-bot';
}

/**
 * @return list<string>
 */
function sahtout_adopt_a_bot_progress_log_lines(?array $claim): array
{
    if ($claim === null) {
        return [];
    }
    $raw = trim((string) ($claim['progress_log'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $lines = [];
    foreach ($decoded as $line) {
        if (is_string($line) && trim($line) !== '') {
            $lines[] = trim($line);
        }
    }

    return $lines;
}

function sahtout_adopt_a_bot_set_progress(mysqli $siteDb, int $claimId, int $accountId, string $message, bool $appendLog = true): void
{
    sahtout_adopt_a_bot_claims_upgrade_schema($siteDb);
    $message = mb_substr(trim($message), 0, 500);
    if ($message === '') {
        return;
    }

    $claim = null;
    $stmt = $siteDb->prepare(
        'SELECT progress_log FROM adopt_a_bot_claims WHERE claim_id = ? AND account_id = ? LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('ii', $claimId, $accountId);
        $stmt->execute();
        $res = $stmt->get_result();
        $claim = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    $lines = sahtout_adopt_a_bot_progress_log_lines($claim);
    if ($appendLog) {
        $lines[] = $message;
        if (count($lines) > 40) {
            $lines = array_slice($lines, -40);
        }
    }
    $logJson = json_encode($lines, JSON_UNESCAPED_UNICODE);
    if (!is_string($logJson)) {
        $logJson = '[]';
    }

    $stmt = $siteDb->prepare(
        'UPDATE adopt_a_bot_claims SET progress_message = ?, progress_log = ? WHERE claim_id = ? AND account_id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('ssii', $message, $logJson, $claimId, $accountId);
        $stmt->execute();
        $stmt->close();
    }
}

function sahtout_adopt_a_bot_start_processing(
    mysqli $siteDb,
    int $claimId,
    int $accountId,
    int $botGuid,
    string $botName
): bool {
    sahtout_adopt_a_bot_claims_upgrade_schema($siteDb);
    $botName = mb_substr(trim($botName), 0, 12);
    $intro = 'Adoption queued. We will complete the transfer as soon as the character is offline.';
    $logJson = json_encode([$intro], JSON_UNESCAPED_UNICODE);
    if (!is_string($logJson)) {
        $logJson = '[]';
    }

    $stmt = $siteDb->prepare(
        "UPDATE adopt_a_bot_claims
         SET status = 'processing', bot_character_guid = ?, bot_character_name = ?, progress_message = ?, progress_log = ?
         WHERE claim_id = ? AND account_id = ? AND status = 'pending'"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('isssii', $botGuid, $botName, $intro, $logJson, $claimId, $accountId);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();

    return $ok;
}

/**
 * @return array{ok:bool, error?:string, character_name?:string, done?:bool}
 */
function sahtout_adopt_a_bot_process_open_claim(
    mysqli $siteDb,
    mysqli $charDb,
    mysqli $authDb,
    int $accountId,
    string $charDbName,
    string $authDbName
): array {
    $open = sahtout_adopt_a_bot_get_open_claim($siteDb, $accountId);
    if ($open === null) {
        return ['ok' => false, 'error' => 'invalid_claim'];
    }
    $status = (string) ($open['status'] ?? '');
    $claimId = (int) ($open['claim_id'] ?? 0);
    $botGuid = (int) ($open['bot_character_guid'] ?? 0);
    if ($claimId <= 0 || $botGuid <= 0) {
        return ['ok' => false, 'error' => 'invalid_claim'];
    }
    if ($status === 'pending') {
        return ['ok' => false, 'error' => 'not_started'];
    }

    sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, 'Checking character and disconnecting from the world if needed…');

    $result = sahtout_adopt_a_bot_complete_claim(
        $siteDb,
        $charDb,
        $authDb,
        $accountId,
        $claimId,
        $botGuid,
        $charDbName,
        $authDbName
    );

    if (!empty($result['ok'])) {
        sahtout_adopt_a_bot_set_progress(
            $siteDb,
            $claimId,
            $accountId,
            'Adoption complete! The character is on your account.'
        );

        return ['ok' => true, 'done' => true, 'character_name' => (string) ($result['character_name'] ?? '')];
    }

    $err = (string) ($result['error'] ?? 'error');
    if ($err === 'bot_online') {
        sahtout_adopt_a_bot_set_progress(
            $siteDb,
            $claimId,
            $accountId,
            'Still waiting for the character to go offline. We will keep trying…'
        );

        return ['ok' => false, 'error' => 'bot_online', 'done' => false];
    }

    sahtout_adopt_a_bot_set_progress(
        $siteDb,
        $claimId,
        $accountId,
        'Adoption could not be completed: ' . $err
    );

    return ['ok' => false, 'error' => $err, 'done' => false];
}

/**
 * @param list<int> $poolAccountIds
 * @return list<array{guid:int,name:string,level:int,race:int,class:int,gender:int}>
 */
function sahtout_adopt_a_bot_list_pool_characters(
    mysqli $charDb,
    array $poolAccountIds,
    string $search,
    int $limit = 50,
    ?int $maxBotLevel = null
): array {
    $inList = sahtout_adopt_a_bot_sql_account_in_list($poolAccountIds);
    if ($inList === '0') {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        if ($maxBotLevel !== null) {
            $sql = 'SELECT guid, name, level, race, class, gender FROM characters WHERE account IN (' . $inList . ') AND name LIKE ? AND level <= ? ORDER BY name ASC LIMIT ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('sii', $like, $maxBotLevel, $limit);
        } else {
            $sql = 'SELECT guid, name, level, race, class, gender FROM characters WHERE account IN (' . $inList . ') AND name LIKE ? ORDER BY name ASC LIMIT ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('si', $like, $limit);
        }
    } else {
        if ($maxBotLevel !== null) {
            $sql = 'SELECT guid, name, level, race, class, gender FROM characters WHERE account IN (' . $inList . ') AND level <= ? ORDER BY name ASC LIMIT ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('ii', $maxBotLevel, $limit);
        } else {
            $sql = 'SELECT guid, name, level, race, class, gender FROM characters WHERE account IN (' . $inList . ') ORDER BY name ASC LIMIT ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('i', $limit);
        }
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'guid' => (int) $row['guid'],
            'name' => (string) $row['name'],
            'level' => (int) $row['level'],
            'race' => (int) $row['race'],
            'class' => (int) $row['class'],
            'gender' => (int) ($row['gender'] ?? 0),
        ];
    }
    $stmt->close();

    return $rows;
}

/**
 * @return ?array{guid:int,name:string,level:int,race:int,class:int,gender:int,online:int,account:int}
 */
function sahtout_adopt_a_bot_fetch_eligible_pool_character(
    mysqli $charDb,
    mysqli $authDb,
    mysqli $siteDb,
    int $buyerAccountId,
    int $botGuid
): ?array {
    if ($botGuid <= 0) {
        return null;
    }
    $poolAccountIds = sahtout_adopt_a_bot_pool_account_ids($authDb, $siteDb);
    if ($poolAccountIds === []) {
        return null;
    }
    $maxLevel = sahtout_adopt_a_bot_max_adoptable_bot_level($charDb, $buyerAccountId);
    $inList = sahtout_adopt_a_bot_sql_account_in_list($poolAccountIds);
    $sql = 'SELECT guid, name, level, race, class, gender, online, account FROM characters WHERE guid = ? AND account IN ('
        . $inList . ') AND level <= ? LIMIT 1';
    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $botGuid, $maxLevel);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }

    return [
        'guid' => (int) $row['guid'],
        'name' => (string) $row['name'],
        'level' => (int) $row['level'],
        'race' => (int) $row['race'],
        'class' => (int) $row['class'],
        'gender' => (int) ($row['gender'] ?? 0),
        'online' => (int) ($row['online'] ?? 0),
        'account' => (int) $row['account'],
    ];
}

function sahtout_adopt_a_bot_faction_icon(int $race): string
{
    global $base_path;
    $alliance = [1, 3, 4, 7, 11, 22, 25, 29];
    $faction = in_array($race, $alliance, true) ? 'alliance' : 'horde';

    return $base_path . 'img/accountimg/faction/' . $faction . '.png';
}

function sahtout_adopt_a_bot_race_icon(int $race, int $gender): string
{
    global $base_path;
    $genderFolder = ($gender === 0) ? 'male' : 'female';
    $raceMap = [
        1 => 'human', 2 => 'orc', 3 => 'dwarf', 4 => 'nightelf',
        5 => 'undead', 6 => 'tauren', 7 => 'gnome', 8 => 'troll',
        9 => 'goblin', 10 => 'bloodelf', 11 => 'draenei',
        22 => 'worgen', 25 => 'pandaren_alliance', 26 => 'pandaren_horde',
        29 => 'voidelf',
    ];
    $raceName = $raceMap[$race] ?? 'unknown';

    return $base_path . "img/accountimg/race/{$genderFolder}/{$raceName}.png";
}

function sahtout_adopt_a_bot_class_icon(int $class): string
{
    global $base_path;
    $classMap = [
        1 => 'warrior', 2 => 'paladin', 3 => 'hunter', 4 => 'rogue',
        5 => 'priest', 6 => 'deathknight', 7 => 'shaman', 8 => 'mage',
        9 => 'warlock', 10 => 'monk', 11 => 'druid', 12 => 'demonhunter',
    ];
    $className = $classMap[$class] ?? 'unknown';

    return $base_path . "img/accountimg/class/{$className}.webp";
}

function sahtout_adopt_a_bot_character_is_online(mysqli $charDb, int $guid): bool
{
    $stmt = $charDb->prepare('SELECT online FROM characters WHERE guid = ? LIMIT 1');
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('i', $guid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return true;
    }

    return (int) ($row['online'] ?? 0) === 1;
}

/** Kick via SOAP when configured, then poll until offline or timeout. */
function sahtout_adopt_a_bot_prepare_character_offline(
    mysqli $charDb,
    string $charDbName,
    int $guid,
    int $maxWaitSeconds = SAHTOUT_ADOPT_A_BOT_OFFLINE_WAIT_SECONDS
): bool {
    if (!sahtout_adopt_a_bot_character_is_online($charDb, $guid)) {
        return true;
    }
    sahtout_adopt_a_bot_kick_character_if_online($charDb, $charDbName, $guid);
    $deadline = time() + max(1, $maxWaitSeconds);
    $lastKickAt = time();
    while (time() < $deadline) {
        usleep(800000);
        if (!sahtout_adopt_a_bot_character_is_online($charDb, $guid)) {
            return true;
        }
        if (time() - $lastKickAt >= 3) {
            sahtout_adopt_a_bot_kick_character_if_online($charDb, $charDbName, $guid);
            $lastKickAt = time();
        }
    }

    return !sahtout_adopt_a_bot_character_is_online($charDb, $guid);
}

function sahtout_adopt_a_bot_kick_character_if_online(mysqli $charDb, string $charDbName, int $guid): void
{
    if (!sahtout_soap_is_configured()) {
        return;
    }
    $stmt = $charDb->prepare(
        'SELECT name, online FROM `' . $charDbName . '`.characters WHERE guid = ? LIMIT 1'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $guid);
    if (!$stmt->execute()) {
        $stmt->close();

        return;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row || (int) ($row['online'] ?? 0) !== 1) {
        return;
    }
    $name = trim((string) ($row['name'] ?? ''));
    if ($name !== '') {
        sahtout_soap_execute_command('.kick ' . sahtout_soap_character_name_for_command($name));
    }
}

function sahtout_adopt_a_bot_adjust_realm_characters(
    mysqli $authDb,
    string $authDbName,
    int $realmId,
    int $accountId,
    int $delta
): bool {
    if ($delta === 0) {
        return true;
    }
    $realmId = (int) $realmId;
    $accountId = (int) $accountId;
    $delta = (int) $delta;

    $stmt = $authDb->prepare(
        'SELECT numchars FROM `' . $authDbName . '`.realmcharacters WHERE realmid = ? AND acctid = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $realmId, $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        $newCount = max(0, (int) $row['numchars'] + $delta);
        $stmt = $authDb->prepare(
            'UPDATE `' . $authDbName . '`.realmcharacters SET numchars = ? WHERE realmid = ? AND acctid = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $newCount, $realmId, $accountId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    if ($delta < 0) {
        return true;
    }

    $stmt = $authDb->prepare(
        'INSERT INTO `' . $authDbName . '`.realmcharacters (realmid, acctid, numchars) VALUES (?, ?, ?)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $realmId, $accountId, $delta);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * @return array{ok:bool, error?:string, character_name?:string}
 */
function sahtout_adopt_a_bot_complete_claim(
    mysqli $siteDb,
    mysqli $charDb,
    mysqli $authDb,
    int $accountId,
    int $claimId,
    int $botGuid,
    string $charDbName,
    string $authDbName
): array {
    sahtout_adopt_a_bot_claims_ensure_schema($siteDb);

    if (!sahtout_adopt_a_bot_has_character_slot($charDb, $accountId)) {
        return ['ok' => false, 'error' => 'no_slot'];
    }

    $stmt = $siteDb->prepare(
        "SELECT claim_id, purchase_id FROM adopt_a_bot_claims WHERE claim_id = ? AND account_id = ? AND status IN ('pending', 'processing') LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'database'];
    }
    $stmt->bind_param('ii', $claimId, $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $claim = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$claim) {
        return ['ok' => false, 'error' => 'invalid_claim'];
    }

    $poolAccountIds = sahtout_adopt_a_bot_pool_account_ids($authDb, $siteDb);
    if ($poolAccountIds === []) {
        return ['ok' => false, 'error' => 'pool_missing'];
    }
    $poolInList = sahtout_adopt_a_bot_sql_account_in_list($poolAccountIds);

    $eligible = sahtout_adopt_a_bot_fetch_eligible_pool_character($charDb, $authDb, $siteDb, $accountId, $botGuid);
    if ($eligible === null) {
        return ['ok' => false, 'error' => 'bot_not_found'];
    }

    sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, 'Preparing transfer (offline check)…');

    if (!sahtout_adopt_a_bot_prepare_character_offline($charDb, $charDbName, $botGuid)) {
        return ['ok' => false, 'error' => 'bot_online'];
    }

    if (!$charDb->begin_transaction()) {
        return ['ok' => false, 'error' => 'database'];
    }
    $sql = 'SELECT guid, name, level, online, account FROM characters WHERE guid = ? AND account IN (' . $poolInList . ') FOR UPDATE';
    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        $charDb->rollback();

        return ['ok' => false, 'error' => 'database'];
    }
    $stmt->bind_param('i', $botGuid);
    if (!$stmt->execute()) {
        $stmt->close();
        $charDb->rollback();

        return ['ok' => false, 'error' => 'database'];
    }
    $res = $stmt->get_result();
    $bot = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$bot) {
        $charDb->rollback();

        return ['ok' => false, 'error' => 'bot_not_found'];
    }
    $botLevel = (int) ($bot['level'] ?? 0);
    $buyerHighest = sahtout_adopt_a_bot_highest_character_level($charDb, $accountId);
    if (!sahtout_adopt_a_bot_is_bot_level_allowed($botLevel, $buyerHighest)) {
        $charDb->rollback();

        return ['ok' => false, 'error' => 'level_too_high'];
    }
    if ((int) ($bot['online'] ?? 0) === 1) {
        $charDb->rollback();

        return ['ok' => false, 'error' => 'bot_online'];
    }

    $poolAccountId = (int) ($bot['account'] ?? 0);
    if ($poolAccountId <= 0) {
        $charDb->rollback();

        return ['ok' => false, 'error' => 'bot_not_found'];
    }

    $charName = (string) $bot['name'];
    $upd = $charDb->prepare('UPDATE characters SET account = ? WHERE guid = ? AND account = ?');
    if (!$upd) {
        $charDb->rollback();

        return ['ok' => false, 'error' => 'database'];
    }
    $upd->bind_param('iii', $accountId, $botGuid, $poolAccountId);
    if (!$upd->execute() || $upd->affected_rows !== 1) {
        $upd->close();
        $charDb->rollback();

        return ['ok' => false, 'error' => 'transfer_failed'];
    }
    $upd->close();

    if (!$charDb->commit()) {
        return ['ok' => false, 'error' => 'database'];
    }

    $realmId = sahtout_adopt_a_bot_configured_realm_id();
    if (!sahtout_adopt_a_bot_adjust_realm_characters($authDb, $authDbName, $realmId, $poolAccountId, -1)) {
        error_log('adopt_a_bot: failed to decrement pool realmcharacters');
    }
    if (!sahtout_adopt_a_bot_adjust_realm_characters($authDb, $authDbName, $realmId, $accountId, 1)) {
        error_log('adopt_a_bot: failed to increment buyer realmcharacters');
    }

    $stmt = $siteDb->prepare(
        "UPDATE adopt_a_bot_claims SET status = 'completed', bot_character_guid = ?, bot_character_name = ?, completed_at = NOW() WHERE claim_id = ? AND account_id = ?"
    );
    if ($stmt) {
        $stmt->bind_param('isii', $botGuid, $charName, $claimId, $accountId);
        $stmt->execute();
        $stmt->close();
    }

    sahtout_log_website_activity(
        $siteDb,
        $accountId,
        'Adopt a Bot',
        'Adopted character GUID ' . $botGuid . ' from pool account.',
        $charName
    );

    return ['ok' => true, 'character_name' => $charName];
}
