<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_achievement_rewards.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'character_stat_reward_sync.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'token_gift_sync.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'website_activity.php';

function sahtout_achievement_token_grants_table_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $siteDb->query("SHOW TABLES LIKE 'achievement_token_grants'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

/**
 * Award enabled achievement token rewards once per account when any character has completed the achievement.
 *
 * @return array{
 *   ok: bool,
 *   granted: list<array{achievement_id:int, tokens:int, title:?string}>,
 *   tokens_added: int,
 *   message: string
 * }
 */
function sahtout_sync_achievement_rewards(mysqli $siteDb, mysqli $charDb, int $accountId): array
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
    if (!sahtout_admin_achievement_rewards_table_exists($siteDb)
        || !sahtout_achievement_token_grants_table_exists($siteDb)) {
        return $empty('tables_missing');
    }
    if ($charDb->connect_error) {
        return $empty('char_db_unavailable');
    }

    $rewardRes = $siteDb->query(
        'SELECT achievement_id, tokens FROM achievement_token_rewards WHERE is_enabled = 1 AND tokens > 0'
    );
    if (!$rewardRes) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'reward_query_failed'];
    }

    $rewards = [];
    while ($row = $rewardRes->fetch_assoc()) {
        $id = (int) ($row['achievement_id'] ?? 0);
        $tokens = (int) ($row['tokens'] ?? 0);
        if ($id > 0 && $tokens > 0) {
            $rewards[$id] = $tokens;
        }
    }
    $rewardRes->free();

    if ($rewards === []) {
        return $empty('no_active_rewards');
    }

    $stmt = $charDb->prepare('SELECT guid FROM characters WHERE account = ?');
    if (!$stmt) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'char_list_failed'];
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $guidRes = $stmt->get_result();
    $guids = [];
    while ($row = $guidRes->fetch_assoc()) {
        $guids[] = (int) $row['guid'];
    }
    $stmt->close();

    if ($guids === []) {
        return $empty('no_characters');
    }

    $achIds = array_keys($rewards);
    $guidPlaceholders = implode(',', array_fill(0, count($guids), '?'));
    $achPlaceholders = implode(',', array_fill(0, count($achIds), '?'));

    $sql = "SELECT DISTINCT achievement FROM character_achievement
            WHERE guid IN ($guidPlaceholders) AND achievement IN ($achPlaceholders)";
    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'achievement_query_failed'];
    }

    $types = str_repeat('i', count($guids) + count($achIds));
    $params = array_merge($guids, $achIds);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $achRes = $stmt->get_result();
    $completed = [];
    while ($row = $achRes->fetch_assoc()) {
        $completed[(int) $row['achievement']] = true;
    }
    $stmt->close();

    if ($completed === []) {
        return $empty('none_completed');
    }

    $grantStmt = $siteDb->prepare(
        'SELECT achievement_id FROM achievement_token_grants WHERE account_id = ?'
    );
    if (!$grantStmt) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'grant_query_failed'];
    }
    $grantStmt->bind_param('i', $accountId);
    $grantStmt->execute();
    $grantRes = $grantStmt->get_result();
    $already = [];
    while ($row = $grantRes->fetch_assoc()) {
        $already[(int) $row['achievement_id']] = true;
    }
    $grantStmt->close();

    $pending = [];
    foreach ($completed as $achId => $_) {
        if (isset($rewards[$achId]) && !isset($already[$achId])) {
            $pending[$achId] = $rewards[$achId];
        }
    }

    if ($pending === []) {
        return $empty('already_granted');
    }

    $titles = [];
    if (sahtout_admin_achievement_catalog_table_exists($siteDb)) {
        $ids = array_keys($pending);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $titleStmt = $siteDb->prepare(
            "SELECT achievement_id, title_enus FROM achievement_catalog WHERE achievement_id IN ($ph)"
        );
        if ($titleStmt) {
            $titleStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $titleStmt->execute();
            $tr = $titleStmt->get_result();
            while ($row = $tr->fetch_assoc()) {
                $titles[(int) $row['achievement_id']] = (string) ($row['title_enus'] ?? '');
            }
            $titleStmt->close();
        }
    }

    $insertGrant = $siteDb->prepare(
        'INSERT INTO achievement_token_grants (account_id, achievement_id, tokens_awarded) VALUES (?, ?, ?)'
    );
    $addTokens = $siteDb->prepare(
        'UPDATE user_currencies SET tokens = LEAST(tokens + ?, 9999999) WHERE account_id = ?'
    );
    if (!$insertGrant || !$addTokens) {
        return ['ok' => false, 'granted' => [], 'tokens_added' => 0, 'message' => 'prepare_failed'];
    }

    $granted = [];
    $tokensAdded = 0;

    foreach ($pending as $achId => $tokenAmount) {
        $siteDb->begin_transaction();
        try {
            $insertGrant->bind_param('iii', $accountId, $achId, $tokenAmount);
            if (!$insertGrant->execute()) {
                throw new RuntimeException('grant_insert');
            }
            $addTokens->bind_param('ii', $tokenAmount, $accountId);
            if (!$addTokens->execute() || $addTokens->affected_rows <= 0) {
                throw new RuntimeException('token_update');
            }
            $siteDb->commit();
            $title = $titles[$achId] ?? null;
            $granted[] = [
                'achievement_id' => $achId,
                'tokens' => $tokenAmount,
                'title' => $title,
            ];
            $tokensAdded += $tokenAmount;
            $detailTitle = trim((string) $title) !== '' ? (string) $title : ('Achievement #' . $achId);
            sahtout_log_website_activity(
                $siteDb,
                $accountId,
                'Achievement Token Reward',
                '+' . number_format($tokenAmount) . ' tokens — ' . $detailTitle
            );
        } catch (Throwable $e) {
            $siteDb->rollback();
            if ($siteDb->errno !== 1062) {
                error_log('Achievement reward sync failed for account ' . $accountId . ' achievement ' . $achId . ': ' . $e->getMessage());
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
 * Run achievement, stat, and token-gift reward sync (no session throttle).
 *
 * @return array{
 *   ok: bool,
 *   granted: list<mixed>,
 *   tokens_added: int,
 *   message: string,
 *   stat_granted?: list<mixed>,
 *   token_gift_granted?: list<mixed>,
 *   token_gift_message?: string
 * }
 */
function sahtout_sync_all_website_rewards(mysqli $siteDb, mysqli $charDb, int $accountId): array
{
    $result = sahtout_sync_achievement_rewards($siteDb, $charDb, $accountId);
    $statResult = sahtout_sync_character_stat_rewards($siteDb, $charDb, $accountId);
    $giftResult = sahtout_site_token_gifts_enabled()
        ? sahtout_sync_token_gifts($siteDb, $charDb, $accountId)
        : [
            'ok' => true,
            'granted' => [],
            'tokens_added' => 0,
            'message' => 'feature_disabled',
        ];

    $totalTokens = (int) ($result['tokens_added'] ?? 0)
        + (int) ($statResult['tokens_added'] ?? 0)
        + (int) ($giftResult['tokens_added'] ?? 0);

    return [
        'ok' => ($result['ok'] ?? false) && ($statResult['ok'] ?? false) && ($giftResult['ok'] ?? false),
        'granted' => $result['granted'] ?? [],
        'tokens_added' => $totalTokens,
        'message' => $totalTokens > 0 ? 'granted' : ($giftResult['message'] ?? $result['message'] ?? 'none'),
        'stat_granted' => $statResult['granted'] ?? [],
        'token_gift_granted' => $giftResult['granted'] ?? [],
        'token_gift_message' => (string) ($giftResult['message'] ?? ''),
    ];
}

/** @param array{tokens_added?:int, granted?:list<mixed>, stat_granted?:list<mixed>, stat_tokens_added?:int, token_gift_granted?:list<mixed>, token_gift_tokens_added?:int} $combined */
function sahtout_apply_website_reward_notice_session(array $combined): void
{
    $totalTokens = (int) ($combined['tokens_added'] ?? 0);
    if ($totalTokens <= 0) {
        return;
    }

    $_SESSION['achievement_reward_notice'] = [
        'tokens_added' => $totalTokens,
        'granted' => $combined['granted'] ?? [],
        'stat_granted' => $combined['stat_granted'] ?? [],
        'stat_tokens_added' => (int) ($combined['stat_tokens_added'] ?? 0),
        'token_gift_granted' => $combined['token_gift_granted'] ?? [],
        'token_gift_tokens_added' => (int) ($combined['token_gift_tokens_added'] ?? 0),
    ];
}

/** Throttled sync for active sessions (default 5 minutes). */
function sahtout_maybe_sync_achievement_rewards(mysqli $siteDb, mysqli $charDb, int $accountId, int $minIntervalSeconds = 300): array
{
    $now = time();
    $last = (int) ($_SESSION['achievement_rewards_sync_at'] ?? 0);
    if ($last > 0 && ($now - $last) < $minIntervalSeconds) {
        return [
            'ok' => true,
            'granted' => [],
            'tokens_added' => 0,
            'message' => 'throttled',
        ];
    }

    $_SESSION['achievement_rewards_sync_at'] = $now;
    $combined = sahtout_sync_all_website_rewards($siteDb, $charDb, $accountId);
    if ((int) ($combined['tokens_added'] ?? 0) > 0) {
        $statTokens = 0;
        foreach ($combined['stat_granted'] ?? [] as $row) {
            $statTokens += (int) ($row['tokens'] ?? 0);
        }
        $giftTokens = 0;
        foreach ($combined['token_gift_granted'] ?? [] as $row) {
            $giftTokens += (int) ($row['tokens'] ?? 0);
        }
        sahtout_apply_website_reward_notice_session([
            'tokens_added' => (int) $combined['tokens_added'],
            'granted' => $combined['granted'] ?? [],
            'stat_granted' => $combined['stat_granted'] ?? [],
            'stat_tokens_added' => $statTokens,
            'token_gift_granted' => $combined['token_gift_granted'] ?? [],
            'token_gift_tokens_added' => $giftTokens,
        ]);
    }

    return $combined;
}

/**
 * @param array{tokens_added?:int, granted?:list<array{achievement_id:int,tokens:int,title:?string}>} $notice
 */
function sahtout_format_achievement_reward_notice(array $notice): string
{
    $tokens = (int) ($notice['tokens_added'] ?? 0);
    if ($tokens <= 0) {
        return '';
    }

    $parts = [];
    $granted = $notice['granted'] ?? [];
    if ($granted !== []) {
        $count = count($granted);
        $summary = translate(
            'account_achievement_tokens_awarded',
            'You received %s shop tokens for %d achievement reward(s).',
            number_format(array_sum(array_map(static fn ($row) => (int) ($row['tokens'] ?? 0), $granted))),
            $count
        );

        $details = [];
        foreach (array_slice($granted, 0, 5) as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $title = translate('account_achievement_untitled', 'Achievement #%d', (int) $row['achievement_id']);
            }
            $details[] = $title . ' (+' . number_format((int) $row['tokens']) . ')';
        }
        if ($count > 5) {
            $details[] = translate('account_achievement_and_more', '…and %d more.', $count - 5);
        }

        $parts[] = trim($summary . ' ' . implode(' ', $details));
    }

    $statGranted = $notice['stat_granted'] ?? [];
    $statTokens = (int) ($notice['stat_tokens_added'] ?? 0);
    if ($statTokens > 0 && $statGranted !== []) {
        $statText = sahtout_format_character_stat_reward_notice([
            'tokens_added' => $statTokens,
            'granted' => $statGranted,
        ]);
        if ($statText !== '') {
            $parts[] = $statText;
        }
    }

    $giftGranted = $notice['token_gift_granted'] ?? [];
    $giftTokens = (int) ($notice['token_gift_tokens_added'] ?? 0);
    if ($giftTokens > 0 && $giftGranted !== []) {
        $giftText = sahtout_format_token_gift_reward_notice($giftGranted, $giftTokens);
        if ($giftText !== '') {
            $parts[] = $giftText;
        }
    }

    return implode(' ', $parts);
}
