<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

function sahtout_admin_shop_rewards_tab_url(string $basePath, array $queryExtra = []): string
{
    if (!isset($queryExtra['subtab'])) {
        $queryExtra['subtab'] = 'configured';
    }
    $params = array_merge(['tab' => 'rewards'], $queryExtra);
    $rewardPage = (int) ($_POST['reward_page'] ?? $_GET['reward_page'] ?? 0);
    if ($rewardPage > 1 && ($params['subtab'] ?? '') === 'configured') {
        $params['reward_page'] = $rewardPage;
    }

    return $basePath . 'admin/ashop?' . http_build_query($params);
}

function sahtout_admin_shop_is_rewards_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update' && isset($_POST['achievement_id'])) {
        return true;
    }

    return $action === 'add'
        && isset($_POST['milestone_type'], $_POST['achievement_id'])
        && !isset($_POST['category']);
}

/**
 * @return array{redirect: ?string, flash: string}
 */
function sahtout_admin_shop_rewards_handle_post(mysqli $siteDb, string $basePath, bool $rewardsReady, bool $catalogReady): array
{
    $empty = ['redirect' => null, 'flash' => ''];
    if (!$rewardsReady) {
        return ['redirect' => null, 'flash' => 'error:' . translate('admin_rewards_table_missing', 'Run achievement_catalog.sql on the site database first.')];
    }

    $action = (string) ($_POST['action'] ?? '');
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        return ['redirect' => null, 'flash' => 'error:' . translate('error_csrf_invalid', 'Invalid CSRF token.')];
    }

    if ($action === 'update') {
        $achievementId = (int) ($_POST['achievement_id'] ?? 0);
        $tokens = max(0, min(9999999, (int) ($_POST['tokens'] ?? 0)));
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        if ($achievementId > 0) {
            $stmt = $siteDb->prepare(
                'UPDATE achievement_token_rewards SET tokens = ?, is_enabled = ? WHERE achievement_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('iii', $tokens, $isEnabled, $achievementId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    return ['redirect' => sahtout_admin_shop_rewards_tab_url($basePath, ['subtab' => 'configured', 'reward_status' => 'updated']), 'flash' => ''];
                }
            }
        }

        return ['redirect' => null, 'flash' => 'error:' . translate('admin_rewards_save_failed', 'Could not save changes.')];
    }

    if ($action === 'add' && $catalogReady) {
        $flash = '';
        $achievementId = (int) ($_POST['achievement_id'] ?? 0);
        $milestoneType = sahtout_admin_normalize_milestone_type((string) ($_POST['milestone_type'] ?? ''));
        $milestoneValue = (int) ($_POST['milestone_value'] ?? 0);
        $dungeonName = trim((string) ($_POST['dungeon_name'] ?? ''));

        if ($achievementId <= 0 || $milestoneType === null) {
            return ['redirect' => null, 'flash' => 'error:' . translate('admin_rewards_validation', 'Choose a milestone type and a valid achievement.')];
        }

        $catalog = sahtout_admin_achievement_catalog_row($siteDb, $achievementId);
        if ($catalog === null) {
            return ['redirect' => null, 'flash' => 'error:' . translate('admin_rewards_unknown_achievement', 'That achievement is not in the catalog. Run the DBC import first.')];
        }

        $instanceMapId = null;
        $milestoneValueSql = null;

        if ($milestoneType === 'level' || $milestoneType === 'quest_count') {
            if ($milestoneValue <= 0) {
                $critType = (int) ($catalog['criteria_type'] ?? 0);
                $critVal = (int) ($catalog['criteria_value'] ?? 0);
                $expectedType = $milestoneType === 'level' ? 5 : 9;
                if ($critType === $expectedType && $critVal > 0) {
                    $milestoneValue = $critVal;
                }
            }
            if ($milestoneValue <= 0) {
                return ['redirect' => null, 'flash' => 'error:' . translate('admin_rewards_need_milestone_value', 'Enter a level or quest count for this milestone type.')];
            }
            $milestoneValueSql = $milestoneValue;
        } elseif ($milestoneType === 'dungeon') {
            global $world_db;
            $resolved = sahtout_admin_resolve_catalog_instance_map(
                $siteDb,
                (isset($world_db) && $world_db instanceof mysqli) ? $world_db : null,
                $catalog
            );
            if ($resolved !== null && $resolved > 0) {
                $instanceMapId = $resolved;
            }
        }

        $check = $siteDb->prepare('SELECT achievement_id FROM achievement_token_rewards WHERE achievement_id = ? LIMIT 1');
        $check->bind_param('i', $achievementId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            return ['redirect' => null, 'flash' => 'error:' . translate('admin_rewards_duplicate', 'This achievement already has a reward row.')];
        }

        $tokens = 0;
        $isEnabled = 0;
        $milestoneValueParam = ($milestoneType === 'level' || $milestoneType === 'quest_count')
            ? (int) $milestoneValueSql
            : null;
        $instanceMapIdParam = ($milestoneType === 'dungeon' && $instanceMapId !== null && $instanceMapId > 0)
            ? (int) $instanceMapId
            : null;
        $dungeonNameParam = ($milestoneType === 'dungeon' && $dungeonName !== '')
            ? $dungeonName
            : null;
        $dungeonMinParam = null;
        $dungeonMaxParam = null;
        $dungeonTargetParam = null;
        if ($milestoneType === 'dungeon' && $instanceMapIdParam !== null) {
            $lfgLevels = sahtout_lfg_dungeon_levels_for_map((int) $instanceMapIdParam);
            if ($lfgLevels !== null) {
                $dungeonMinParam = ($lfgLevels['min'] ?? 0) > 0 ? (int) $lfgLevels['min'] : null;
                $dungeonMaxParam = ($lfgLevels['max'] ?? 0) > 0 ? (int) $lfgLevels['max'] : null;
                $dungeonTargetParam = ($lfgLevels['target'] ?? 0) > 0 ? (int) $lfgLevels['target'] : null;
            }
        }

        $stmt = $siteDb->prepare(
            'INSERT INTO achievement_token_rewards
             (achievement_id, tokens, milestone_type, milestone_value, instance_map_id, dungeon_name,
              dungeon_min_level, dungeon_max_level, dungeon_target_level, is_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return ['redirect' => null, 'flash' => 'error:' . translate('admin_rewards_save_failed', 'Could not save changes.')];
        }
        $stmt->bind_param(
            'iisiisiiii',
            $achievementId,
            $tokens,
            $milestoneType,
            $milestoneValueParam,
            $instanceMapIdParam,
            $dungeonNameParam,
            $dungeonMinParam,
            $dungeonMaxParam,
            $dungeonTargetParam,
            $isEnabled
        );
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            return ['redirect' => sahtout_admin_shop_rewards_tab_url($basePath, ['subtab' => 'configured', 'reward_status' => 'added']), 'flash' => ''];
        }

        return ['redirect' => null, 'flash' => 'error:' . translate('admin_rewards_save_failed', 'Could not save changes.')];
    }

    return $empty;
}

function sahtout_admin_shop_rewards_flash_html(string $flash): string
{
    if ($flash === '') {
        return '';
    }
    [$type, $msg] = explode(':', $flash, 2);

    return $type === 'success'
        ? '<div class="bg-green-900/40 border border-green-500/50 text-green-300 px-4 py-3 rounded-sm mb-6">' . htmlspecialchars($msg) . '</div>'
        : '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6">' . htmlspecialchars($msg) . '</div>';
}

function sahtout_admin_shop_rewards_status_flash_from_get(): string
{
    if (!isset($_GET['reward_status'])) {
        return '';
    }

    return match ((string) $_GET['reward_status']) {
        'added' => 'success:' . translate('admin_rewards_added', 'Reward row added.'),
        'updated' => 'success:' . translate('admin_rewards_updated', 'Reward settings saved.'),
        default => 'success:' . translate('admin_rewards_saved', 'Changes saved.'),
    };
}
