<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_character_stat_rewards.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'website_activity.php';

/**
 * @return array{
 *   ok: bool,
 *   granted: list<array{reward_id:int, stat_type:string, threshold:int, tokens:int}>,
 *   tokens_added: int,
 *   message: string
 * }
 */
function sahtout_sync_character_stat_rewards(mysqli $siteDb, mysqli $charDb, int $accountId): array
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
    if (!sahtout_character_stat_rewards_table_exists($siteDb)
        || !sahtout_character_stat_grants_table_exists($siteDb)) {
        return $empty('tables_missing');
    }
    if ($charDb->connect_error) {
        return $empty('char_db_unavailable');
    }

    $res = $siteDb->query(
        'SELECT id, stat_type, threshold, tokens FROM character_stat_token_rewards
         WHERE is_enabled = 1 AND tokens > 0'
    );
    if (!$res) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'reward_query_failed'];
    }
    $rewards = [];
    while ($row = $res->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $rewards[] = $row;
        }
    }
    $res->free();

    if ($rewards === []) {
        return $empty('no_active_rewards');
    }

    $stats = sahtout_account_character_stat_maximums($charDb, $accountId);

    $grantStmt = $siteDb->prepare(
        'SELECT reward_id FROM character_stat_token_grants WHERE account_id = ?'
    );
    if (!$grantStmt) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'grant_query_failed'];
    }
    $grantStmt->bind_param('i', $accountId);
    $grantStmt->execute();
    $grantRes = $grantStmt->get_result();
    $already = [];
    while ($row = $grantRes->fetch_assoc()) {
        $already[(int) $row['reward_id']] = true;
    }
    $grantStmt->close();

    $pending = [];
    foreach ($rewards as $row) {
        $rewardId = (int) $row['id'];
        $type = (string) ($row['stat_type'] ?? '');
        $threshold = (int) ($row['threshold'] ?? 0);
        $tokens = (int) ($row['tokens'] ?? 0);
        if ($rewardId <= 0 || $tokens <= 0 || isset($already[$rewardId])) {
            continue;
        }
        $current = (int) ($stats[$type] ?? 0);
        if ($current >= $threshold) {
            $pending[] = [
                'reward_id' => $rewardId,
                'stat_type' => $type,
                'threshold' => $threshold,
                'tokens' => $tokens,
            ];
        }
    }

    if ($pending === []) {
        return $empty('none_eligible');
    }

    $insertGrant = $siteDb->prepare(
        'INSERT INTO character_stat_token_grants (account_id, reward_id, tokens_awarded) VALUES (?, ?, ?)'
    );
    $addTokens = $siteDb->prepare(
        'UPDATE user_currencies SET tokens = LEAST(tokens + ?, 9999999) WHERE account_id = ?'
    );
    if (!$insertGrant || !$addTokens) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'prepare_failed'];
    }

    $granted = [];
    $tokensAdded = 0;

    foreach ($pending as $item) {
        $rewardId = (int) $item['reward_id'];
        $tokenAmount = (int) $item['tokens'];
        $siteDb->begin_transaction();
        try {
            $insertGrant->bind_param('iii', $accountId, $rewardId, $tokenAmount);
            if (!$insertGrant->execute()) {
                throw new RuntimeException('grant_insert');
            }
            $addTokens->bind_param('ii', $tokenAmount, $accountId);
            if (!$addTokens->execute() || $addTokens->affected_rows <= 0) {
                throw new RuntimeException('token_update');
            }
            $siteDb->commit();
            $granted[] = $item;
            $tokensAdded += $tokenAmount;
            $type = (string) ($item['stat_type'] ?? '');
            $label = translate(sahtout_character_stat_type_label_key($type), $type);
            $threshold = number_format((int) ($item['threshold'] ?? 0));
            sahtout_log_website_activity(
                $siteDb,
                $accountId,
                'Character Milestone Token Reward',
                '+' . number_format($tokenAmount) . ' tokens — ' . $label . ' ≥ ' . $threshold
            );
        } catch (Throwable $e) {
            $siteDb->rollback();
            if ($siteDb->errno !== 1062) {
                error_log('Character stat reward sync failed for account ' . $accountId . ' reward ' . $rewardId . ': ' . $e->getMessage());
            }
        }
    }

    return [
        'ok' => true,
        'granted' => $granted,
        'tokens_added' => $tokensAdded,
        'message' => $tokensAdded > 0 ? 'granted' : 'none_granted',
    ];
}

/**
 * @param array{tokens_added?:int, granted?:list<mixed>} $statNotice
 */
function sahtout_format_character_stat_reward_notice(array $statNotice): string
{
    $tokens = (int) ($statNotice['tokens_added'] ?? 0);
    $granted = $statNotice['granted'] ?? [];
    if ($tokens <= 0 || $granted === []) {
        return '';
    }

    $count = count($granted);
    $summary = translate(
        'account_stat_tokens_awarded',
        'You received %s shop tokens for %d character milestone(s).',
        number_format($tokens),
        $count
    );

    $details = [];
    foreach (array_slice($granted, 0, 5) as $row) {
        $type = (string) ($row['stat_type'] ?? '');
        $label = translate(sahtout_character_stat_type_label_key($type), $type);
        $threshold = number_format((int) ($row['threshold'] ?? 0));
        $details[] = $label . ' ≥ ' . $threshold . ' (+' . number_format((int) ($row['tokens'] ?? 0)) . ')';
    }
    if ($count > 5) {
        $details[] = translate('account_achievement_and_more', '…and %d more.', $count - 5);
    }

    return $summary . ' ' . implode(' ', $details);
}
