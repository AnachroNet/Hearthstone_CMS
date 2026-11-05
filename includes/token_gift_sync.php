<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_token_gifts.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'website_activity.php';

/**
 * @return array{
 *   ok: bool,
 *   granted: list<array{token_gift_id:int, quest_id:int, tokens:int, name:string, character_guid?:int}>,
 *   tokens_added: int,
 *   message: string
 * }
 */
function sahtout_sync_token_gifts(mysqli $siteDb, mysqli $charDb, int $accountId): array
{
    $empty = static fn (string $message): array => [
        'ok' => true,
        'granted' => [],
        'tokens_added' => 0,
        'message' => $message,
    ];

    if ($accountId <= 0) {
        return $empty('invalid_account');
    }
    if (!sahtout_token_gifts_table_exists($siteDb) || !sahtout_token_gift_grants_table_exists($siteDb)) {
        return $empty('tables_missing');
    }
    if ($charDb->connect_error) {
        return $empty('char_db_unavailable');
    }

    sahtout_token_gift_grants_ensure_repeatable_schema($siteDb);
    $syncStateTable = $siteDb->query("SHOW TABLES LIKE 'token_gift_reward_sync_state'");
    $useSyncState = $syncStateTable && $syncStateTable->num_rows > 0;
    $syncStateTable?->free();

    $res = $siteDb->query(
        'SELECT id, name, quest_id, item_entry, tokens FROM token_gifts
         WHERE is_enabled = 1 AND tokens > 0 AND quest_id > 0 AND published_at IS NOT NULL'
    );
    if (!$res) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'reward_query_failed'];
    }

    $gifts = [];
    while ($row = $res->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        $questId = (int) ($row['quest_id'] ?? 0);
        $tokens = (int) ($row['tokens'] ?? 0);
        if ($id > 0 && $questId > 0 && $tokens > 0) {
            $gifts[$id] = $row;
        }
    }
    $res->free();

    if ($gifts === []) {
        return $empty('no_active_gifts');
    }

    $charStmt = $charDb->prepare('SELECT guid, name FROM characters WHERE account = ?');
    if (!$charStmt) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'char_list_failed'];
    }
    $charStmt->bind_param('i', $accountId);
    $charStmt->execute();
    $guidRes = $charStmt->get_result();
    $guids = [];
    $charNames = [];
    while ($row = $guidRes->fetch_assoc()) {
        $guid = (int) ($row['guid'] ?? 0);
        if ($guid > 0) {
            $guids[] = $guid;
            $charNames[$guid] = (string) ($row['name'] ?? '');
        }
    }
    $charStmt->close();

    if ($guids === []) {
        return $empty('no_characters');
    }

    $questIds = sahtout_token_gift_collect_world_quest_ids($gifts);
    $rewardedActiveByQuestGuid = sahtout_token_gift_rewarded_active_map($charDb, $guids, $questIds);
    $rewardedExistsByQuestGuid = sahtout_token_gift_rewarded_exists_map($charDb, $guids, $questIds);
    $incompleteByQuestGuid = sahtout_token_gift_incomplete_quest_map($charDb, $guids, $questIds);
    $charGrantCounts = sahtout_token_gift_char_grant_counts($siteDb, $accountId, array_keys($gifts));

    $insertGrant = $siteDb->prepare(
        'INSERT INTO token_gift_grants (account_id, token_gift_id, character_guid, quest_id, tokens_awarded) VALUES (?, ?, ?, ?, ?)'
    );
    $addTokens = $siteDb->prepare(
        'UPDATE user_currencies SET tokens = LEAST(tokens + ?, 9999999) WHERE account_id = ?'
    );
    if (!$insertGrant || !$addTokens) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'prepare_failed'];
    }

    $upsertState = null;
    $selectState = null;
    if ($useSyncState) {
        $upsertState = $siteDb->prepare(
            'INSERT INTO token_gift_reward_sync_state (account_id, token_gift_id, character_guid, last_seen_rewarded_active)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_seen_rewarded_active = VALUES(last_seen_rewarded_active)'
        );
        $selectState = $siteDb->prepare(
            'SELECT last_seen_rewarded_active FROM token_gift_reward_sync_state
             WHERE account_id = ? AND token_gift_id = ? AND character_guid = ?'
        );
    }

    $granted = [];
    $tokensAdded = 0;

    foreach ($gifts as $giftId => $row) {
        $giftId = (int) $giftId;
        $questId = (int) ($row['quest_id'] ?? 0);
        $itemEntry = (int) ($row['item_entry'] ?? 0);
        $tokenAmount = (int) ($row['tokens'] ?? 0);
        $giftName = (string) ($row['name'] ?? '');

        if ($useSyncState) {
            $activeByGuid = [];
            foreach ($guids as $bootstrapGuid) {
                $bootstrapGuid = (int) $bootstrapGuid;
                if ($bootstrapGuid <= 0) {
                    continue;
                }
                $snap = sahtout_token_gift_char_reward_snapshot(
                    $questId,
                    $itemEntry,
                    $bootstrapGuid,
                    $rewardedExistsByQuestGuid,
                    $rewardedActiveByQuestGuid
                );
                $activeByGuid[$bootstrapGuid] = $snap['active'];
            }
            sahtout_token_gift_bootstrap_reward_sync_state(
                $siteDb,
                $accountId,
                $giftId,
                $guids,
                $activeByGuid
            );
        }

        foreach ($guids as $guid) {
            $guid = (int) $guid;
            if ($guid <= 0) {
                continue;
            }

            $snap = sahtout_token_gift_char_reward_snapshot(
                $questId,
                $itemEntry,
                $guid,
                $rewardedExistsByQuestGuid,
                $rewardedActiveByQuestGuid
            );
            if (!$snap['rewarded']) {
                continue;
            }

            $active = $snap['active'];
            $grantQuestId = $snap['matched_quest_id'] > 0 ? $snap['matched_quest_id'] : $questId;
            $inProgress = sahtout_token_gift_guid_quest_in_progress(
                $questId,
                $itemEntry,
                $guid,
                $incompleteByQuestGuid
            );
            $charGrantCount = (int) (($charGrantCounts[$giftId] ?? [])[$guid] ?? 0);

            $lastSeen = 0;
            if ($useSyncState && $selectState) {
                $selectState->bind_param('iii', $accountId, $giftId, $guid);
                $selectState->execute();
                $stateRes = $selectState->get_result();
                if ($stateRow = $stateRes->fetch_assoc()) {
                    $lastSeen = (int) ($stateRow['last_seen_rewarded_active'] ?? 0) > 0 ? 1 : 0;
                }
                $stateRes->free();

                if ($inProgress && $lastSeen === 1) {
                    $resetSeen = 0;
                    $upsertState->bind_param('iiii', $accountId, $giftId, $guid, $resetSeen);
                    $upsertState->execute();
                    $lastSeen = 0;
                }

                if ($charGrantCount === 0 && $lastSeen === 1 && !$inProgress) {
                    $resetSeen = 0;
                    $upsertState->bind_param('iiii', $accountId, $giftId, $guid, $resetSeen);
                    $upsertState->execute();
                    $lastSeen = 0;
                }
            }

            if ($useSyncState) {
                $shouldGrant = $charGrantCount === 0 || ($active === 1 && $lastSeen === 0);
            } else {
                $shouldGrant = $charGrantCount === 0
                    && !sahtout_token_gift_has_legacy_account_grant($siteDb, $accountId, $giftId);
            }

            if ($useSyncState && !$shouldGrant && $lastSeen !== $active && $charGrantCount > 0) {
                $upsertState->bind_param('iiii', $accountId, $giftId, $guid, $active);
                $upsertState->execute();
            }

            if (!$shouldGrant) {
                continue;
            }

            $siteDb->begin_transaction();
            try {
                $insertGrant->bind_param('iiiii', $accountId, $giftId, $guid, $grantQuestId, $tokenAmount);
                if (!$insertGrant->execute()) {
                    throw new RuntimeException('grant_insert');
                }
                if (!sahtout_token_gift_add_tokens($siteDb, $addTokens, $tokenAmount, $accountId)) {
                    throw new RuntimeException('token_update');
                }
                if ($useSyncState && $upsertState) {
                    $seenAfterGrant = 1;
                    $upsertState->bind_param('iiii', $accountId, $giftId, $guid, $seenAfterGrant);
                    if (!$upsertState->execute()) {
                        throw new RuntimeException('sync_state');
                    }
                }
                $siteDb->commit();

                $charGrantCounts[$giftId][$guid] = $charGrantCount + 1;

                $charName = $charNames[$guid] ?? '';
                $detailName = trim($giftName) !== '' ? $giftName : ('Promo #' . $giftId);
                sahtout_log_website_activity(
                    $siteDb,
                    $accountId,
                    'Token Gift Reward',
                    '+' . number_format($tokenAmount) . ' tokens — ' . $detailName,
                    $charName !== '' ? $charName : null
                );

                $granted[] = [
                    'token_gift_id' => $giftId,
                    'quest_id' => $grantQuestId,
                    'tokens' => $tokenAmount,
                    'name' => $giftName,
                    'character_guid' => $guid,
                ];
                $tokensAdded += $tokenAmount;
            } catch (Throwable $e) {
                $siteDb->rollback();
                error_log(
                    'token_gift_sync grant failed account=' . $accountId
                    . ' gift=' . $giftId . ' guid=' . $guid . ' quest=' . $grantQuestId . ': ' . $e->getMessage()
                );
            }
        }
    }

    $insertGrant->close();
    $addTokens->close();
    $selectState?->close();
    $upsertState?->close();

    if ($tokensAdded === 0 && $gifts !== []) {
        error_log(
            'token_gift_sync none_eligible account=' . $accountId
            . ' char_count=' . count($guids)
            . ' use_sync_state=' . ($useSyncState ? '1' : '0')
            . ' quest_ids=' . implode(',', $questIds)
        );
    }

    return [
        'ok' => true,
        'granted' => $granted,
        'tokens_added' => $tokensAdded,
        'message' => $tokensAdded > 0 ? 'granted' : 'none_eligible',
    ];
}

