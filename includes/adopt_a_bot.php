<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'site_settings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'soap_client.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'website_activity.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'account_admin_actions.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'armory_playerbots.php';

const SAHTOUT_ADOPT_A_BOT_MAX_CHARACTERS = 10;
/** Max levels a pool bot may be above the buyer's highest character (inclusive). */
const SAHTOUT_ADOPT_A_BOT_LEVEL_ABOVE_CAP = 5;
/** Seconds to wait for a pool character to go offline after SOAP kick before adoption (sync confirm). */
const SAHTOUT_ADOPT_A_BOT_OFFLINE_WAIT_SECONDS = 25;
/** Per progress-poll wait (0 = one kick/check per request; client polls again). */
const SAHTOUT_ADOPT_A_BOT_OFFLINE_POLL_WAIT_SECONDS = 0;
/** Seconds between SOAP disconnect retries while a rndbot keeps relogging. */
const SAHTOUT_ADOPT_A_BOT_DISCONNECT_RETRY_SECONDS = 12;
/** playerbots_random_bots logout hold — blocks RandomPlayerbotMgr from re-adding the bot. */
const SAHTOUT_ADOPT_A_BOT_PLAYERBOT_OFFLINE_HOLD_SECONDS = 900;
const SAHTOUT_ADOPT_A_BOT_DEFAULT_TOKEN_COST = 100;
/** Bots shown per page on the adoption picker (pool can be large). */
const SAHTOUT_ADOPT_A_BOT_PICKER_PAGE_SIZE = 25;
const SAHTOUT_ADOPT_A_BOT_DEFAULT_POOL_USERNAME_PREFIX = 'RNDBOT';
const SAHTOUT_ADOPT_A_BOT_SHOP_IMAGE = 'img/shopimg/services/adopt-a-bot.jpg';
const SAHTOUT_ADOPT_A_BOT_SHOP_NAME = 'Adopt a Bot';
/** AzerothCore AT_LOGIN flag: appearance/customize at next login (same as shop “Customize Character”). */
const SAHTOUT_AT_LOGIN_CUSTOMIZE = 8;
/** OR’d with customize on adoption so the client shows the barber flow reliably after transfer. */
const SAHTOUT_AT_LOGIN_FIRST = 32;
const SAHTOUT_AT_LOGIN_ADOPT_APPEARANCE = 40;

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

function sahtout_adopt_a_bot_status_supports_processing(mysqli $siteDb): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return false;
    }
    $res = $siteDb->query("SHOW COLUMNS FROM adopt_a_bot_claims LIKE 'status'");
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    $res->free();
    if (!$row) {
        return false;
    }

    return str_contains(strtolower((string) ($row['Type'] ?? '')), 'processing');
}

function sahtout_adopt_a_bot_status_supports_draft(mysqli $siteDb): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return false;
    }
    $res = $siteDb->query("SHOW COLUMNS FROM adopt_a_bot_claims LIKE 'status'");
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    $res->free();
    if (!$row) {
        return false;
    }

    return str_contains(strtolower((string) ($row['Type'] ?? '')), 'draft');
}

function sahtout_adopt_a_bot_claims_purchase_id_nullable(mysqli $siteDb): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return false;
    }
    $res = $siteDb->query("SHOW COLUMNS FROM adopt_a_bot_claims LIKE 'purchase_id'");
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    $res->free();
    if (!$row) {
        return false;
    }

    return strtoupper((string) ($row['Null'] ?? '')) === 'YES';
}

function sahtout_adopt_a_bot_claims_supports_unpaid_draft(mysqli $siteDb): bool
{
    return sahtout_adopt_a_bot_status_supports_draft($siteDb)
        && sahtout_adopt_a_bot_claims_purchase_id_nullable($siteDb);
}

function sahtout_adopt_a_bot_claims_upgrade_draft_schema(mysqli $siteDb): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return false;
    }
    if (sahtout_adopt_a_bot_claims_supports_unpaid_draft($siteDb)) {
        return true;
    }

    static $attempted = false;
    if ($attempted) {
        return sahtout_adopt_a_bot_claims_supports_unpaid_draft($siteDb);
    }
    $attempted = true;

    try {
        $siteDb->query(
            "ALTER TABLE adopt_a_bot_claims
                MODIFY purchase_id int unsigned DEFAULT NULL,
                MODIFY status enum('draft','pending','processing','completed','cancelled') NOT NULL DEFAULT 'draft'"
        );
    } catch (mysqli_sql_exception $e) {
        error_log(
            'adopt_a_bot: draft schema upgrade skipped — run SQL/Hearthstone_UPDATE/2026110701_adopt-a-bot.sql as DBA: '
            . $e->getMessage()
        );
    }

    return sahtout_adopt_a_bot_claims_supports_unpaid_draft($siteDb);
}

/** True when progress columns exist and status enum includes processing (async adoption UI). */
function sahtout_adopt_a_bot_claims_extended_schema(mysqli $siteDb, bool $forceRefresh = false): bool
{
    static $ready = null;
    if ($forceRefresh) {
        $ready = null;
    }
    if ($ready !== null) {
        return $ready;
    }
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        $ready = false;

        return false;
    }
    $ready = sahtout_adopt_a_bot_claims_column_exists($siteDb, 'progress_message')
        && sahtout_adopt_a_bot_status_supports_processing($siteDb);

    return $ready;
}

/**
 * Attempt ALTER when the app DB user is allowed; otherwise log once and keep serving with legacy schema.
 */
