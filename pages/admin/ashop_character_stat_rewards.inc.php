<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}
/** @var bool $statRewardsReady */
/** @var list<array<string, mixed>> $allStatRewards */
/** @var string $rewardsFormAction */
/** @var array<string, string> $statTypeLabels */
?>
<div id="character-stat-rewards-panel" class="wow-panel p-4 md:p-6 lg:p-8 scroll-mt-24">
    <h2 class="section-title text-lg md:text-xl mb-2"><?php echo translate('admin_stat_rewards_heading', 'Character stat milestones'); ?></h2>
    <p class="text-gray-400 text-sm mb-6 max-w-3xl"><?php echo translate('admin_stat_rewards_help', 'Award shop tokens when any character on the account reaches a lifetime statistic. Values use the best character on the account (same sources as the armory lifetime panel). Grants run on login and while browsing the account area.'); ?></p>

    <?php if (!$statRewardsReady): ?>
        <p class="text-amber-300 text-sm"><?php echo translate('admin_stat_rewards_table_missing', 'Run character_stat_token_rewards.sql on the website database first.'); ?></p>
    <?php else: ?>

    <form method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="grid md:grid-cols-4 gap-4 items-end mb-8 pb-6 border-b border-[rgba(201,162,39,.12)]">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
        <input type="hidden" name="action" value="stat_add">
        <div>
            <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="stat_type"><?php echo translate('admin_stat_rewards_col_type', 'Stat'); ?></label>
            <select name="stat_type" id="stat_type" class="wow-input" required>
                <?php foreach ($statTypeLabels as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="stat_threshold"><?php echo translate('admin_stat_rewards_threshold', 'Threshold'); ?></label>
            <input type="number" name="stat_threshold" id="stat_threshold" class="wow-input" min="1" max="999999999" required placeholder="100">
        </div>
        <div>
            <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="stat_tokens"><?php echo translate('admin_stat_rewards_tokens', 'Tokens'); ?></label>
            <input type="number" name="stat_tokens" id="stat_tokens" class="wow-input" min="1" max="9999999" required placeholder="10">
        </div>
        <div>
            <button type="submit" class="btn-game btn-gold w-full md:w-auto">
                <i class="fas fa-plus"></i> <?php echo translate('admin_stat_rewards_add', 'Add milestone'); ?>
            </button>
        </div>
    </form>

    <?php if ($allStatRewards === []): ?>
        <p class="text-gray-500"><?php echo translate('admin_stat_rewards_empty', 'No character stat milestones configured yet.'); ?></p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="wow-table min-w-[640px]">
                <thead>
                    <tr>
                        <th><?php echo translate('admin_stat_rewards_col_type', 'Stat'); ?></th>
                        <th class="col-num"><?php echo translate('admin_stat_rewards_threshold', 'Threshold'); ?></th>
                        <th class="col-num"><?php echo translate('admin_stat_rewards_tokens', 'Tokens'); ?></th>
                        <th class="align-center"><?php echo translate('admin_rewards_col_enabled', 'Enabled'); ?></th>
                        <th class="col-actions align-right"><?php echo translate('admin_rewards_col_actions', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allStatRewards as $row):
                        $rid = (int) ($row['id'] ?? 0);
                        $stype = (string) ($row['stat_type'] ?? '');
                        $enabled = (int) ($row['is_enabled'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td><span class="type-pill"><?php echo htmlspecialchars($statTypeLabels[$stype] ?? $stype); ?></span></td>
                        <td class="col-num text-gray-200"><?php echo number_format((int) ($row['threshold'] ?? 0)); ?></td>
                        <?php $statUpdateFormId = 'stat-reward-update-' . $rid; ?>
                        <td class="col-num">
                            <form id="<?php echo htmlspecialchars($statUpdateFormId, ENT_QUOTES, 'UTF-8'); ?>" method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" hidden>
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                                <input type="hidden" name="action" value="stat_update">
                                <input type="hidden" name="reward_id" value="<?php echo $rid; ?>">
                            </form>
                            <label class="sr-only" for="<?php echo htmlspecialchars($statUpdateFormId . '-tokens', ENT_QUOTES, 'UTF-8'); ?>"><?php echo translate('admin_stat_rewards_tokens', 'Tokens'); ?></label>
                            <input type="number" id="<?php echo htmlspecialchars($statUpdateFormId . '-tokens', ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo htmlspecialchars($statUpdateFormId, ENT_QUOTES, 'UTF-8'); ?>" name="tokens" class="wow-input text-sm py-2 w-full max-w-[7rem] block" min="0" max="9999999" value="<?php echo (int) ($row['tokens'] ?? 0); ?>">
                        </td>
                        <td class="align-center">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-400 cursor-pointer whitespace-nowrap">
                                <input type="checkbox" form="<?php echo htmlspecialchars($statUpdateFormId, ENT_QUOTES, 'UTF-8'); ?>" name="is_enabled" value="1" class="rounded border-gray-600 shrink-0" <?php echo $enabled ? 'checked' : ''; ?>>
                                <?php echo translate('admin_rewards_enable_label', 'Enabled'); ?>
                            </label>
                        </td>
                        <td class="actions-cell">
                            <div class="action-buttons action-buttons--stack">
                                <button type="submit" form="<?php echo htmlspecialchars($statUpdateFormId, ENT_QUOTES, 'UTF-8'); ?>" class="btn-game btn-gold text-xs py-2 px-3"><?php echo translate('admin_rewards_save', 'Save'); ?></button>
                                <form method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="inline"
                                      onsubmit="return confirm(<?php echo json_encode(translate('admin_stat_rewards_delete_confirm', 'Delete this character stat milestone?')); ?>);">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                                    <input type="hidden" name="action" value="stat_delete">
                                    <input type="hidden" name="reward_id" value="<?php echo $rid; ?>">
                                    <button type="submit" class="text-xs text-red-400 hover:text-red-300"><?php echo translate('admin_stat_rewards_delete', 'Delete'); ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
