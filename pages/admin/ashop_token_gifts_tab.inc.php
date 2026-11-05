<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}
/** @var bool $tokenGiftsReady */
/** @var list<array<string, mixed>> $allTokenGifts */
/** @var string $rewardsFormAction */
/** @var bool $canPublishTokenGifts */
/** @var int $tokenGiftCount */
/** @var bool $tokenGiftCanAddNew */
/** @var string $tokenGiftsPanel */
/** @var string $base_path */

$tokenGiftsSubtabPersist = ['tab' => 'rewards', 'subtab' => 'token_gifts'];
?>
<div id="token-gifts-panel" class="space-y-4 md:space-y-6 scroll-mt-24">
    <div class="wow-panel p-4 md:p-6 lg:p-8">
        <h2 class="section-title text-lg md:text-xl mb-2"><?php echo translate('admin_token_gifts_heading', 'Token gift letters'); ?></h2>
        <p class="text-gray-400 text-sm mb-4 w-full"><?php echo translate(
            'admin_token_gifts_help',
            'Create sealed-letter promos to be distributed in the World. Item and quest IDs come from your General Settings list (10 configured slots; display icon 1102). Publish picks the first free ID with no world rows and no other promo using it.'
        ); ?></p>
        <p class="text-amber-200/90 text-sm mb-6 w-full border border-amber-500/25 bg-amber-950/20 rounded-sm px-3 py-2"><?php echo translate(
            'admin_token_gifts_world_restart_notice',
            'IMPORTANT: New items in the database may only be loaded when the World server restarts. Changes to existing items may be made live by reloading some data sets. This tool will attempt to do as much as possible to update your data, but a World server restart may be necessary.'
        ); ?></p>

        <?php if (!$tokenGiftsReady): ?>
            <p class="text-amber-300 text-sm"><?php echo translate('admin_token_gifts_table_missing', 'Run token_gifts.sql on the website database first.'); ?></p>
        <?php else: ?>

        <?php
        sahtout_admin_render_subtab_nav(
            [
                ['id' => 'active', 'label' => translate('admin_token_gifts_subtab_active', 'Active Promos'), 'icon' => 'fas fa-list'],
                ['id' => 'add', 'label' => translate('admin_token_gifts_subtab_add', 'Add New Promo'), 'icon' => 'fas fa-plus'],
            ],
            $tokenGiftsPanel,
            $base_path,
            'admin/ashop',
            $tokenGiftsSubtabPersist,
            translate('admin_token_gifts_heading', 'Token gift letters'),
            'tg_panel'
        );
        ?>

        <div class="<?php echo sahtout_admin_subtab_panel_class('active', $tokenGiftsPanel, 'space-y-6'); ?>">
            <?php if ($allTokenGifts === []): ?>
                <p class="text-gray-500"><?php echo translate('admin_token_gifts_empty', 'No token gifts yet.'); ?></p>
            <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($allTokenGifts as $row):
                        $gid = (int) ($row['id'] ?? 0);
                        $published = !empty($row['published_at']);
                        $formId = 'tg-form-' . $gid;
                    ?>
                    <div class="border border-[rgba(201,162,39,.15)] rounded-sm p-4 md:p-5 bg-[#0a0e16]/40">
                        <form id="<?php echo htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'); ?>" method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="grid grid-cols-1 gap-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                            <input type="hidden" name="gift_id" value="<?php echo $gid; ?>">
                            <div class="flex flex-wrap items-center gap-3 justify-between">
                                <span class="text-sm text-[#f2cf5b] font-['Cinzel'] tracking-wide drop-shadow-[0_0_10px_rgba(201,162,39,.25)]">#<?php echo $gid; ?>
                                    <?php if ($published): ?>
                                        · <?php echo translate('admin_token_gifts_status_published', 'Published'); ?>
                                        · <?php echo translate('admin_token_gifts_item_quest', 'Item %1$d / Quest %2$d', (int) ($row['item_entry'] ?? 0), (int) ($row['quest_id'] ?? 0)); ?>
                                    <?php else: ?>
                                        · <?php echo translate('admin_token_gifts_status_draft', 'Draft (not in world)'); ?>
                                    <?php endif; ?>
                                </span>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-400">
                                    <input type="checkbox" name="tg_enabled" value="1" class="rounded border-gray-600" <?php echo (int) ($row['is_enabled'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                    <?php echo translate('admin_rewards_enable_label', 'Enabled'); ?>
                                </label>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="<?php echo $formId; ?>-name"><?php echo translate('admin_token_gifts_name', 'Promo name / letter title'); ?></label>
                                <input type="text" id="<?php echo $formId; ?>-name" name="tg_name" class="wow-input w-full" maxlength="120" required value="<?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="<?php echo $formId; ?>-letter"><?php echo translate('admin_token_gifts_letter', 'Letter body (quest dialog)'); ?></label>
                                <textarea id="<?php echo $formId; ?>-letter" name="tg_letter" class="wow-input w-full min-h-[120px]" maxlength="1000"><?php echo htmlspecialchars((string) ($row['letter_text'] ?? '')); ?></textarea>
                            </div>
                            <div class="grid sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="<?php echo $formId; ?>-tokens"><?php echo translate('admin_token_gifts_tokens', 'Shop tokens'); ?></label>
                                    <input type="number" id="<?php echo $formId; ?>-tokens" name="tg_tokens" class="wow-input w-full" min="0" max="9999999" value="<?php echo (int) ($row['tokens'] ?? 0); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="<?php echo $formId; ?>-chance"><?php echo translate('admin_token_gifts_loot_chance', 'Loot drop chance (%)'); ?></label>
                                    <input type="number" id="<?php echo $formId; ?>-chance" name="tg_loot_chance" class="wow-input w-full" min="0.01" max="100" step="0.01" value="<?php echo htmlspecialchars((string) ($row['loot_chance'] ?? '2')); ?>">
                                </div>
                            </div>
                            <div>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                                    <input type="checkbox" name="tg_deploy_chests" value="1" class="rounded border-gray-600" <?php echo (int) ($row['deploy_world_chests'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                    <?php echo translate('admin_token_gifts_deploy_chests', 'Deploy to world chest loot IDs (site settings)'); ?>
                                </label>
                            </div>
                            <div class="grid sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="<?php echo $formId; ?>-creature"><?php echo translate('admin_token_gifts_creature_entries', 'Extra creature entry IDs (optional)'); ?></label>
                                    <input type="text" id="<?php echo $formId; ?>-creature" name="tg_creature_entries" class="wow-input w-full text-sm" placeholder="1234, 5678" value="<?php echo htmlspecialchars((string) ($row['extra_creature_entries'] ?? '')); ?>">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="<?php echo $formId; ?>-go"><?php echo translate('admin_token_gifts_go_loot_ids', 'Extra gameobject loot IDs / data1 (optional)'); ?></label>
                                    <input type="text" id="<?php echo $formId; ?>-go" name="tg_go_loot_ids" class="wow-input w-full text-sm" placeholder="2276" value="<?php echo htmlspecialchars((string) ($row['extra_go_loot_ids'] ?? '')); ?>">
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2 pt-2 items-center action-buttons">
                                <button type="submit" name="action" value="tg_update" class="btn-game btn-gold text-xs py-2 px-3">
                                    <i class="fas fa-save" aria-hidden="true"></i>
                                    <?php echo translate('admin_rewards_save', 'Save'); ?>
                                </button>
                                <?php if ($canPublishTokenGifts): ?>
                                    <?php if (!$published): ?>
                                    <button type="submit" name="action" value="tg_publish" class="btn-game btn-success text-xs py-2 px-3">
                                        <i class="fas fa-globe-americas" aria-hidden="true"></i>
                                        <?php echo translate('admin_token_gifts_publish', 'Publish to world'); ?>
                                    </button>
                                    <?php else: ?>
                                    <button type="submit" name="action" value="tg_publish" class="btn-game btn-success text-xs py-2 px-3">
                                        <i class="fas fa-globe-americas" aria-hidden="true"></i>
                                        <?php echo translate('admin_token_gifts_republish', 'Republish to world'); ?>
                                    </button>
                                    <?php endif; ?>
                                    <button type="submit" name="action" value="tg_delete" class="btn-game btn-danger text-xs py-2 px-3"
                                        onclick="return confirm(<?php echo json_encode(translate('admin_token_gifts_delete_confirm', 'Delete this token gift and remove its loot rows?')); ?>);">
                                        <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                        <?php echo translate('admin_token_gifts_delete', 'Delete'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="<?php echo sahtout_admin_subtab_panel_class('add', $tokenGiftsPanel, 'space-y-6'); ?>">
            <?php if (!$tokenGiftCanAddNew): ?>
                <div class="border-2 border-amber-500/40 bg-amber-950/30 rounded-sm px-4 py-5 text-center">
                    <p class="text-amber-100 text-base font-semibold font-['Cinzel'] tracking-wide mb-2">
                        <?php echo translate(
                            'admin_token_gifts_all_configured',
                            'All available promos have been configured.'
                        ); ?>
                    </p>
                    <p class="text-amber-200/80 text-sm max-w-2xl mx-auto">
                        <?php echo translate(
                            'admin_token_gifts_world_id_slots_exhausted',
                            'No free world IDs left in the configured range (max %3$d promos). Delete an unused gift or free a slot in item_template / quest_template.',
                            sahtout_token_gift_world_id_first(),
                            sahtout_token_gift_world_id_last(),
                            sahtout_token_gift_max_promos()
                        ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <fieldset <?php echo $tokenGiftCanAddNew ? '' : 'disabled'; ?> class="border-0 p-0 m-0 min-w-0<?php echo $tokenGiftCanAddNew ? '' : ' opacity-50'; ?>">
                <form method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="grid grid-cols-1 gap-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                    <input type="hidden" name="action" value="tg_add">
                    <div>
                        <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_name_new"><?php echo translate('admin_token_gifts_name', 'Promo name / letter title'); ?></label>
                        <input type="text" name="tg_name" id="tg_name_new" class="wow-input w-full" maxlength="120" required>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_letter_new"><?php echo translate('admin_token_gifts_letter', 'Letter body (quest dialog)'); ?></label>
                        <textarea name="tg_letter" id="tg_letter_new" class="wow-input w-full min-h-[140px]" maxlength="1000"></textarea>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_tokens_new"><?php echo translate('admin_token_gifts_tokens', 'Shop tokens'); ?></label>
                            <input type="number" name="tg_tokens" id="tg_tokens_new" class="wow-input w-full" min="1" max="9999999" required value="1">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_loot_chance_new"><?php echo translate('admin_token_gifts_loot_chance', 'Loot drop chance (%)'); ?></label>
                            <input type="number" name="tg_loot_chance" id="tg_loot_chance_new" class="wow-input w-full" min="0.01" max="100" step="0.01" value="2">
                        </div>
                    </div>
                    <div>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                            <input type="checkbox" name="tg_deploy_chests" value="1" class="rounded border-gray-600" checked>
                            <?php echo translate('admin_token_gifts_deploy_chests', 'Deploy to world chest loot IDs (site settings)'); ?>
                        </label>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_creature_new"><?php echo translate('admin_token_gifts_creature_entries', 'Extra creature entry IDs (optional)'); ?></label>
                            <input type="text" name="tg_creature_entries" id="tg_creature_new" class="wow-input w-full" placeholder="1234, 5678">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_go_loot_new"><?php echo translate('admin_token_gifts_go_loot_ids', 'Extra gameobject loot IDs / data1 (optional)'); ?></label>
                            <input type="text" name="tg_go_loot_ids" id="tg_go_loot_new" class="wow-input w-full" placeholder="2276">
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="btn-game btn-gold" <?php echo $tokenGiftCanAddNew ? '' : 'disabled'; ?>>
                            <i class="fas fa-plus"></i> <?php echo translate('admin_token_gifts_add', 'Add token gift'); ?>
                        </button>
                    </div>
                </form>
            </fieldset>
        </div>

        <?php endif; ?>
    </div>
</div>