function sahtout_adopt_a_bot_claims_upgrade_schema(mysqli $siteDb): bool
{
    if (sahtout_adopt_a_bot_claims_extended_schema($siteDb)) {
        return true;
    }
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return false;
    }

    static $attempted = false;
    if ($attempted) {
        return sahtout_adopt_a_bot_claims_extended_schema($siteDb);
    }
    $attempted = true;

    try {
        if (!sahtout_adopt_a_bot_claims_column_exists($siteDb, 'progress_message')) {
            $siteDb->query(
                'ALTER TABLE adopt_a_bot_claims
                    MODIFY status enum(\'pending\',\'processing\',\'completed\',\'cancelled\') NOT NULL DEFAULT \'pending\',
                    ADD COLUMN progress_message varchar(500) DEFAULT NULL AFTER bot_character_name,
                    ADD COLUMN progress_log text DEFAULT NULL AFTER progress_message'
            );
        } elseif (!sahtout_adopt_a_bot_status_supports_processing($siteDb)) {
            $siteDb->query(
                "ALTER TABLE adopt_a_bot_claims
                    MODIFY status enum('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending'"
            );
        }
    } catch (mysqli_sql_exception $e) {
        error_log(
            'adopt_a_bot: schema upgrade skipped — grant ALTER or run SQL/Hearthstone_UPDATE/2026110701_adopt-a-bot.sql as DBA: '
            . $e->getMessage()
        );
    }

    sahtout_adopt_a_bot_claims_extended_schema($siteDb, true);

    return sahtout_adopt_a_bot_claims_extended_schema($siteDb);
}

/**
 * @return string Comma-separated SELECT list for adopt_a_bot_claims rows.
 */
function sahtout_adopt_a_bot_claims_select_columns(mysqli $siteDb): string
{
    if (sahtout_adopt_a_bot_claims_extended_schema($siteDb)) {
        return 'claim_id, purchase_id, created_at, status, bot_character_guid, bot_character_name, progress_message, progress_log';
    }

    return 'claim_id, purchase_id, created_at, status, bot_character_guid, bot_character_name';
}

function sahtout_adopt_a_bot_claims_ensure_schema(mysqli $siteDb): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        try {
            $ok = (bool) $siteDb->query(
                'CREATE TABLE IF NOT EXISTS adopt_a_bot_claims (
            claim_id int unsigned NOT NULL AUTO_INCREMENT,
            account_id int unsigned NOT NULL,
            purchase_id int unsigned DEFAULT NULL,
            status enum(\'draft\',\'pending\',\'processing\',\'completed\',\'cancelled\') NOT NULL DEFAULT \'draft\',
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
        } catch (mysqli_sql_exception $e) {
            error_log('adopt_a_bot: CREATE TABLE adopt_a_bot_claims failed: ' . $e->getMessage());

            return sahtout_adopt_a_bot_claims_table_exists($siteDb);
        }
    }

    sahtout_adopt_a_bot_claims_upgrade_schema($siteDb);
    sahtout_adopt_a_bot_claims_upgrade_draft_schema($siteDb);

    return sahtout_adopt_a_bot_claims_table_exists($siteDb);
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

/** True unless business rules block purchase (no lifetime adoption cap; price and slots limit repeat buys). */
function sahtout_adopt_a_bot_account_may_purchase(mysqli $siteDb, int $accountId): bool
{
    unset($siteDb, $accountId);

    return true;
}

function sahtout_adopt_a_bot_get_pending_claim(mysqli $siteDb, int $accountId): ?array
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return null;
    }
    sahtout_adopt_a_bot_claims_upgrade_draft_schema($siteDb);
    $cols = sahtout_adopt_a_bot_claims_select_columns($siteDb);
    $statusSql = sahtout_adopt_a_bot_claims_supports_unpaid_draft($siteDb)
        ? "status IN ('draft', 'pending')"
        : "status = 'pending'";
    $stmt = $siteDb->prepare(
        "SELECT {$cols} FROM adopt_a_bot_claims WHERE account_id = ? AND {$statusSql} ORDER BY claim_id DESC LIMIT 1"
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
    $cols = sahtout_adopt_a_bot_claims_select_columns($siteDb);
    sahtout_adopt_a_bot_claims_upgrade_draft_schema($siteDb);
    if (sahtout_adopt_a_bot_claims_supports_unpaid_draft($siteDb)) {
        $statusSql = "status IN ('draft', 'pending', 'processing')";
    } elseif (sahtout_adopt_a_bot_claims_extended_schema($siteDb)) {
        $statusSql = "status IN ('pending', 'processing')";
    } else {
        $statusSql = "status = 'pending'";
    }
    $stmt = $siteDb->prepare(
        "SELECT {$cols} FROM adopt_a_bot_claims WHERE account_id = ? AND {$statusSql} ORDER BY claim_id DESC LIMIT 1"
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
        return $basePath . 'adopt-a-bot-character?guid=' . $guid . '&step=processing';
    }
    if ($status === 'processing') {
        return $basePath . 'adopt-a-bot';
    }
    if ($guid > 0 && ($status === 'pending' || $status === 'draft')) {
        return $basePath . 'adopt-a-bot-character?guid=' . $guid;
    }

    return $basePath . 'adopt-a-bot';
}

/** Open adopt session (no token charge) — one per account until completed or cancelled. */
function sahtout_adopt_a_bot_create_draft_claim(mysqli $siteDb, int $accountId): ?int
{
    if (!sahtout_adopt_a_bot_claims_ensure_schema($siteDb)) {
        return null;
    }
    if (!sahtout_adopt_a_bot_claims_supports_unpaid_draft($siteDb)) {
        return null;
    }
    if (sahtout_adopt_a_bot_get_open_claim($siteDb, $accountId) !== null) {
        return null;
    }
    $stmt = $siteDb->prepare(
        "INSERT INTO adopt_a_bot_claims (account_id, purchase_id, status) VALUES (?, NULL, 'draft')"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $accountId);
    if (!$stmt->execute()) {
        $stmt->close();

        return null;
    }
    $id = (int) $siteDb->insert_id;
    $stmt->close();

    return $id > 0 ? $id : null;
}

function sahtout_adopt_a_bot_claim_has_purchase(?array $claim): bool
{
    return $claim !== null && (int) ($claim['purchase_id'] ?? 0) > 0;
}

/** User may abandon before tokens are charged and transfer starts. */
function sahtout_adopt_a_bot_may_cancel_unpaid_adoption(?array $claim): bool
{
    if ($claim === null) {
        return false;
    }
    $status = strtolower(trim((string) ($claim['status'] ?? '')));
    if ($status === 'processing') {
        return false;
    }
    if ($status === 'draft') {
        return true;
    }
    if ($status === 'pending' && !sahtout_adopt_a_bot_claim_has_purchase($claim)) {
        return true;
    }

    return false;
}

/**
 * Debit tokens and attach purchase to a draft claim (final checkout).
 *
 * @return array{ok:bool, error?:string, purchase_id?:int}
 */
function sahtout_adopt_a_bot_charge_claim_purchase(
    mysqli $siteDb,
    int $accountId,
    int $claimId,
    int $shopItemId
): array {
    $stmt = $siteDb->prepare('SELECT token_cost, stock FROM shop_items WHERE item_id = ? LIMIT 1');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'database'];
    }
    $stmt->bind_param('i', $shopItemId);
    $stmt->execute();
    $res = $stmt->get_result();
    $item = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$item) {
        return ['ok' => false, 'error' => 'item_not_found'];
    }
    $cost = (int) ($item['token_cost'] ?? 0);
    if ($cost <= 0) {
        return ['ok' => false, 'error' => 'item_not_found'];
    }

    if (!$siteDb->begin_transaction()) {
        return ['ok' => false, 'error' => 'database'];
    }

    $stmt = $siteDb->prepare(
        'UPDATE user_currencies SET tokens = tokens - ?, last_updated = NOW()
         WHERE account_id = ? AND tokens >= ?'
    );
    if (!$stmt) {
        $siteDb->rollback();

        return ['ok' => false, 'error' => 'database'];
    }
    $stmt->bind_param('iii', $cost, $accountId, $cost);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        $siteDb->rollback();

        return ['ok' => false, 'error' => 'insufficient_funds'];
    }
    $stmt->close();

    if ($item['stock'] !== null) {
        $stmt = $siteDb->prepare(
            'UPDATE shop_items SET stock = stock - 1, last_updated = NOW()
             WHERE item_id = ? AND stock IS NOT NULL AND stock > 0'
        );
        if (!$stmt) {
            $siteDb->rollback();

            return ['ok' => false, 'error' => 'database'];
        }
        $stmt->bind_param('i', $shopItemId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            $siteDb->rollback();

            return ['ok' => false, 'error' => 'out_of_stock'];
        }
        $stmt->close();
    }

    $stmt = $siteDb->prepare('INSERT INTO purchases (account_id, item_id) VALUES (?, ?)');
    if (!$stmt) {
        $siteDb->rollback();

        return ['ok' => false, 'error' => 'database'];
    }
    $stmt->bind_param('ii', $accountId, $shopItemId);
    if (!$stmt->execute()) {
        $stmt->close();
        $siteDb->rollback();

        return ['ok' => false, 'error' => 'database'];
    }
    $purchaseId = (int) $siteDb->insert_id;
    $stmt->close();

    $stmt = $siteDb->prepare(
        "UPDATE adopt_a_bot_claims SET purchase_id = ?, status = 'pending'
         WHERE claim_id = ? AND account_id = ? AND status = 'draft' AND purchase_id IS NULL"
    );
    if (!$stmt) {
        $siteDb->rollback();

        return ['ok' => false, 'error' => 'database'];
    }
    $stmt->bind_param('iii', $purchaseId, $claimId, $accountId);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        $siteDb->rollback();

        return ['ok' => false, 'error' => 'invalid_claim'];
    }
    $stmt->close();

    if (!$siteDb->commit()) {
        return ['ok' => false, 'error' => 'database'];
    }

    return ['ok' => true, 'purchase_id' => $purchaseId];
}

