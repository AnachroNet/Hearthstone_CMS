<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_token_gifts.php';

/**
 * @return array{
 *   ok: bool,
 *   granted: list<array{token_gift_id:int, quest_id:int, tokens:int, name:string}>,
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

    $grantStmt = $siteDb->prepare('SELECT token_gift_id FROM token_gift_grants WHERE account_id = ?');
    if (!$grantStmt) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'grant_query_failed'];
    }
    $grantStmt->bind_param('i', $accountId);
    $grantStmt->execute();
    $grantRes = $grantStmt->get_result();
    $already = [];
    while ($row = $grantRes->fetch_assoc()) {
        $already[(int) $row['token_gift_id']] = true;
    }
    $grantStmt->close();

    $charStmt = $charDb->prepare('SELECT guid FROM characters WHERE account = ?');
    if (!$charStmt) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'char_list_failed'];
    }
    $charStmt->bind_param('i', $accountId);
    $charStmt->execute();
    $guidRes = $charStmt->get_result();
    $guids = [];
    while ($row = $guidRes->fetch_assoc()) {
        $guids[] = (int) ($row['guid'] ?? 0);
    }
    $charStmt->close();

    if ($guids === []) {
        return $empty('no_characters');
    }

    $completedQuests = sahtout_account_completed_quest_ids($charDb, $guids);

    $pending = [];
    foreach ($gifts as $giftId => $row) {
        if (isset($already[$giftId])) {
            continue;
        }
        $questId = (int) ($row['quest_id'] ?? 0);
        if (isset($completedQuests[$questId])) {
            $pending[] = $row;
        }
    }

    if ($pending === []) {
        return $empty('none_eligible');
    }

    $insertGrant = $siteDb->prepare(
        'INSERT INTO token_gift_grants (account_id, token_gift_id, quest_id, tokens_awarded) VALUES (?, ?, ?, ?)'
    );
    $addTokens = $siteDb->prepare(
        'UPDATE user_currencies SET tokens = LEAST(tokens + ?, 9999999) WHERE account_id = ?'
    );
    if (!$insertGrant || !$addTokens) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'prepare_failed'];
    }

    $granted = [];
    $tokensAdded = 0;

    foreach ($pending as $row) {
        $giftId = (int) ($row['id'] ?? 0);
        $questId = (int) ($row['quest_id'] ?? 0);
        $tokenAmount = (int) ($row['tokens'] ?? 0);
        $siteDb->begin_transaction();
        try {
            $insertGrant->bind_param('iiii', $accountId, $giftId, $questId, $tokenAmount);
            if (!$insertGrant->execute()) {
                throw new RuntimeException('grant_insert');
            }
            $addTokens->bind_param('ii', $tokenAmount, $accountId);
            if (!$addTokens->execute() || $addTokens->affected_rows <= 0) {
                throw new RuntimeException('token_update');
            }
            $siteDb->commit();
            $granted[] = [
                'token_gift_id' => $giftId,
                'quest_id' => $questId,
                'tokens' => $tokenAmount,
                'name' => (string) ($row['name'] ?? ''),
            ];
            $tokensAdded += $tokenAmount;
        } catch (Throwable) {
            $siteDb->rollback();
        }
    }

    $insertGrant->close();
    $addTokens->close();

    return [
        'ok' => true,
        'granted' => $granted,
        'tokens_added' => $tokensAdded,
        'message' => $tokensAdded > 0 ? 'granted' : 'none_granted',
    ];
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
