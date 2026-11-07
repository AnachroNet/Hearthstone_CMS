<?php
if (!defined('ALLOWED_ACCESS')) {
    exit;
}
/** @var array $inquiryData */
/** @var list<array{account_id: int, username: string, tokens: int}> $inquiryAccountOptions */
/** @var string $base_path */
/** @var string $servicesTab */
?>
<div id="user-services-inquiry-add-tab" class="tab-content <?php echo ($servicesTab ?? '') === 'inquiry_add' ? 'active' : ''; ?> space-y-4 md:space-y-6">
    <p class="text-gray-400 text-sm max-w-3xl"><?php echo translate('admin_inquiries_add_intro', 'Create a Contact Us–style inquiry for a website account. You can add the player message, an optional staff reply, token grant, and close the inquiry in one step.'); ?></p>

    <?php if (!$inquiryData['available']): ?>
        <div class="panel p-6 text-center text-gray-400">
            <?php echo translate('admin_inquiries_unavailable', 'The user_inquiries table is missing. Re-import SQL/Hearthstone_DATABASE/BASE_INSTALL/2026110601-hearthstone_site_install_v1.0.sql into your site database.'); ?>
        </div>
    <?php elseif ($inquiryAccountOptions === []): ?>
        <div class="panel p-6 text-center text-gray-400">
            <?php echo translate('admin_inquiries_add_no_accounts', 'No website accounts found in user_currencies. The player must have a site account before you can create an inquiry.'); ?>
        </div>
    <?php else: ?>
        <div class="panel p-4 md:p-6 lg:p-8 max-w-3xl">
            <form method="post" action="<?php echo htmlspecialchars($base_path . 'admin/tickets?tab=inquiry_add'); ?>" class="space-y-5" id="adminCreateInquiryForm">
                <input type="hidden" name="action" value="create_inquiry">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2" for="create-inquiry-account"><?php echo translate('admin_inquiries_add_account', 'Account'); ?></label>
                    <select name="account_id" id="create-inquiry-account" class="input-dark" required>
                        <option value=""><?php echo translate('admin_inquiries_add_account_placeholder', 'Select an account…'); ?></option>
                        <?php foreach ($inquiryAccountOptions as $acct): ?>
                            <option value="<?php echo (int) $acct['account_id']; ?>" data-tokens="<?php echo (int) $acct['tokens']; ?>">
                                <?php echo htmlspecialchars($acct['username']); ?> (#<?php echo (int) $acct['account_id']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php echo translate('admin_inquiries_tokens_balance', 'Current account balance'); ?>:
                        <span class="text-[#f2cf5b] font-semibold" id="create-inquiry-token-balance">—</span>
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2" for="create-inquiry-subject"><?php echo translate('admin_inquiries_subject', 'Subject'); ?></label>
                    <input type="text" name="subject" id="create-inquiry-subject" class="input-dark" maxlength="255" required placeholder="<?php echo translate('contact_label_subject', 'Subject'); ?>">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2" for="create-inquiry-message"><?php echo translate('admin_inquiries_message', 'Player message'); ?></label>
                    <textarea name="message" id="create-inquiry-message" class="input-dark textarea-dark" rows="6" maxlength="20000" required placeholder="<?php echo translate('contact_label_message', 'Message'); ?>"></textarea>
                    <p class="text-xs text-gray-500 mt-1"><?php echo translate('admin_inquiries_add_message_hint', 'Same text the player would submit on Contact Us.'); ?></p>
                </div>

                <div class="pt-2 border-t border-[rgba(201,162,39,0.08)]">
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2" for="create-inquiry-response"><?php echo translate('admin_inquiries_response', 'Staff response'); ?></label>
                    <textarea name="response_text" id="create-inquiry-response" class="input-dark textarea-dark" rows="5" placeholder="<?php echo translate('admin_inquiries_add_response_optional', 'Optional — leave blank to create as pending only.'); ?>"></textarea>
                </div>

                <div class="pt-2 border-t border-[rgba(201,162,39,0.08)]">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2"><?php echo translate('admin_inquiries_tokens_heading', 'Shop tokens (optional)'); ?></h4>
                    <p class="text-xs text-gray-500 mb-3"><?php echo translate('admin_inquiries_add_tokens_help', 'Granted when the inquiry is created (with a staff response, or on its own if you only add tokens).'); ?></p>
                    <label class="block text-xs text-gray-400 mb-1" for="create-inquiry-tokens"><?php echo translate('admin_inquiries_tokens_add', 'Tokens to add on save'); ?></label>
                    <input type="number" name="tokens_to_add" id="create-inquiry-tokens" class="input-dark max-w-[12rem]" min="0" max="9999999" step="1" value="0">
                </div>

                <label class="flex items-start gap-3 text-sm text-gray-300 cursor-pointer">
                    <input type="checkbox" name="close_inquiry" value="1" class="mt-1 rounded border-gray-600">
                    <span><?php echo translate('admin_inquiries_add_close_checkbox', 'Close inquiry immediately after creating (after saving any response and tokens above).'); ?></span>
                </label>

                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" class="btn-gold">
                        <i class="fas fa-plus"></i> <?php echo translate('admin_inquiries_add_submit', 'Create inquiry'); ?>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var select = document.getElementById('create-inquiry-account');
    var balanceEl = document.getElementById('create-inquiry-token-balance');
    if (!select || !balanceEl) {
        return;
    }
    function updateBalance() {
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) {
            balanceEl.textContent = '—';
            return;
        }
        var tokens = opt.getAttribute('data-tokens') || '0';
        balanceEl.textContent = Number(tokens).toLocaleString();
    }
    select.addEventListener('change', updateBalance);
    updateBalance();
})();
</script>