/** Cancel unpaid adoption (draft or unpaid pending). No refund — tokens were not charged. */
function sahtout_adopt_a_bot_cancel_draft_claim(mysqli $siteDb, int $accountId): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return false;
    }
    $stmt = $siteDb->prepare(
        "UPDATE adopt_a_bot_claims SET status = 'cancelled'
         WHERE account_id = ? AND status = 'draft' LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $accountId);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if ($ok) {
        return true;
    }

    $stmt = $siteDb->prepare(
        "UPDATE adopt_a_bot_claims SET status = 'cancelled'
         WHERE account_id = ? AND status = 'pending' AND (purchase_id IS NULL OR purchase_id = 0) LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $accountId);
    $ok = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();

    return $ok;
}

/** Legacy: pause processing — disabled after paid final confirm. */
function sahtout_adopt_a_bot_revert_processing_claim(mysqli $siteDb, int $accountId): bool
{
    if (!sahtout_adopt_a_bot_claims_table_exists($siteDb)) {
        return false;
    }
    unset($siteDb, $accountId);

    return false;
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

/**
 * @return list<string>
 */
function sahtout_adopt_a_bot_session_progress_lines(int $claimId, int $accountId): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    if (!isset($_SESSION['adopt_a_bot_progress']) || !is_array($_SESSION['adopt_a_bot_progress'])) {
        return [];
    }
    $key = $claimId . ':' . $accountId;
    $blob = $_SESSION['adopt_a_bot_progress'][$key] ?? null;
    if (!is_array($blob)) {
        return [];
    }
    $lines = $blob['lines'] ?? [];
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        if (is_string($line) && trim($line) !== '') {
            $out[] = trim($line);
        }
    }

    return $out;
}

function sahtout_adopt_a_bot_session_progress_message(int $claimId, int $accountId): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (!isset($_SESSION['adopt_a_bot_progress']) || !is_array($_SESSION['adopt_a_bot_progress'])) {
        return '';
    }
    $key = $claimId . ':' . $accountId;
    $blob = $_SESSION['adopt_a_bot_progress'][$key] ?? null;
    if (!is_array($blob)) {
        return '';
    }

    return trim((string) ($blob['message'] ?? ''));
}