/**
 * @param array<int, array<string, mixed>> $gifts
 * @return list<int>
 */
function sahtout_token_gift_collect_world_quest_ids(array $gifts): array
{
    $ids = [];
    foreach ($gifts as $row) {
        $questId = (int) ($row['quest_id'] ?? 0);
        $itemEntry = (int) ($row['item_entry'] ?? 0);
        if ($questId > 0) {
            $ids[$questId] = true;
        }
        if ($itemEntry > 0) {
            $ids[$itemEntry] = true;
        }
    }

    return array_map('intval', array_keys($ids));
}

/**
 * @param array<int, array<int, true>> $rewardedExistsByQuestGuid
 * @param array<int, array<int, int>> $rewardedActiveByQuestGuid
 * @return array{rewarded: bool, active: int, matched_quest_id: int}
 */
function sahtout_token_gift_char_reward_snapshot(
    int $siteQuestId,
    int $itemEntry,
    int $guid,
    array $rewardedExistsByQuestGuid,
    array $rewardedActiveByQuestGuid
): array {
    $candidates = array_values(array_unique(array_filter(
        [$siteQuestId, $itemEntry],
        static fn (int $id): bool => $id > 0
    )));

    $rewarded = false;
    $active = 0;
    $matchedQuestId = 0;
    foreach ($candidates as $candidateQuestId) {
        if (empty($rewardedExistsByQuestGuid[$candidateQuestId][$guid])) {
            continue;
        }
        $rewarded = true;
        $matchedQuestId = $candidateQuestId;
        $active = max($active, (int) (($rewardedActiveByQuestGuid[$candidateQuestId] ?? [])[$guid] ?? 1));
    }

    return [
        'rewarded' => $rewarded,
        'active' => $active > 0 ? 1 : 0,
        'matched_quest_id' => $matchedQuestId,
    ];
}

