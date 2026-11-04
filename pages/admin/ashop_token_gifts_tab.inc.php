<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}
/** @var bool $tokenGiftsReady */
/** @var list<array<string, mixed>> $allTokenGifts */
/** @var string $rewardsFormAction */
/** @var bool $canPublishTokenGifts */
/** @var list<int> $tokenGiftChestLootIds */
?>
<div id="token-gifts-panel" class="space-y-4 md:space-y-6 scroll-mt-24">
    <div class="wow-panel p-4 md:p-6 lg:p-8">
        <h2 class="section-title text-lg md:text-xl mb-2"><?php echo translate('admin_token_gifts_heading', 'Token gift letters'); ?></h2>
        <p class="text-gray-400 text-sm mb-6 max-w-3xl"><?php echo translate('admin_token_gifts_help', 'Create sealed-letter promos: publish an item and auto-complete quest to the world, optionally drop the letter into battered chest loot pools (site setting Token Gift Loot IDs), and grant shop tokens once per account when any character completes the quest.'); ?></p>

        <?php if (!$tokenGiftsReady): ?>
            <p class="text-amber-300 text-sm"><?php echo translate('admin_token_gifts_table_missing', 'Run token_gifts.sql on the website database first.'); ?></p>
        <?php else: ?>

        <?php if ($tokenGiftChestLootIds !== []): ?>
            <p class="text-xs text-gray-500 mb-4">
                <?php echo translate('admin_token_gifts_chest_ids', 'World chest loot IDs from settings:'); ?>
                <span class="text-gray-400 font-mono"><?php echo htmlspecialchars(implode(', ', $tokenGiftChestLootIds)); ?></span>
            </p>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="grid md:grid-cols-2 gap-4 items-start mb-8 pb-6 border-b border-[rgba(201,162,39,.12)]">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
            <input type="hidden" name="action" value="tg_add">
            <div class="md:col-span-2">
                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_name_new"><?php echo translate('admin_token_gifts_name', 'Promo name / letter title'); ?></label>
                <input type="text" name="tg_name" id="tg_name_new" class="wow-input" maxlength="120" required>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_letter_new"><?php echo translate('admin_token_gifts_letter', 'Letter body (page_text + quest text)'); ?></label>
                <p class="text-xs text-gray-500 mb-1"><?php echo translate('admin_token_gifts_letter_help', 'Shown when the player reads the item in-game (page_text). Token amount is appended automatically.'); ?></p>
                <textarea name="tg_letter" id="tg_letter_new" class="wow-input min-h-[100px]" maxlength="1000"></textarea>
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_tokens_new"><?php echo translate('admin_token_gifts_tokens', 'Shop tokens'); ?></label>
                <input type="number" name="tg_tokens" id="tg_tokens_new" class="wow-input" min="1" max="9999999" required value="10">
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_loot_chance_new"><?php echo translate('admin_token_gifts_loot_chance', 'Loot drop chance (%)'); ?></label>
                <input type="number" name="tg_loot_chance" id="tg_loot_chance_new" class="wow-input" min="0.01" max="100" step="0.01" value="2">
            </div>
            <div class="md:col-span-2">
                <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                    <input type="checkbox" name="tg_deploy_chests" value="1" class="rounded border-gray-600" checked>
                    <?php echo translate('admin_token_gifts_deploy_chests', 'Deploy to world chest loot IDs (site settings)'); ?>
                </label>
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_creature_new"><?php echo translate('admin_token_gifts_creature_entries', 'Extra creature entry IDs (optional)'); ?></label>
                <input type="text" name="tg_creature_entries" id="tg_creature_new" class="wow-input" placeholder="1234, 5678">
            </div>
            <div>
                <label class="block text-sm font-semibold text-[#f2cf5b] mb-2 font-['Cinzel'] tracking-wide" for="tg_go_loot_new"><?php echo translate('admin_token_gifts_go_loot_ids', 'Extra gameobject loot IDs / data1 (optional)'); ?></label>
                <input type="text" name="tg_go_loot_ids" id="tg_go_loot_new" class="wow-input" placeholder="2276">
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="btn-game btn-gold">
                    <i class="fas fa-plus"></i> <?php echo translate('admin_token_gifts_add', 'Add token gift'); ?>
                </button>
            </div>
        </form>

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
                    <form id="<?php echo htmlspecialchars($formId, ENT_QUOTES, 'UTF-8'); ?>" method="post" action="<?php echo htmlspecialchars($rewardsFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="grid md:grid-cols-2 gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                        <input type="hidden" name="gift_id" value="<?php echo $gid; ?>">
                        <div class="md:col-span-2 flex flex-wrap items-center gap-3 justify-between">
                            <span class="text-sm text-[#f2cf5b] font-['Cinzel'] tracking-wide drop-shadow-[0_0_10px_rgba(201,162,39,.25)]">#<?php echo $gid; ?>
                                <?php if ($published): ?>
                                    · <?php echo translate('admin_token_gifts_status_published', 'Published'); ?>
                                    · <?php echo translate('admin_token_gifts_item_quest', 'Item %1$d / Quest %2$d', (int) ($row['item_entry'] ?? 0), (int) ($row['quest_id'] ?? 0)); ?>
                                    <?php if ((int) ($row['page_text_id'] ?? 0) > 0): ?>
                                        · <?php echo translate('admin_token_gifts_page_text', 'PageText %d', (int) $row['page_text_id']); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    · <?php echo translate('admin_token_gifts_status_draft', 'Draft (not in world)'); ?>
                                <?php endif; ?>
                            </span>
                            <label class="inline-flex items-center gap-2 text-sm text-gray-400">
                                <input type="checkbox" name="tg_enabled" value="1" class="rounded border-gray-600" <?php echo (int) ($row['is_enabled'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                <?php echo translate('admin_rewards_enable_label', 'Enabled'); ?>
                            </label>
                        </div>
                        <div class="md:col-span-2">
                            <label class="sr-only" for="<?php echo $formId; ?>-name"><?php echo translate('admin_token_gifts_name', 'Promo name'); ?></label>
                            <input type="text" id="<?php echo $formId; ?>-name" name="tg_name" class="wow-input" maxlength="120" required value="<?php echo htmlspecialchars((string) ($row['name'] ?? '')); ?>">
                        </div>
                        <div class="md:col-span-2">
                            <label class="sr-only" for="<?php echo $formId; ?>-letter"><?php echo translate('admin_token_gifts_letter', 'Letter body'); ?></label>
                            <textarea id="<?php echo $formId; ?>-letter" name="tg_letter" class="wow-input min-h-[80px]" maxlength="1000"><?php echo htmlspecialchars((string) ($row['letter_text'] ?? '')); ?></textarea>
                        </div>
                        <div>
                            <label class="sr-only" for="<?php echo $formId; ?>-tokens"><?php echo translate('admin_token_gifts_tokens', 'Tokens'); ?></label>
                            <input type="number" id="<?php echo $formId; ?>-tokens" name="tg_tokens" class="wow-input" min="0" max="9999999" value="<?php echo (int) ($row['tokens'] ?? 0); ?>">
                        </div>
                        <div>
                            <label class="sr-only" for="<?php echo $formId; ?>-chance"><?php echo translate('admin_token_gifts_loot_chance', 'Loot chance'); ?></label>
                            <input type="number" id="<?php echo $formId; ?>-chance" name="tg_loot_chance" class="wow-input" min="0.01" max="100" step="0.01" value="<?php echo htmlspecialchars((string) ($row['loot_chance'] ?? '2')); ?>">
                        </div>
                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                                <input type="checkbox" name="tg_deploy_chests" value="1" class="rounded border-gray-600" <?php echo (int) ($row['deploy_world_chests'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                <?php echo translate('admin_token_gifts_deploy_chests', 'Deploy to world chest loot IDs (site settings)'); ?>
                            </label>
                        </div>
                        <div>
                            <input type="text" name="tg_creature_entries" class="wow-input text-sm" placeholder="<?php echo htmlspecialchars(translate('admin_token_gifts_creature_entries', 'Creature IDs')); ?>" value="<?php echo htmlspecialchars((string) ($row['extra_creature_entries'] ?? '')); ?>">
                        </div>
                        <div>
                            <input type="text" name="tg_go_loot_ids" class="wow-input text-sm" placeholder="<?php echo htmlspecialchars(translate('admin_token_gifts_go_loot_ids', 'GO loot IDs')); ?>" value="<?php echo htmlspecialchars((string) ($row['extra_go_loot_ids'] ?? '')); ?>">
                        </div>
                        <div class="md:col-span-2 flex flex-wrap gap-2 pt-2 items-center action-buttons">
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
                                <button type="submit" name="action" value="tg_redeploy_loot" class="btn-game btn-edit-action text-xs py-2 px-3">
                                    <i class="fas fa-dice" aria-hidden="true"></i>
                                    <?php echo translate('admin_token_gifts_redeploy_loot', 'Redeploy loot'); ?>
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

        <?php endif; ?>
    </div>
</div>