function sahtout_adopt_a_bot_last_progress_line(mysqli $siteDb, int $claimId, int $accountId): string
{
    if (!sahtout_adopt_a_bot_claims_extended_schema($siteDb)) {
        return '';
    }
    $stmt = $siteDb->prepare(
        'SELECT progress_log FROM adopt_a_bot_claims WHERE claim_id = ? AND account_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('ii', $claimId, $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $lines = sahtout_adopt_a_bot_progress_log_lines($row);
    if ($lines === []) {
        return '';
    }

    return (string) end($lines);
}

function sahtout_adopt_a_bot_set_progress(mysqli $siteDb, int $claimId, int $accountId, string $message, bool $appendLog = true): void
{
    sahtout_adopt_a_bot_claims_upgrade_schema($siteDb);
    if (!sahtout_adopt_a_bot_claims_extended_schema($siteDb)) {
        error_log('adopt_a_bot progress (claim ' . $claimId . '): ' . $message);
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['adopt_a_bot_progress']) || !is_array($_SESSION['adopt_a_bot_progress'])) {
                $_SESSION['adopt_a_bot_progress'] = [];
            }
            $key = $claimId . ':' . $accountId;
            $prev = $_SESSION['adopt_a_bot_progress'][$key] ?? ['lines' => [], 'message' => ''];
            $prevLines = is_array($prev['lines'] ?? null) ? $prev['lines'] : [];
            if ($appendLog && ($prevLines === [] || end($prevLines) !== $message)) {
                $prevLines[] = $message;
                if (count($prevLines) > 40) {
                    $prevLines = array_slice($prevLines, -40);
                }
            }
            $_SESSION['adopt_a_bot_progress'][$key] = [
                'message' => $message,
                'lines' => $prevLines,
                'at' => time(),
            ];
        }

        return;
    }
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
        if ($lines !== [] && end($lines) === $message) {
            return;
        }
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
    if (!sahtout_adopt_a_bot_claims_extended_schema($siteDb)) {
        return false;
    }
    $botName = mb_substr(trim($botName), 0, 12);
    $intro = 'Adoption queued. We will complete the transfer as soon as the character is offline.';
    $logJson = json_encode([$intro], JSON_UNESCAPED_UNICODE);
    if (!is_string($logJson)) {
        $logJson = '[]';
    }

    $stmt = $siteDb->prepare(
        "UPDATE adopt_a_bot_claims
         SET status = 'processing', bot_character_guid = ?, bot_character_name = ?, progress_message = ?, progress_log = ?
         WHERE claim_id = ? AND account_id = ? AND status IN ('pending', 'draft')"
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

    if (sahtout_adopt_a_bot_last_progress_line($siteDb, $claimId, $accountId) === ''
        && trim((string) ($open['progress_message'] ?? '')) === '') {
        sahtout_adopt_a_bot_set_progress(
            $siteDb,
            $claimId,
            $accountId,
            'Checking pool character and realm onlinelist…'
        );
    }

    $result = sahtout_adopt_a_bot_complete_claim(
        $siteDb,
        $charDb,
        $authDb,
        $accountId,
        $claimId,
        $botGuid,
        $charDbName,
        $authDbName,
        SAHTOUT_ADOPT_A_BOT_OFFLINE_POLL_WAIT_SECONDS,
        false
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
        $dbg = (string) ($result['debug'] ?? '');
        if ($dbg !== '') {
            sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, $dbg);
        }

        return [
            'ok' => false,
            'error' => 'bot_online',
            'done' => false,
            'debug' => $dbg,
            'on_onlinelist' => $result['on_onlinelist'] ?? null,
        ];
    }

    $failLine = 'Adoption could not be completed: ' . $err;
    $dbgExtra = trim((string) ($result['debug'] ?? ''));
    if ($dbgExtra !== '') {
        $failLine .= ' — ' . $dbgExtra;
    }
    sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, $failLine);

    return ['ok' => false, 'error' => $err, 'done' => false, 'debug' => $dbgExtra !== '' ? $dbgExtra : $failLine];
}

/**
 * Build JSON-ready progress state after one adoption tick (used by poll endpoint and processing page).
 *
 * @return array<string, mixed>
 */