/**
 * @param array<int, array<int, true>> $incompleteByQuestGuid
 */
function sahtout_token_gift_guid_quest_in_progress(
    int $siteQuestId,
    int $itemEntry,
    int $guid,
    array $incompleteByQuestGuid
): bool {
    foreach (array_unique(array_filter([$siteQuestId, $itemEntry], static fn (int $id): bool => $id > 0)) as $q) {
        if (!empty($incompleteByQuestGuid[$q][$guid])) {
            return true;
        }
    }

    return false;
}

function sahtout_token_gift_has_legacy_account_grant(mysqli $siteDb, int $accountId, int $giftId): bool
{
    if ($accountId <= 0 || $giftId <= 0) {
        return false;
    }
    $stmt = $siteDb->prepare(
        'SELECT 1 FROM token_gift_grants
         WHERE account_id = ? AND token_gift_id = ? AND character_guid = 0 LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $accountId, $giftId);
    $stmt->execute();
    $has = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $has;
}

/**
 * After upgrading from one-time grants, seed sync state so existing payouts are not duplicated.
 *
 * @param list<int> $guids
 * @param array<int, int> $activeByGuid guid => 0|1
 */
function sahtout_token_gift_bootstrap_reward_sync_state(
    mysqli $siteDb,
    int $accountId,
    int $giftId,
    array $guids,
    array $activeByGuid
): void {
    static $bootstrapped = [];
    $key = $accountId . ':' . $giftId;
    if (isset($bootstrapped[$key])) {
        return;
    }
    $bootstrapped[$key] = true;

    $grantGuidsStmt = $siteDb->prepare(
        'SELECT DISTINCT character_guid FROM token_gift_grants
         WHERE account_id = ? AND token_gift_id = ? AND character_guid > 0'
    );
    if (!$grantGuidsStmt) {
        return;
    }
    $grantGuidsStmt->bind_param('ii', $accountId, $giftId);
    $grantGuidsStmt->execute();
    $grantGuidRes = $grantGuidsStmt->get_result();
    $grantedGuids = [];
    while ($grantRow = $grantGuidRes->fetch_assoc()) {
        $g = (int) ($grantRow['character_guid'] ?? 0);
        if ($g > 0) {
            $grantedGuids[$g] = true;
        }
    }
    $grantGuidRes->free();
    $grantGuidsStmt->close();

    if ($grantedGuids === []) {
        return;
    }

    $ins = $siteDb->prepare(
        'INSERT IGNORE INTO token_gift_reward_sync_state (account_id, token_gift_id, character_guid, last_seen_rewarded_active)
         VALUES (?, ?, ?, ?)'
    );
    $hasStateStmt = $siteDb->prepare(
        'SELECT 1 FROM token_gift_reward_sync_state
         WHERE account_id = ? AND token_gift_id = ? AND character_guid = ? LIMIT 1'
    );
    if (!$ins || !$hasStateStmt) {
        $ins?->close();
        $hasStateStmt?->close();

        return;
    }

    foreach ($guids as $guid) {
        $guid = (int) $guid;
        if ($guid <= 0 || !isset($grantedGuids[$guid])) {
            continue;
        }

        $hasStateStmt->bind_param('iii', $accountId, $giftId, $guid);
        $hasStateStmt->execute();
        $already = $hasStateStmt->get_result()->num_rows > 0;
        $hasStateStmt->free_result();

        if ($already) {
            continue;
        }

        $active = (int) ($activeByGuid[$guid] ?? 0) > 0 ? 1 : 0;
        $ins->bind_param('iiii', $accountId, $giftId, $guid, $active);
        $ins->execute();
    }
    $ins->close();
    $hasStateStmt->close();
}

