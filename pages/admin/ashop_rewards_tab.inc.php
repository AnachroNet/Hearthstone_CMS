<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

/** @var string $rewardsFormAction */
/** @var string $rewardsSearchUrl */
/** @var string $rewards_alert_html */
/** @var bool $rewardsReady */
/** @var bool $catalogReady */
/** @var list<array<string, mixed>> $allRewards */
/** @var array<int, string> $rewardCreatureNames */
/** @var array<string, string> $milestoneLabels */
?>
<p class="text-gray-400 text-sm"><?php echo translate('admin_rewards_help', 'Link in-game achievements to website shop tokens. After import, set token amounts and enable each row. Login-time granting can be added later.'); ?></p>

<?php echo $rewards_alert_html; ?>

<?php if (!$rewardsReady): ?>
    <div class="panel p-4 md:p-6 lg:p-8 text-amber-300 border border-amber-500/30">
        <?php echo translate('admin_rewards_table_missing', 'Run achievement_catalog.sql on the site database first.'); ?>
    </div>
<?php else: ?>

<?php if (!$catalogReady): ?>
    <div class="panel p-4 text-amber-300 text-sm border border-amber-500/30">
        <?php echo translate('admin_rewards_catalog_missing', 'Import achievement_catalog data to search achievements when adding rows.'); ?>
    </div>
<?php endif; ?>

<div class="panel p-4 md:p-6 lg:p-8">
    <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6"><?php echo translate('admin_rewards_add', 'Add reward'); ?></h2>
    <form method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="space-y-4" id="addRewardForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="achievement_id" id="achievement_id" value="0">

        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="form-label" for="milestone_type"><?php echo translate('admin_rewards_col_type', 'Milestone type'); ?></label>
                <select name="milestone_type" id="milestone_type" class="input-dark" required>
                    <option value="level"><?php echo htmlspecialchars($milestoneLabels['level']); ?></option>
                    <option value="quest_count"><?php echo htmlspecialchars($milestoneLabels['quest_count']); ?></option>
                    <option value="dungeon"><?php echo htmlspecialchars($milestoneLabels['dungeon']); ?></option>
                    <option value="other"><?php echo htmlspecialchars($milestoneLabels['manual']); ?></option>
                </select>
            </div>
            <div id="milestoneValueWrap">
                <label class="form-label" for="milestone_value"><?php echo translate('admin_rewards_milestone_value', 'Level or quest count'); ?></label>
                <input type="number" min="1" max="999999" name="milestone_value" id="milestone_value" class="input-dark" placeholder="80">
            </div>
        </div>

        <div id="dungeonNameWrap" class="hidden">
            <label class="form-label" for="dungeon_name"><?php echo translate('admin_rewards_dungeon_name', 'Dungeon name (optional)'); ?></label>
            <input type="text" name="dungeon_name" id="dungeon_name" class="input-dark" maxlength="128" placeholder="Utgarde Keep">
        </div>

        <div class="relative">
            <label class="form-label" for="achievement_search"><?php echo translate('admin_rewards_achievement', 'Achievement'); ?></label>
            <input type="text" id="achievement_search" class="input-dark" autocomplete="off"
                   placeholder="<?php echo htmlspecialchars(translate('admin_rewards_search_placeholder', 'Search by title or achievement ID…')); ?>"
                   <?php echo $catalogReady ? '' : 'disabled'; ?>>
            <p id="achievement_picked" class="text-sm text-gray-500 mt-1"></p>
            <div id="achievement_results" class="ach-search-results"></div>
        </div>

        <button type="submit" class="btn-gold" id="addRewardBtn" disabled>
            <i class="fas fa-plus"></i> <?php echo translate('admin_rewards_create', 'Add reward row'); ?>
        </button>
    </form>
</div>

<div class="panel p-4 md:p-6 lg:p-8 overflow-x-auto">
    <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6"><?php echo translate('admin_rewards_list', 'Configured rewards'); ?></h2>
    <?php if ($allRewards === []): ?>
        <p class="text-gray-500"><?php echo translate('admin_rewards_empty', 'No reward rows yet. Run the DBC import or add one above.'); ?></p>
    <?php else: ?>
    <table class="table-dark">
        <thead>
            <tr>
                <th><?php echo translate('admin_rewards_col_achievement', 'Achievement'); ?></th>
                <th><?php echo translate('admin_rewards_col_type', 'Type'); ?></th>
                <th><?php echo translate('admin_rewards_col_detail', 'Detail'); ?></th>
                <th><?php echo translate('admin_rewards_col_tokens', 'Tokens'); ?></th>
                <th><?php echo translate('admin_rewards_col_enabled', 'Enabled'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allRewards as $row):
                $achId = (int) $row['achievement_id'];
                $type = (string) ($row['milestone_type'] ?? 'manual');
                $title = (string) ($row['title_enus'] ?? '');
                if ($title === '') {
                    $title = translate('admin_rewards_unknown_title', 'Achievement #%d', $achId);
                }
                $achDescription = trim((string) ($row['description_enus'] ?? ''));
                $rewardEnabled = sahtout_admin_reward_is_enabled($row);
            ?>
            <tr>
                <td>
                    <div class="font-semibold text-white"><?php echo htmlspecialchars($title); ?></div>
                    <?php if ($achDescription !== ''): ?>
                        <div class="text-xs text-gray-400 mt-1 max-w-xl"><?php echo htmlspecialchars($achDescription); ?></div>
                    <?php endif; ?>
                    <div class="text-xs text-gray-500 mt-1">ID <?php echo $achId; ?></div>
                </td>
                <td>
                    <span class="type-pill"><?php echo htmlspecialchars($milestoneLabels[$type] ?? $type); ?></span>
                    <?php
                    $typeSubtitle = sahtout_admin_milestone_type_subtitle($row);
                    if ($typeSubtitle !== null && $typeSubtitle !== ''):
                    ?>
                        <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($typeSubtitle); ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-gray-300 text-sm"><?php echo htmlspecialchars(sahtout_admin_reward_detail_label($row, $rewardCreatureNames)); ?></td>
                <td colspan="3">
                    <form method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="flex flex-wrap items-end gap-3 justify-end">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="achievement_id" value="<?php echo $achId; ?>">
                        <div class="w-28">
                            <label class="sr-only"><?php echo translate('admin_rewards_col_tokens', 'Tokens'); ?></label>
                            <input type="number" name="tokens" class="input-dark text-sm py-2" min="0" max="9999999"
                                   value="<?php echo (int) ($row['tokens'] ?? 0); ?>">
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer" title="<?php echo htmlspecialchars(translate('admin_rewards_enable_hint', 'When checked, this reward is active for account-wide sync.')); ?>">
                            <input type="checkbox" name="is_enabled" value="1" class="rounded border-gray-600"
                                <?php echo $rewardEnabled ? 'checked' : ''; ?>>
                            <span><?php echo translate('admin_rewards_enable_label', 'Enabled'); ?></span>
                            <?php if (!$rewardEnabled): ?>
                                <span class="text-gray-500">(<?php echo translate('admin_rewards_off', 'off'); ?>)</span>
                            <?php endif; ?>
                        </label>
                        <button type="submit" class="btn-gold btn-iron-sm text-xs py-2 px-3">
                            <?php echo translate('admin_rewards_save', 'Save'); ?>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>