function sahtout_adopt_a_bot_build_progress_payload(
    mysqli $siteDb,
    int $accountId,
    array $result,
    ?array $openClaim,
    string $accountUrl
): array {
    $claimId = (int) ($openClaim['claim_id'] ?? 0);
    $lines = sahtout_adopt_a_bot_progress_log_lines($openClaim);
    if ($claimId > 0) {
        $sessionLines = sahtout_adopt_a_bot_session_progress_lines($claimId, $accountId);
        foreach ($sessionLines as $line) {
            if (!in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }
    }
    $message = trim((string) ($openClaim['progress_message'] ?? ''));
    if ($message === '' && $claimId > 0) {
        $message = sahtout_adopt_a_bot_session_progress_message($claimId, $accountId);
    }
    $debug = (string) ($result['debug'] ?? '');
    if ($debug === '' && $message !== '') {
        $debug = $message;
    }

    return [
        'ok' => !empty($result['ok']),
        'done' => !empty($result['done']),
        'error' => $result['error'] ?? null,
        'message' => $message !== '' ? $message : $debug,
        'lines' => $lines,
        'debug' => $debug !== '' ? $debug : null,
        'on_onlinelist' => $result['on_onlinelist'] ?? null,
        'character_name' => $result['character_name'] ?? null,
        'account_url' => $accountUrl,
        'progress_schema' => sahtout_adopt_a_bot_claims_extended_schema($siteDb),
        'polled_at' => time(),
    ];
}

/**
 * @param list<int> $poolAccountIds
 */
function sahtout_adopt_a_bot_count_pool_characters(
    mysqli $charDb,
    array $poolAccountIds,
    string $search,
    ?int $maxBotLevel = null
): int {
    $inList = sahtout_adopt_a_bot_sql_account_in_list($poolAccountIds);
    if ($inList === '0') {
        return 0;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        if ($maxBotLevel !== null) {
            $sql = 'SELECT COUNT(*) AS c FROM characters WHERE account IN (' . $inList . ') AND name LIKE ? AND level <= ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('si', $like, $maxBotLevel);
        } else {
            $sql = 'SELECT COUNT(*) AS c FROM characters WHERE account IN (' . $inList . ') AND name LIKE ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return 0;
            }
            $stmt->bind_param('s', $like);
        }
    } elseif ($maxBotLevel !== null) {
        $sql = 'SELECT COUNT(*) AS c FROM characters WHERE account IN (' . $inList . ') AND level <= ?';
        $stmt = $charDb->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $maxBotLevel);
    } else {
        $sql = 'SELECT COUNT(*) AS c FROM characters WHERE account IN (' . $inList . ')';
        $stmt = $charDb->prepare($sql);
        if (!$stmt) {
            return 0;
        }
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return (int) ($row['c'] ?? 0);
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
    ?int $maxBotLevel = null,
    int $offset = 0
): array {
    $inList = sahtout_adopt_a_bot_sql_account_in_list($poolAccountIds);
    if ($inList === '0') {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . $search . '%';
        if ($maxBotLevel !== null) {
            $sql = 'SELECT guid, name, level, race, class, gender FROM characters WHERE account IN (' . $inList . ') AND name LIKE ? AND level <= ? ORDER BY name ASC LIMIT ? OFFSET ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('siii', $like, $maxBotLevel, $limit, $offset);
        } else {
            $sql = 'SELECT guid, name, level, race, class, gender FROM characters WHERE account IN (' . $inList . ') AND name LIKE ? ORDER BY name ASC LIMIT ? OFFSET ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('siii', $like, $limit, $offset);
        }
    } else {
        if ($maxBotLevel !== null) {
            $sql = 'SELECT guid, name, level, race, class, gender FROM characters WHERE account IN (' . $inList . ') AND level <= ? ORDER BY name ASC LIMIT ? OFFSET ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('iii', $maxBotLevel, $limit, $offset);
        } else {
            $sql = 'SELECT guid, name, level, race, class, gender FROM characters WHERE account IN (' . $inList . ') ORDER BY name ASC LIMIT ? OFFSET ?';
            $stmt = $charDb->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('ii', $limit, $offset);
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

/**
 * @return ?array{name: string, online: int}
 */
function sahtout_adopt_a_bot_character_online_row(mysqli $charDb, int $guid): ?array
{
    $stmt = $charDb->prepare('SELECT name, online FROM characters WHERE guid = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $guid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }

    return [
        'name' => trim((string) ($row['name'] ?? '')),
        'online' => (int) ($row['online'] ?? 0),
    ];
}

function sahtout_adopt_a_bot_auth_login_for_character(mysqli $charDb, mysqli $authDb, int $guid): string
{
    $stmt = $charDb->prepare('SELECT account FROM characters WHERE guid = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $guid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $acctId = (int) ($row['account'] ?? 0);
    if ($acctId <= 0) {
        return '';
    }
    $stmt = $authDb->prepare('SELECT username FROM account WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $acctId);
    $stmt->execute();
    $res = $stmt->get_result();
    $auth = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return trim((string) ($auth['username'] ?? ''));
}

function sahtout_adopt_a_bot_force_character_offline_flag(mysqli $charDb, int $guid): void
{
    $stmt = $charDb->prepare('UPDATE characters SET online = 0 WHERE guid = ? AND online = 1');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $guid);
    $stmt->execute();
    $stmt->close();
}

/** Clear a stuck characters.online=1 when SOAP shows the player is not in the world (common for playerbots). */
function sahtout_adopt_a_bot_reconcile_online_state(mysqli $charDb, int $guid, ?mysqli $authDb = null): bool
{
    $row = sahtout_adopt_a_bot_character_online_row($charDb, $guid);
    if ($row === null || (int) $row['online'] !== 1) {
        return false;
    }
    $name = $row['name'];
    if ($name === '' || !sahtout_soap_is_configured()) {
        return false;
    }
    $accountLogin = ($authDb instanceof mysqli) ? sahtout_adopt_a_bot_auth_login_for_character($charDb, $authDb, $guid) : '';
    $inWorld = sahtout_soap_character_in_world($name, $accountLogin !== '' ? $accountLogin : null);
    if ($inWorld === false) {
        sahtout_adopt_a_bot_force_character_offline_flag($charDb, $guid);

        return true;
    }

    return false;
}

/** True when transfer must wait for disconnect (DB and/or live world). */
function sahtout_adopt_a_bot_character_is_online(mysqli $charDb, int $guid, ?mysqli $authDb = null): bool
{
    sahtout_adopt_a_bot_reconcile_online_state($charDb, $guid, $authDb);

    $row = sahtout_adopt_a_bot_character_online_row($charDb, $guid);
    if ($row === null) {
        return true;
    }
    if ((int) $row['online'] !== 1) {
        return false;
    }
    $name = $row['name'];
    if ($name !== '' && sahtout_soap_is_configured()) {
        $accountLogin = ($authDb instanceof mysqli) ? sahtout_adopt_a_bot_auth_login_for_character($charDb, $authDb, $guid) : '';
        $inWorld = sahtout_soap_character_in_world($name, $accountLogin !== '' ? $accountLogin : null);
        if ($inWorld === false) {
            sahtout_adopt_a_bot_force_character_offline_flag($charDb, $guid);

            return false;
        }
        if ($inWorld === true) {
            return true;
        }
        sahtout_adopt_a_bot_force_character_offline_flag($charDb, $guid);

        return false;
    }

    return (int) ($row['online'] ?? 0) === 1;
}

/** Kick + playerbots hold so shop services can apply (adopted rndbots often stay on the buyer's onlinelist). */
function sahtout_shop_disconnect_character_for_service(
    mysqli $charDb,
    ?mysqli $authDb,
    int $guid,
    string $characterName
): void {
    $characterName = trim($characterName);
    if ($guid <= 0 || $characterName === '' || !sahtout_soap_is_configured()) {
        return;
    }
    sahtout_adopt_a_bot_playerbots_hold_offline($charDb, $guid);
    $literal = sahtout_soap_character_name_for_command($characterName);
    sahtout_soap_execute_command('.kick ' . $literal . ' ShopService');
    sahtout_adopt_a_bot_kick_character_if_online($charDb, '', $guid, $authDb);
    sahtout_adopt_a_bot_force_character_offline_flag($charDb, $guid);
}

/**
 * Shop purchases: block only when the buyer's account has this character in the world.
 * Clears stale characters.online flags and kicks orphan pool-bot sessions on the onlinelist.
 */
function sahtout_shop_character_online_blocks_purchase(
    mysqli $charDb,
    ?mysqli $authDb,
    int $guid,
    int $accountId,
    string $characterName
): bool {
    $characterName = trim($characterName);
    if ($guid <= 0 || $accountId <= 0) {
        return true;
    }

    $buyerLogin = '';
    if ($authDb instanceof mysqli) {
        $stmt = $authDb->prepare('SELECT username FROM account WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $accountId);
            $stmt->execute();
            $res = $stmt->get_result();
            $authRow = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            $buyerLogin = trim((string) ($authRow['username'] ?? ''));
        }
    }

    if (!sahtout_soap_is_configured()) {
        $stmt = $charDb->prepare('UPDATE characters SET online = 0 WHERE guid = ? AND account = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ii', $guid, $accountId);
            $stmt->execute();
            $stmt->close();
        }

        return false;
    }

    $listText = sahtout_soap_account_onlinelist_text();
    if ($listText === null) {
        sahtout_adopt_a_bot_kick_character_if_online($charDb, '', $guid, $authDb);
        sahtout_adopt_a_bot_force_character_offline_flag($charDb, $guid);

        return false;
    }

    if (
        $buyerLogin !== ''
        && $characterName !== ''
        && sahtout_soap_onlinelist_contains_character_for_account($listText, $buyerLogin, $characterName)
    ) {
        sahtout_shop_disconnect_character_for_service($charDb, $authDb, $guid, $characterName);
        $listText = sahtout_soap_account_onlinelist_text();
        if (
            $listText !== null
            && sahtout_soap_onlinelist_contains_character_for_account($listText, $buyerLogin, $characterName)
        ) {
            return true;
        }
        $stmt = $charDb->prepare('UPDATE characters SET online = 0 WHERE guid = ? AND account = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ii', $guid, $accountId);
            $stmt->execute();
            $stmt->close();
        }

        return false;
    }

    if ($characterName !== '' && sahtout_soap_onlinelist_contains_character($listText, $characterName)) {
        sahtout_adopt_a_bot_playerbots_hold_offline($charDb, $guid);
        $literal = sahtout_soap_character_name_for_command($characterName);
        sahtout_soap_execute_command('.kick ' . $literal . ' ShopService');
        sahtout_adopt_a_bot_force_character_offline_flag($charDb, $guid);

        return false;
    }

    $stmt = $charDb->prepare('UPDATE characters SET online = 0 WHERE guid = ? AND account = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('ii', $guid, $accountId);
        $stmt->execute();
        $stmt->close();
    }

    return false;
}

function sahtout_adopt_a_bot_disconnect_retry_due(int $claimId, int $cooldownSeconds = SAHTOUT_ADOPT_A_BOT_DISCONNECT_RETRY_SECONDS): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }
    $key = (string) $claimId;
    $last = (int) ($_SESSION['adopt_a_bot_disconnect_retry'][$key] ?? 0);
    if ($last > 0 && (time() - $last) < max(3, $cooldownSeconds)) {
        return false;
    }

    return true;
}

function sahtout_adopt_a_bot_mark_disconnect_attempt(int $claimId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['adopt_a_bot_disconnect_retry'][(string) $claimId] = time();
}

/**
 * Remove this character from the random-bot event queue permanently (adopted bots are player-owned).
 * Requires DELETE on {playerbots}.playerbots_random_bots for the site DB user.
 */
function sahtout_adopt_a_bot_remove_from_playerbots_random_pool(mysqli $charDb, int $botGuid): bool
{
    if ($botGuid <= 0 || !sahtout_playerbots_db_configured()) {
        return false;
    }
    if (!armory_playerbots_table_exists($charDb, null, 'playerbots_random_bots')) {
        return false;
    }
    $table = sahtout_sql_quoted_identifier(sahtout_playerbots_db_name()) . '.'
        . sahtout_sql_quoted_identifier('playerbots_random_bots');
    $botGuid = (int) $botGuid;

    try {
        return (bool) $charDb->query("DELETE FROM {$table} WHERE bot = {$botGuid}");
    } catch (mysqli_sql_exception $e) {
        error_log('adopt_a_bot playerbots pool remove: ' . $e->getMessage());

        return false;
    }
}

/**
 * Tell RandomPlayerbotMgr not to re-login this guid for a while (needs UPDATE on playerbots DB).
 */
function sahtout_adopt_a_bot_playerbots_hold_offline(mysqli $charDb, int $botGuid, int $holdSeconds = SAHTOUT_ADOPT_A_BOT_PLAYERBOT_OFFLINE_HOLD_SECONDS): bool
{
    if ($botGuid <= 0 || !sahtout_playerbots_db_configured()) {
        return false;
    }
    if (!armory_playerbots_table_exists($charDb, null, 'playerbots_random_bots')) {
        return false;
    }

    $table = sahtout_sql_quoted_identifier(sahtout_playerbots_db_name()) . '.'
        . sahtout_sql_quoted_identifier('playerbots_random_bots');
    $botGuid = (int) $botGuid;
    $now = time();
    $holdSeconds = max(60, $holdSeconds);

    try {
        if (!$charDb->query(
            "DELETE FROM {$table} WHERE owner = 0 AND bot = {$botGuid} AND event IN ('logout', 'add')"
        )) {
            return false;
        }
        $stmt = $charDb->prepare(
            "INSERT INTO {$table} (owner, bot, `time`, validIn, event, `value`, `data`)
             VALUES (0, ?, ?, ?, 'logout', 1, 'adopt_a_bot')"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iii', $botGuid, $now, $holdSeconds);
        if (!$stmt->execute()) {
            $stmt->close();

            return false;
        }
        $stmt->close();

        $stmt = $charDb->prepare(
            "INSERT INTO {$table} (owner, bot, `time`, validIn, event, `value`, `data`)
             VALUES (0, ?, ?, ?, 'add', 1, 'adopt_a_bot')"
        );
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param('iii', $botGuid, $now, $holdSeconds);
        $stmt->execute();
        $stmt->close();

        return true;
    } catch (mysqli_sql_exception $e) {
        error_log('adopt_a_bot playerbots hold: ' . $e->getMessage());

        return false;
    }
}

function sahtout_adopt_a_bot_force_disconnect_for_adoption(
    mysqli $siteDb,
    mysqli $charDb,
    ?mysqli $authDb,
    int $claimId,
    int $accountId,
    int $botGuid,
    string $botName,
    bool $logProgress = true
): void {
    if (!sahtout_soap_is_configured()) {
        return;
    }
    $botName = trim($botName);
    if ($botName === '') {
        return;
    }

    if (sahtout_adopt_a_bot_playerbots_hold_offline($charDb, $botGuid) && $logProgress) {
        sahtout_adopt_a_bot_set_progress(
            $siteDb,
            $claimId,
            $accountId,
            'Requested playerbots logout hold for ' . $botName . ' (prevents random-bot relog).'
        );
    }

    if ($authDb instanceof mysqli) {
        $login = sahtout_adopt_a_bot_auth_login_for_character($charDb, $authDb, $botGuid);
        $acctLiteral = sahtout_soap_account_name_literal($login);
        if ($acctLiteral !== null) {
            $accountKick = sahtout_soap_execute_command('.account kick ' . $acctLiteral);
            if ($logProgress) {
                sahtout_adopt_a_bot_progress_note_soap($siteDb, $claimId, $accountId, '.account kick', $accountKick);
            }
        }
    }

    $literal = sahtout_soap_character_name_for_command($botName);
    $kick = sahtout_soap_execute_command('.kick ' . $literal . ' Adoption');
    if ($logProgress) {
        sahtout_adopt_a_bot_progress_note_soap($siteDb, $claimId, $accountId, '.kick', $kick);
    }

    sahtout_adopt_a_bot_kick_character_if_online($charDb, '', $botGuid, $authDb);
    sahtout_adopt_a_bot_force_character_offline_flag($charDb, $botGuid);
}

/**
 * Pool-bot offline: trust .account onlinelist for this character name only (not sibling chars on same account).
 *
 * @return array{ready:bool, on_onlinelist:bool|null, debug:string}
 */
function sahtout_adopt_a_bot_ensure_pool_bot_offline_for_transfer(
    mysqli $siteDb,
    mysqli $charDb,
    ?mysqli $authDb,
    int $claimId,
    int $accountId,
    int $botGuid,
    string $botName,
    string $charDbName
): array {
    $botName = trim($botName);
    $debug = '';
    $onList = null;

    $row = sahtout_adopt_a_bot_character_online_row($charDb, $botGuid);
    $dbOnline = $row !== null && (int) ($row['online'] ?? 0) === 1;

    if (!sahtout_soap_is_configured()) {
        $ready = !$dbOnline;
        $debug = $ready ? 'SOAP not configured; character offline in DB.' : 'SOAP not configured; characters.online=1.';

        return ['ready' => $ready, 'on_onlinelist' => null, 'debug' => $debug];
    }

    $listText = sahtout_soap_account_onlinelist_text();
    if ($listText === null) {
        sahtout_adopt_a_bot_kick_character_if_online($charDb, $charDbName, $botGuid, $authDb);
        sahtout_adopt_a_bot_force_character_offline_flag($charDb, $botGuid);
        $debug = 'Onlinelist unavailable; issued kick and cleared DB online flag.';
        sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, $debug);

        return ['ready' => true, 'on_onlinelist' => null, 'debug' => $debug];
    }

    $onList = sahtout_soap_onlinelist_contains_character($listText, $botName);
    $proceedingMsg = $botName . ' is not on .account onlinelist (other pool bots may be online). Proceeding.';
    $waitAfterKickMsg = $botName . ' is still on .account onlinelist after disconnect (random playerbot may be relogging; retrying every '
        . SAHTOUT_ADOPT_A_BOT_DISCONNECT_RETRY_SECONDS . 's).';
    $lastLine = sahtout_adopt_a_bot_last_progress_line($siteDb, $claimId, $accountId);

    if (!$onList) {
        sahtout_adopt_a_bot_force_character_offline_flag($charDb, $botGuid);
        $debug = $proceedingMsg;
        if ($lastLine !== $proceedingMsg) {
            sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, $debug);
        }

        return ['ready' => true, 'on_onlinelist' => false, 'debug' => $debug];
    }

    $alreadyWaiting = ($lastLine === $waitAfterKickMsg);
    $retryDue = sahtout_adopt_a_bot_disconnect_retry_due($claimId);

    if ($alreadyWaiting && !$retryDue) {
        $listCheck = sahtout_soap_account_onlinelist_text();
        if ($listCheck !== null && !sahtout_soap_onlinelist_contains_character($listCheck, $botName)) {
            sahtout_adopt_a_bot_force_character_offline_flag($charDb, $botGuid);
            $debug = $botName . ' left the onlinelist; continuing adoption.';
            sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, $debug);

            return ['ready' => true, 'on_onlinelist' => false, 'debug' => $debug];
        }

        return ['ready' => false, 'on_onlinelist' => true, 'debug' => $waitAfterKickMsg];
    }

    if ($alreadyWaiting && $retryDue) {
        sahtout_adopt_a_bot_set_progress(
            $siteDb,
            $claimId,
            $accountId,
            'Retrying disconnect for ' . $botName . ' (kick, account kick, playerbots hold)…'
        );
    }

    sahtout_adopt_a_bot_mark_disconnect_attempt($claimId);
    sahtout_adopt_a_bot_force_disconnect_for_adoption(
        $siteDb,
        $charDb,
        $authDb,
        $claimId,
        $accountId,
        $botGuid,
        $botName,
        !$alreadyWaiting
    );
    usleep(700000);
    $listAfter = sahtout_soap_account_onlinelist_text();
    $stillOn = $listAfter !== null && sahtout_soap_onlinelist_contains_character($listAfter, $botName);
    if (!$stillOn) {
        sahtout_adopt_a_bot_force_character_offline_flag($charDb, $botGuid);
        $debug = $botName . ' was on onlinelist; disconnect succeeded and they are offline now.';
        sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, $debug);

        return ['ready' => true, 'on_onlinelist' => true, 'debug' => $debug];
    }

    $debug = $waitAfterKickMsg;
    if ($lastLine !== $waitAfterKickMsg) {
        sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, $debug);
    }

    return ['ready' => false, 'on_onlinelist' => true, 'debug' => $debug];
}

