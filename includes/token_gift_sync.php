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
        'SELECT id, name, quest_id, tokens FROM token_gifts
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

    $questIds = array_values(array_unique(array_map(static fn (array $g): int => (int) ($g['quest_id'] ?? 0), $gifts)));
    $rewardedActiveByQuestGuid = sahtout_token_gift_rewarded_active_map($charDb, $guids, $questIds);

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

    if (!$useSyncState) {
        $legacyAlready = [];
        $legacyStmt = $siteDb->prepare('SELECT token_gift_id FROM token_gift_grants WHERE account_id = ?');
        if ($legacyStmt) {
            $legacyStmt->bind_param('i', $accountId);
            $legacyStmt->execute();
            $legacyRes = $legacyStmt->get_result();
            while ($legacyRow = $legacyRes->fetch_assoc()) {
                $legacyAlready[(int) $legacyRow['token_gift_id']] = true;
            }
            $legacyStmt->close();
        }
        $completedQuests = sahtout_account_completed_quest_ids($charDb, $guids);
        foreach ($gifts as $giftId => $row) {
            $giftId = (int) $giftId;
            if (isset($legacyAlready[$giftId])) {
                continue;
            }
            $questId = (int) ($row['quest_id'] ?? 0);
            if (!isset($completedQuests[$questId])) {
                continue;
            }
            $tokenAmount = (int) ($row['tokens'] ?? 0);
            $giftName = (string) ($row['name'] ?? '');
            $siteDb->begin_transaction();
            try {
                $zeroGuid = 0;
                $insertGrant->bind_param('iiiii', $accountId, $giftId, $zeroGuid, $questId, $tokenAmount);
                if (!$insertGrant->execute()) {
                    throw new RuntimeException('grant_insert');
                }
                $addTokens->bind_param('ii', $tokenAmount, $accountId);
                if (!$addTokens->execute() || $addTokens->affected_rows <= 0) {
                    throw new RuntimeException('token_update');
                }
                $siteDb->commit();
                $detailName = trim($giftName) !== '' ? $giftName : ('Promo #' . $giftId);
                sahtout_log_website_activity(
                    $siteDb,
                    $accountId,
                    'Token Gift Reward',
                    '+' . number_format($tokenAmount) . ' tokens — ' . $detailName
                );
                $granted[] = [
                    'token_gift_id' => $giftId,
                    'quest_id' => $questId,
                    'tokens' => $tokenAmount,
                    'name' => $giftName,
                ];
                $tokensAdded += $tokenAmount;
            } catch (Throwable) {
                $siteDb->rollback();
            }
        }
    } else {
        foreach ($gifts as $giftId => $row) {
            $giftId = (int) $giftId;
            $questId = (int) ($row['quest_id'] ?? 0);
            $tokenAmount = (int) ($row['tokens'] ?? 0);
            $giftName = (string) ($row['name'] ?? '');

            sahtout_token_gift_bootstrap_reward_sync_state(
                $siteDb,
                $accountId,
                $giftId,
                $guids,
                $rewardedActiveByQuestGuid[$questId] ?? []
            );

            foreach ($guids as $guid) {
                $active = (int) (($rewardedActiveByQuestGuid[$questId] ?? [])[$guid] ?? 0);
                $active = $active > 0 ? 1 : 0;

                $lastSeen = 0;
                $selectState->bind_param('iii', $accountId, $giftId, $guid);
                $selectState->execute();
                $stateRes = $selectState->get_result();
                if ($stateRow = $stateRes->fetch_assoc()) {
                    $lastSeen = (int) ($stateRow['last_seen_rewarded_active'] ?? 0) > 0 ? 1 : 0;
                }
                $stateRes->free();

                $shouldGrant = $active === 1 && $lastSeen === 0;

                if (!$shouldGrant && $lastSeen !== $active) {
                    $upsertState->bind_param('iiii', $accountId, $giftId, $guid, $active);
                    $upsertState->execute();
                }

                if (!$shouldGrant) {
                    continue;
                }

                $siteDb->begin_transaction();
                try {
                    $insertGrant->bind_param('iiiii', $accountId, $giftId, $guid, $questId, $tokenAmount);
                    if (!$insertGrant->execute()) {
                        throw new RuntimeException('grant_insert');
                    }
                    $addTokens->bind_param('ii', $tokenAmount, $accountId);
                    if (!$addTokens->execute() || $addTokens->affected_rows <= 0) {
                        throw new RuntimeException('token_update');
                    }
                    $seenAfterGrant = 1;
                    $upsertState->bind_param('iiii', $accountId, $giftId, $guid, $seenAfterGrant);
                    if (!$upsertState->execute()) {
                        throw new RuntimeException('sync_state');
                    }
                    $siteDb->commit();

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
                        'quest_id' => $questId,
                        'tokens' => $tokenAmount,
                        'name' => $giftName,
                        'character_guid' => $guid,
                    ];
                    $tokensAdded += $tokenAmount;
                } catch (Throwable) {
                    $siteDb->rollback();
                }
            }
        }
    }

    $insertGrant->close();
    $addTokens->close();
    $selectState?->close();
    $upsertState?->close();

    return [
        'ok' => true,
        'granted' => $granted,
        'tokens_added' => $tokensAdded,
        'message' => $tokensAdded > 0 ? 'granted' : 'none_eligible',
    ];
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

    $check = $siteDb->prepare(
        'SELECT 1 FROM token_gift_reward_sync_state WHERE account_id = ? AND token_gift_id = ? LIMIT 1'
    );
    if (!$check) {
        return;
    }
    $check->bind_param('ii', $accountId, $giftId);
    $check->execute();
    $hasState = $check->get_result()->num_rows > 0;
    $check->close();
    if ($hasState) {
        return;
    }

    $grantCountStmt = $siteDb->prepare(
        'SELECT COUNT(*) AS c FROM token_gift_grants WHERE account_id = ? AND token_gift_id = ?'
    );
    if (!$grantCountStmt) {
        return;
    }
    $grantCountStmt->bind_param('ii', $accountId, $giftId);
    $grantCountStmt->execute();
    $grantCount = (int) ($grantCountStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $grantCountStmt->close();

    if ($grantCount <= 0) {
        return;
    }

    $ins = $siteDb->prepare(
        'INSERT INTO token_gift_reward_sync_state (account_id, token_gift_id, character_guid, last_seen_rewarded_active)
         VALUES (?, ?, ?, ?)'
    );
    if (!$ins) {
        return;
    }

    foreach ($guids as $guid) {
        $guid = (int) $guid;
        if ($guid <= 0) {
            continue;
        }
        $active = (int) ($activeByGuid[$guid] ?? 0) > 0 ? 1 : 0;
        $ins->bind_param('iiii', $accountId, $giftId, $guid, $active);
        $ins->execute();
    }
    $ins->close();
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
    $sql = "SELECT guid, quest, active FROM character_queststatus_rewarded
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
            $map[$q][$g] = (int) ($row['active'] ?? 0) > 0 ? 1 : 0;
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