function sahtout_token_gift_add_tokens(mysqli $siteDb, mysqli_stmt $addTokens, int $tokenAmount, int $accountId): bool
{
    if ($tokenAmount <= 0 || $accountId <= 0) {
        return false;
    }

    $addTokens->bind_param('ii', $tokenAmount, $accountId);
    if ($addTokens->execute() && $addTokens->affected_rows > 0) {
        return true;
    }

    error_log(
        'token_gift_sync: user_currencies UPDATE affected 0 for account_id=' . $accountId
        . ' (missing row?)'
    );

    return false;
}

/**
 * @param list<int> $giftIds
 * @return array<int, array<int, int>> giftId => guid => grant count
 */
function sahtout_token_gift_char_grant_counts(mysqli $siteDb, int $accountId, array $giftIds): array
{
    $giftIds = array_values(array_filter(array_map('intval', $giftIds), static fn (int $id): bool => $id > 0));
    $map = [];
    if ($accountId <= 0 || $giftIds === []) {
        return $map;
    }

    $giftPh = implode(',', array_fill(0, count($giftIds), '?'));
    $sql = "SELECT token_gift_id, character_guid, COUNT(*) AS c FROM token_gift_grants
            WHERE account_id = ? AND character_guid > 0 AND token_gift_id IN ({$giftPh})
            GROUP BY token_gift_id, character_guid";
    $stmt = $siteDb->prepare($sql);
    if (!$stmt) {
        return $map;
    }
    $types = 'i' . str_repeat('i', count($giftIds));
    $stmt->bind_param($types, $accountId, ...$giftIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $giftId = (int) ($row['token_gift_id'] ?? 0);
        $guid = (int) ($row['character_guid'] ?? 0);
        if ($giftId > 0 && $guid > 0) {
            $map[$giftId][$guid] = (int) ($row['c'] ?? 0);
        }
    }
    $stmt->close();

    return $map;
}