function sahtout_adopt_a_bot_progress_note_soap(
    mysqli $siteDb,
    int $claimId,
    int $accountId,
    string $command,
    array $soapResult
): void {
    $msg = sahtout_soap_extract_command_message(
        (string) ($soapResult['output'] ?? ''),
        false
    );
    if ($msg === '' && !empty($soapResult['error'])) {
        $msg = (string) $soapResult['error'];
    }
    $msg = preg_replace('/\s+/', ' ', trim($msg)) ?? '';
    if (strlen($msg) > 220) {
        $msg = substr($msg, 0, 217) . '…';
    }
    $line = $command . ': ' . ($msg !== '' ? $msg : ($soapResult['ok'] ? 'ok' : 'no response'));
    sahtout_adopt_a_bot_set_progress($siteDb, $claimId, $accountId, $line);
}

/** Kick via SOAP when configured, then poll until offline or timeout. */
function sahtout_adopt_a_bot_prepare_character_offline(
    mysqli $charDb,
    string $charDbName,
    int $guid,
    int $maxWaitSeconds = SAHTOUT_ADOPT_A_BOT_OFFLINE_WAIT_SECONDS,
    ?mysqli $authDb = null
): bool {
    if (!sahtout_adopt_a_bot_character_is_online($charDb, $guid, $authDb)) {
        return true;
    }
    sahtout_adopt_a_bot_kick_character_if_online($charDb, $charDbName, $guid, $authDb);
    sahtout_adopt_a_bot_reconcile_online_state($charDb, $guid, $authDb);
    if ($maxWaitSeconds <= 0) {
        return !sahtout_adopt_a_bot_character_is_online($charDb, $guid, $authDb);
    }
    $deadline = time() + max(1, $maxWaitSeconds);
    $lastKickAt = time();
    while (time() < $deadline) {
        usleep(800000);
        if (!sahtout_adopt_a_bot_character_is_online($charDb, $guid, $authDb)) {
            return true;
        }
        if (time() - $lastKickAt >= 3) {
            sahtout_adopt_a_bot_kick_character_if_online($charDb, $charDbName, $guid, $authDb);
            $lastKickAt = time();
        }
    }

    return !sahtout_adopt_a_bot_character_is_online($charDb, $guid, $authDb);
}

