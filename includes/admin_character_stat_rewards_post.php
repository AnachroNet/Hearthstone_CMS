<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_character_stat_rewards.php';

function sahtout_admin_shop_is_stat_rewards_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    $action = (string) ($_POST['action'] ?? '');

    return in_array($action, ['stat_add', 'stat_update', 'stat_delete'], true);
}

/**
 * @return array{redirect: ?string, flash: string}
 */
function sahtout_admin_shop_stat_rewards_handle_post(mysqli $siteDb, string $basePath): array
{
    $rewardsUrl = $basePath . 'admin/ashop?tab=rewards';
    if (!sahtout_character_stat_rewards_table_exists($siteDb)) {
        return [
            'redirect' => null,
            'flash' => 'error:' . translate(
                'admin_stat_rewards_table_missing',
                'Run character_stat_token_rewards.sql on the website database first.'
            ),
        ];
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        return ['redirect' => null, 'flash' => 'error:' . translate('error_csrf_invalid', 'Invalid CSRF token.')];
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'stat_add') {
        $statType = (string) ($_POST['stat_type'] ?? '');
        $threshold = (int) ($_POST['stat_threshold'] ?? 0);
        $tokens = (int) ($_POST['stat_tokens'] ?? 0);
        $result = sahtout_admin_add_character_stat_reward($siteDb, $statType, $threshold, $tokens);
        if (!$result['ok']) {
            $msg = match ($result['message'] ?? '') {
                'duplicate' => translate('admin_stat_rewards_duplicate', 'That stat type and threshold already exists.'),
                'invalid_tokens' => translate('admin_stat_rewards_invalid_tokens', 'Enter a token amount between 1 and 9,999,999.'),
                default => translate('admin_stat_rewards_add_failed', 'Could not add character stat reward.'),
            };

            return ['redirect' => null, 'flash' => 'error:' . $msg];
        }

        return ['redirect' => $rewardsUrl . '&stat_status=added', 'flash' => ''];
    }

    if ($action === 'stat_update') {
        $rewardId = (int) ($_POST['reward_id'] ?? 0);
        $tokens = (int) ($_POST['tokens'] ?? 0);
        $isEnabled = isset($_POST['is_enabled']);
        $result = sahtout_admin_update_character_stat_reward($siteDb, $rewardId, $tokens, $isEnabled);
        if (!$result['ok']) {
            return [
                'redirect' => null,
                'flash' => 'error:' . translate('admin_stat_rewards_save_failed', 'Could not save character stat reward.'),
            ];
        }

        return ['redirect' => $rewardsUrl . '&stat_status=updated', 'flash' => ''];
    }

    if ($action === 'stat_delete') {
        $rewardId = (int) ($_POST['reward_id'] ?? 0);
        $result = sahtout_admin_delete_character_stat_reward($siteDb, $rewardId);
        if (!$result['ok']) {
            return [
                'redirect' => null,
                'flash' => 'error:' . translate('admin_stat_rewards_delete_failed', 'Could not delete character stat reward.'),
            ];
        }

        return ['redirect' => $rewardsUrl . '&stat_status=deleted', 'flash' => ''];
    }

    return ['redirect' => null, 'flash' => ''];
}

function sahtout_admin_shop_stat_rewards_status_flash_from_get(): string
{
    if (!isset($_GET['stat_status'])) {
        return '';
    }

    return match ((string) $_GET['stat_status']) {
        'added' => 'success:' . translate('admin_stat_rewards_added', 'Character stat milestone added.'),
        'updated' => 'success:' . translate('admin_stat_rewards_updated', 'Character stat milestone saved.'),
        'deleted' => 'success:' . translate('admin_stat_rewards_deleted', 'Character stat milestone deleted.'),
        default => '',
    };
}