/**
 * @param list<int> $guids
 * @param list<int> $questIds
 * @return array<int, array<int, true>> questId => guid => true
 */
function sahtout_token_gift_rewarded_exists_map(mysqli $charDb, array $guids, array $questIds): array
{
    $guids = array_values(array_filter(array_map('intval', $guids), static fn (int $g): bool => $g > 0));
    $questIds = array_values(array_filter(array_map('intval', $questIds), static fn (int $q): bool => $q > 0));
    $map = [];
    foreach ($questIds as $q) {
        $map[$q] = [];
    }
    if ($guids === [] || $questIds === []) {
        return $map;
    }

    $rewardedTable = $charDb->query("SHOW TABLES LIKE 'character_queststatus_rewarded'");
    $hasRewarded = $rewardedTable && $rewardedTable->num_rows > 0;
    $rewardedTable?->free();
    if (!$hasRewarded) {
        return $map;
    }

    $guidPh = implode(',', array_fill(0, count($guids), '?'));
    $questPh = implode(',', array_fill(0, count($questIds), '?'));
    $sql = "SELECT guid, quest FROM character_queststatus_rewarded
            WHERE guid IN ({$guidPh}) AND quest IN ({$questPh})";
    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        return $map;
    }
    $types = str_repeat('i', count($guids) + count($questIds));
    $stmt->bind_param($types, ...array_merge($guids, $questIds));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $q = (int) ($row['quest'] ?? 0);
        $g = (int) ($row['guid'] ?? 0);
        if ($q > 0 && $g > 0) {
            $map[$q][$g] = true;
        }
    }
    $stmt->close();

    return $map;
}

/**
 * Quest log status INCOMPLETE (repeatable letter accepted again).
 *
 * @param list<int> $guids
 * @param list<int> $questIds
 * @return array<int, array<int, true>>
 */
function sahtout_token_gift_incomplete_quest_map(mysqli $charDb, array $guids, array $questIds): array
{
    $guids = array_values(array_filter(array_map('intval', $guids), static fn (int $g): bool => $g > 0));
    $questIds = array_values(array_filter(array_map('intval', $questIds), static fn (int $q): bool => $q > 0));
    $map = [];
    foreach ($questIds as $q) {
        $map[$q] = [];
    }
    if ($guids === [] || $questIds === []) {
        return $map;
    }

    $guidPh = implode(',', array_fill(0, count($guids), '?'));
    $questPh = implode(',', array_fill(0, count($questIds), '?'));
    $sql = "SELECT guid, quest FROM character_queststatus
            WHERE guid IN ({$guidPh}) AND quest IN ({$questPh}) AND status = 1";
    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        return $map;
    }
    $types = str_repeat('i', count($guids) + count($questIds));
    $stmt->bind_param($types, ...array_merge($guids, $questIds));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $q = (int) ($row['quest'] ?? 0);
        $g = (int) ($row['guid'] ?? 0);
        if ($q > 0 && $g > 0) {
            $map[$q][$g] = true;
        }
    }
    $stmt->close();

    return $map;
}

function sahtout_char_queststatus_rewarded_has_active_column(mysqli $charDb): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $res = $charDb->query("SHOW COLUMNS FROM character_queststatus_rewarded LIKE 'active'");
    $has = $res && $res->num_rows > 0;
    $res?->free();

    return $has;
}

/**
 * @param list<int> $guids
 * @param list<int> $questIds
 * @return array<int, array<int, int>> questId => guid => active (0|1)
 */