function sahtout_adopt_a_bot_kick_character_if_online(mysqli $charDb, string $charDbName, int $guid, ?mysqli $authDb = null): void
{
    if (!sahtout_soap_is_configured()) {
        return;
    }
    $row = sahtout_adopt_a_bot_character_online_row($charDb, $guid);
    if ($row === null || (int) $row['online'] !== 1) {
        sahtout_adopt_a_bot_reconcile_online_state($charDb, $guid, $authDb);

        return;
    }
    $name = $row['name'];
    if ($name === '') {
        return;
    }
    $accountLogin = ($authDb instanceof mysqli) ? sahtout_adopt_a_bot_auth_login_for_character($charDb, $authDb, $guid) : '';
    $literal = sahtout_soap_character_name_for_command($name);
    $kick = sahtout_soap_execute_command('.kick ' . $literal . ' Adoption');
    if (!$kick['ok']) {
        $hint = strtolower(sahtout_soap_extract_command_message($kick['output'] . ' ' . (string) ($kick['error'] ?? ''), false));
        if (sahtout_soap_message_indicates_player_not_in_world($hint)) {
            sahtout_adopt_a_bot_force_character_offline_flag($charDb, $guid);
        }

        return;
    }
    usleep(400000);
    $inWorld = sahtout_soap_character_in_world($name, $accountLogin !== '' ? $accountLogin : null);
    if ($inWorld !== true) {
        sahtout_adopt_a_bot_force_character_offline_flag($charDb, $guid);
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

/** Apply missing customize flag for every completed adoption on this account (retroactive repair). */
/** Grant AT_LOGIN customize on the adopted character (OR into existing at_login flags). */
function sahtout_adopt_a_bot_grant_customize_at_login(mysqli $charDb, int $characterGuid, int $accountId): bool
{
    if ($characterGuid <= 0 || $accountId <= 0) {
        return false;
    }
    $flag = SAHTOUT_AT_LOGIN_ADOPT_APPEARANCE;
    $stmt = $charDb->prepare(
        'UPDATE characters SET at_login = at_login | ? WHERE guid = ? AND account = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $flag, $characterGuid, $accountId);
    if (!$stmt->execute()) {
        $stmt->close();

        return false;
    }
    $stmt->close();

    $check = $charDb->prepare(
        'SELECT at_login FROM characters WHERE guid = ? AND account = ? LIMIT 1'
    );
    if (!$check) {
        return false;
    }
    $check->bind_param('ii', $characterGuid, $accountId);
    $check->execute();
    $res = $check->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $check->close();
    $atLogin = (int) ($row['at_login'] ?? 0);

    return ($atLogin & SAHTOUT_AT_LOGIN_CUSTOMIZE) !== 0;
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
    string $authDbName,
    int $offlineWaitSeconds = SAHTOUT_ADOPT_A_BOT_OFFLINE_WAIT_SECONDS,
    bool $logSoapToProgress = false
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

    $charNameForOffline = trim((string) ($eligible['name'] ?? ''));
    $offline = sahtout_adopt_a_bot_ensure_pool_bot_offline_for_transfer(
        $siteDb,
        $charDb,
        $authDb,
        $claimId,
        $accountId,
        $botGuid,
        $charNameForOffline,
        $charDbName
    );
    if (!$offline['ready']) {
        return [
            'ok' => false,
            'error' => 'bot_online',
            'debug' => (string) ($offline['debug'] ?? ''),
            'on_onlinelist' => $offline['on_onlinelist'] ?? null,
        ];
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
    $charName = (string) $bot['name'];
    $listText = sahtout_soap_is_configured() ? sahtout_soap_account_onlinelist_text() : null;
    if ($listText !== null && sahtout_soap_onlinelist_contains_character($listText, $charName)) {
        $charDb->rollback();

        return [
            'ok' => false,
            'error' => 'bot_online',
            'debug' => $charName . ' is on .account onlinelist; cannot transfer while in world.',
            'on_onlinelist' => true,
        ];
    }
    sahtout_adopt_a_bot_force_character_offline_flag($charDb, $botGuid);

    $poolAccountId = (int) ($bot['account'] ?? 0);
    if ($poolAccountId <= 0) {
        $charDb->rollback();

        return ['ok' => false, 'error' => 'bot_not_found'];
    }

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

    $clearOnline = $charDb->prepare('UPDATE characters SET online = 0 WHERE guid = ? AND account = ? LIMIT 1');
    if ($clearOnline) {
        $clearOnline->bind_param('ii', $botGuid, $accountId);
        $clearOnline->execute();
        $clearOnline->close();
    }
    sahtout_adopt_a_bot_reconcile_online_state($charDb, $botGuid, $authDb);

    if (!sahtout_adopt_a_bot_remove_from_playerbots_random_pool($charDb, $botGuid)) {
        error_log('adopt_a_bot: could not remove guid ' . $botGuid . ' from playerbots_random_bots (check DB grants)');
    }
    sahtout_adopt_a_bot_playerbots_hold_offline($charDb, $botGuid);

    if (!sahtout_adopt_a_bot_grant_customize_at_login($charDb, $botGuid, $accountId)) {
        error_log('adopt_a_bot: failed to grant at_login customize for adopted guid ' . $botGuid);
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
        'Adopted character GUID ' . $botGuid . ' from pool account; at_login customize enabled.',
        $charName
    );

    return ['ok' => true, 'character_name' => $charName];
}
