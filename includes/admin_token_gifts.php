<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'token_gift_settings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'token_gift_world.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'soap_client.php';

function sahtout_admin_token_gift_count(mysqli $siteDb): int
{
    if (!sahtout_token_gifts_table_exists($siteDb)) {
        return 0;
    }
    $res = $siteDb->query('SELECT COUNT(*) AS c FROM token_gifts');
    if (!$res) {
        return 0;
    }
    $count = (int) ($res->fetch_assoc()['c'] ?? 0);
    $res->free();

    return $count;
}

function sahtout_token_gifts_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'token_gifts'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_token_gift_grants_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'token_gift_grants'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_token_gift_reward_sync_state_table_exists(mysqli $siteDb, bool $recheck = false): bool
{
    static $exists = null;
    if ($recheck) {
        $exists = null;
    }
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'token_gift_reward_sync_state'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_token_gift_grants_has_character_guid_column(mysqli $siteDb): bool
{
    $res = $siteDb->query("SHOW COLUMNS FROM token_gift_grants LIKE 'character_guid'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

/** Apply token_gift_grants_repeatable.sql changes when missing (safe to call repeatedly). */
function sahtout_token_gift_grants_ensure_repeatable_schema(mysqli $siteDb): bool
{
    if (!sahtout_token_gift_grants_table_exists($siteDb)) {
        return false;
    }

    if (!sahtout_token_gift_grants_has_character_guid_column($siteDb)) {
        if (!$siteDb->query(
            'ALTER TABLE token_gift_grants ADD COLUMN character_guid int unsigned NOT NULL DEFAULT 0 AFTER token_gift_id'
        )) {
            error_log('token_gift_grants: add character_guid failed: ' . $siteDb->error);

            return false;
        }
    }

    $idx = $siteDb->query("SHOW INDEX FROM token_gift_grants WHERE Key_name = 'uq_account_gift'");
    if ($idx && $idx->num_rows > 0) {
        $siteDb->query('ALTER TABLE token_gift_grants DROP INDEX uq_account_gift');
    }
    $idx?->free();

    $idx2 = $siteDb->query("SHOW INDEX FROM token_gift_grants WHERE Key_name = 'idx_account_gift'");
    if (!$idx2 || $idx2->num_rows === 0) {
        $siteDb->query('ALTER TABLE token_gift_grants ADD KEY idx_account_gift (account_id, token_gift_id)');
    }
    $idx2?->free();

    if (!sahtout_token_gift_reward_sync_state_table_exists($siteDb)) {
        $ok = $siteDb->query(
            'CREATE TABLE IF NOT EXISTS token_gift_reward_sync_state (
                account_id int unsigned NOT NULL,
                token_gift_id int unsigned NOT NULL,
                character_guid int unsigned NOT NULL,
                last_seen_rewarded_active tinyint unsigned NOT NULL DEFAULT 0,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (account_id, token_gift_id, character_guid),
                KEY idx_gift (token_gift_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        if (!$ok) {
            error_log('token_gift_reward_sync_state create failed: ' . $siteDb->error);

            return false;
        }
        sahtout_token_gift_reward_sync_state_table_exists($siteDb, true);
    }

    return sahtout_token_gift_grants_has_character_guid_column($siteDb)
        && sahtout_token_gift_reward_sync_state_table_exists($siteDb, true);
}

function sahtout_admin_can_publish_token_gifts(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

/** @return list<array<string, mixed>> */
function sahtout_admin_fetch_token_gifts(mysqli $siteDb): array
{
    if (!sahtout_token_gifts_table_exists($siteDb)) {
        return [];
    }
    $res = $siteDb->query(
        'SELECT id, name, letter_text, tokens, item_entry, quest_id, page_text_id, page_text_page_count,
                is_enabled, deploy_world_chests, loot_chance, extra_creature_entries, extra_go_loot_ids,
                published_at, created_at, updated_at
         FROM token_gifts ORDER BY id DESC'
    );
    if (!$res) {
        return [];
    }
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();

    return $rows;
}

/** @return array{ok:bool, id?:int, message?:string} */
function sahtout_admin_add_token_gift(
    mysqli $siteDb,
    string $name,
    string $letterText,
    int $tokens,
    bool $deployChests,
    float $lootChance,
    string $extraCreatures,
    string $extraGoLootIds
): array {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        return ['ok' => false, 'message' => 'invalid_name'];
    }
    if ($tokens < 1 || $tokens > 9999999) {
        return ['ok' => false, 'message' => 'invalid_tokens'];
    }
    if (sahtout_admin_token_gift_count($siteDb) >= sahtout_token_gift_max_promos()) {
        return ['ok' => false, 'message' => 'max_gifts'];
    }
    $lootChance = max(0.01, min(100.0, $lootChance));
    $letterText = trim($letterText);
    if (mb_strlen($letterText) > 1000) {
        $letterText = mb_substr($letterText, 0, 1000);
    }

    $deployInt = $deployChests ? 1 : 0;
    $stmt = $siteDb->prepare(
        'INSERT INTO token_gifts (name, letter_text, tokens, deploy_world_chests, loot_chance, extra_creature_entries, extra_go_loot_ids)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'insert_failed'];
    }
    $stmt->bind_param(
        'ssiidss',
        $name,
        $letterText,
        $tokens,
        $deployInt,
        $lootChance,
        $extraCreatures,
        $extraGoLootIds
    );
    if (!$stmt->execute()) {
        $stmt->close();

        return ['ok' => false, 'message' => 'insert_failed'];
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return ['ok' => true, 'id' => $id];
}

/** @return array{ok:bool, message?:string} */
function sahtout_admin_update_token_gift(
    mysqli $siteDb,
    ?mysqli $worldDb,
    int $giftId,
    string $name,
    string $letterText,
    int $tokens,
    bool $isEnabled,
    bool $deployChests,
    float $lootChance,
    string $extraCreatures,
    string $extraGoLootIds
): array {
    if ($giftId <= 0) {
        return ['ok' => false, 'message' => 'invalid_id'];
    }
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'message' => 'invalid_name'];
    }
    if ($tokens < 0 || $tokens > 9999999) {
        return ['ok' => false, 'message' => 'invalid_tokens'];
    }
    $lootChance = max(0.01, min(100.0, $lootChance));
    $letterText = trim($letterText);
    if (mb_strlen($letterText) > 1000) {
        $letterText = mb_substr($letterText, 0, 1000);
    }

    $load = $siteDb->prepare(
        'SELECT item_entry, quest_id, page_text_id, page_text_page_count, published_at, tokens FROM token_gifts WHERE id = ? LIMIT 1'
    );
    if (!$load) {
        return ['ok' => false, 'message' => 'load_failed'];
    }
    $load->bind_param('i', $giftId);
    $load->execute();
    $existing = $load->get_result()->fetch_assoc();
    $load->close();
    if (!$existing) {
        return ['ok' => false, 'message' => 'not_found'];
    }

    $enabledInt = $isEnabled ? 1 : 0;
    $deployInt = $deployChests ? 1 : 0;
    $stmt = $siteDb->prepare(
        'UPDATE token_gifts SET name = ?, letter_text = ?, tokens = ?, is_enabled = ?, deploy_world_chests = ?,
         loot_chance = ?, extra_creature_entries = ?, extra_go_loot_ids = ? WHERE id = ?'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'update_failed'];
    }
    $stmt->bind_param(
        'ssiidsssi',
        $name,
        $letterText,
        $tokens,
        $enabledInt,
        $deployInt,
        $lootChance,
        $extraCreatures,
        $extraGoLootIds,
        $giftId
    );
    if (!$stmt->execute()) {
        $stmt->close();

        return ['ok' => false, 'message' => 'update_failed'];
    }
    $stmt->close();

    if (!empty($existing['published_at']) && $worldDb instanceof mysqli && !$worldDb->connect_error) {
        $itemEntry = (int) ($existing['item_entry'] ?? 0);
        $questId = (int) ($existing['quest_id'] ?? 0);
        if ($itemEntry > 0 && $questId > 0) {
            $worldUpdate = sahtout_token_gift_update_world_content(
                $siteDb,
                $worldDb,
                $giftId,
                $itemEntry,
                $questId,
                $name,
                $letterText,
                $tokens
            );
            if (!$worldUpdate['ok']) {
                return ['ok' => false, 'message' => $worldUpdate['message'] ?? 'world_update_failed'];
            }

            return [
                'ok' => true,
                'reload' => $worldUpdate['reload'] ?? sahtout_soap_reload_token_gift_content(),
            ];
        }
    }

    return ['ok' => true];
}

/** @return array{ok:bool, message?:string} */
function sahtout_admin_delete_token_gift(mysqli $siteDb, ?mysqli $worldDb, int $giftId): array
{
    if ($giftId <= 0) {
        return ['ok' => false, 'message' => 'invalid_id'];
    }

    $worldId = 0;
    $pageFirst = 0;
    $pageCount = 0;
    $load = $siteDb->prepare(
        'SELECT item_entry, quest_id, page_text_id, page_text_page_count FROM token_gifts WHERE id = ? LIMIT 1'
    );
    if ($load) {
        $load->bind_param('i', $giftId);
        $load->execute();
        if ($giftRow = $load->get_result()->fetch_assoc()) {
            $itemEntry = (int) ($giftRow['item_entry'] ?? 0);
            $questId = (int) ($giftRow['quest_id'] ?? 0);
            if ($itemEntry > 0 && $itemEntry === $questId) {
                $worldId = $itemEntry;
            } elseif ($itemEntry > 0) {
                $worldId = $itemEntry;
            } elseif ($questId > 0) {
                $worldId = $questId;
            }
            $pageFirst = (int) ($giftRow['page_text_id'] ?? 0);
            $pageCount = (int) ($giftRow['page_text_page_count'] ?? 0);
        }
        $load->close();
    }

    $reload = null;
    if ($worldDb instanceof mysqli && !$worldDb->connect_error) {
        sahtout_token_gift_undeploy_loot($siteDb, $worldDb, $giftId);
        if ($pageFirst > 0) {
            sahtout_token_gift_delete_page_text_chain($worldDb, $pageFirst, $pageCount);
        }
        if ($worldId > 0) {
            sahtout_token_gift_delete_world_promo($worldDb, $worldId);
        }
        $reload = sahtout_soap_merge_reload_results(
            sahtout_soap_reload_token_gift_content(),
            sahtout_soap_reload_loot_tables()
        );
    }

    $siteDb->query('DELETE FROM token_gift_grants WHERE token_gift_id = ' . (int) $giftId);
    $siteDb->query('DELETE FROM token_gift_reward_sync_state WHERE token_gift_id = ' . (int) $giftId);

    $stmt = $siteDb->prepare('DELETE FROM token_gifts WHERE id = ?');
    if (!$stmt) {
        return ['ok' => false, 'message' => 'delete_failed'];
    }
    $stmt->bind_param('i', $giftId);
    $ok = $stmt->execute();
    $stmt->close();

    return [
        'ok' => $ok,
        'message' => $ok ? 'deleted' : 'delete_failed',
        'reload' => is_array($reload) ? $reload : null,
    ];
}