function sahtout_token_gift_rewarded_active_map(mysqli $charDb, array $guids, array $questIds): array
{
    $guids = array_values(array_filter(array_map('intval', $guids), static fn (int $g): bool => $g > 0));
    $questIds = array_values(array_filter(array_map('intval', $questIds), static fn (int $q): bool => $q > 0));
    $map = [];
    foreach ($questIds as $q) {
        $map[$q] = [];
    }
    if ($guids === [] || $questIds === []) {
        return $map;
    }

    $rewardedTable = $charDb->query("SHOW TABLES LIKE 'character_queststatus_rewarded'");
    $hasRewarded = $rewardedTable && $rewardedTable->num_rows > 0;
    $rewardedTable?->free();
    if (!$hasRewarded) {
        return $map;
    }

    $guidPh = implode(',', array_fill(0, count($guids), '?'));
    $questPh = implode(',', array_fill(0, count($questIds), '?'));
    $hasActive = sahtout_char_queststatus_rewarded_has_active_column($charDb);
    $sql = $hasActive
        ? "SELECT guid, quest, active FROM character_queststatus_rewarded
            WHERE guid IN ({$guidPh}) AND quest IN ({$questPh})"
        : "SELECT guid, quest FROM character_queststatus_rewarded
            WHERE guid IN ({$guidPh}) AND quest IN ({$questPh})";
    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        return $map;
    }
    $types = str_repeat('i', count($guids) + count($questIds));
    $stmt->bind_param($types, ...array_merge($guids, $questIds));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $q = (int) ($row['quest'] ?? 0);
        $g = (int) ($row['guid'] ?? 0);
        if ($q > 0 && $g > 0) {
            if ($hasActive) {
                $map[$q][$g] = (int) ($row['active'] ?? 0) > 0 ? 1 : 0;
            } else {
                $map[$q][$g] = 1;
            }
        }
    }
    $stmt->close();

    return $map;
}

/** @param list<int> $guids @return array<int, true> quest id => completed */
function sahtout_account_completed_quest_ids(mysqli $charDb, array $guids): array
{
    $guids = array_values(array_filter(array_map('intval', $guids), static fn (int $g): bool => $g > 0));
    if ($guids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($guids), '?'));
    $types = str_repeat('i', count($guids));
    $completed = [];

    $sql = "SELECT DISTINCT quest FROM character_queststatus
            WHERE guid IN ({$placeholders}) AND status IN (2, 3)";
    $stmt = $charDb->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$guids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $q = (int) ($row['quest'] ?? 0);
            if ($q > 0) {
                $completed[$q] = true;
            }
        }
        $stmt->close();
    }

    $rewardedTable = $charDb->query("SHOW TABLES LIKE 'character_queststatus_rewarded'");
    $hasRewarded = $rewardedTable && $rewardedTable->num_rows > 0;
    $rewardedTable?->free();

    if ($hasRewarded) {
        $sql2 = "SELECT DISTINCT quest FROM character_queststatus_rewarded WHERE guid IN ({$placeholders})";
        $stmt2 = $charDb->prepare($sql2);
        if ($stmt2) {
            $stmt2->bind_param($types, ...$guids);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row = $res2->fetch_assoc()) {
                $q = (int) ($row['quest'] ?? 0);
                if ($q > 0) {
                    $completed[$q] = true;
                }
            }
            $stmt2->close();
        }
    }

    return $completed;
}

/**
 * @param list<array{token_gift_id:int, tokens:int, name:string}> $granted
 */
function sahtout_format_token_gift_reward_notice(array $granted, int $tokensAdded): string
{
    if ($tokensAdded <= 0 || $granted === []) {
        return '';
    }

    $summary = translate(
        'account_token_gift_awarded',
        'You received %s shop tokens for %d sealed letter promo(s).',
        number_format($tokensAdded),
        count($granted)
    );

    $details = [];
    foreach (array_slice($granted, 0, 5) as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = translate('account_token_gift_untitled', 'Promo #%d', (int) ($row['token_gift_id'] ?? 0));
        }
        $details[] = $name . ' (+' . number_format((int) ($row['tokens'] ?? 0)) . ')';
    }
    if (count($granted) > 5) {
        $details[] = translate('account_achievement_and_more', '…and %d more.', count($granted) - 5);
    }

    return trim($summary . ' ' . implode(' ', $details));
}
