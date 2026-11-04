<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}
/** @var bool $soapConfigured */
/** @var string|null $serverCommFeedback */
/** @var string|null $motdLoadNotice */
/** @var string $serverMotd */
?>
<div class="wow-panel p-4 md:p-6 lg:p-8">
    <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6 flex items-center gap-3">
        <i class="fas fa-bullhorn text-[#f2cf5b]"></i>
        <?php echo translate('gm_cmd_server_comm_heading', 'Server Communication'); ?>
    </h2>

    <?php if (!$soapConfigured): ?>
        <p class="text-amber-300/90 text-sm"><?php echo translate('gm_cmd_soap_missing', 'SOAP is not configured. Set it up under Admin → Settings → SOAP.'); ?></p>
    <?php else: ?>
        <?php if ($serverCommFeedback !== null): ?>
            <div class="mb-4 space-y-3"><?php echo $serverCommFeedback; ?></div>
        <?php endif; ?>

        <?php if ($motdLoadNotice !== null): ?>
            <p class="text-amber-300/80 text-xs mb-4"><?php echo htmlspecialchars($motdLoadNotice); ?></p>
        <?php endif; ?>

        <form method="post" class="space-y-3 mb-6 pb-6 border-b border-[rgba(201,162,39,0.08)]">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="gm_action" value="set_motd">
            <label class="block text-sm text-gray-300 mb-1" for="server-motd"><?php echo translate('gm_cmd_motd_label', 'Server message of the day (MOTD)'); ?></label>
            <p class="text-xs text-gray-500 mb-2"><?php echo translate('gm_cmd_motd_help', 'Shows and updates the enUS message only (.server motd / .server set motd enUS). Other locales are unchanged.'); ?></p>
            <textarea id="server-motd" name="server_motd" class="wow-textarea" rows="3" maxlength="512"><?php echo htmlspecialchars($serverMotd); ?></textarea>
            <button type="submit" class="btn-game btn-gold">
                <i class="fas fa-save"></i>
                <?php echo translate('gm_cmd_motd_save', 'Save MOTD'); ?>
            </button>
        </form>

        <form method="post" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="gm_action" value="broadcast">
            <label class="block text-sm text-gray-300 mb-1" for="broadcast-message"><?php echo translate('gm_cmd_broadcast_label', 'Server broadcast'); ?></label>
            <p class="text-xs text-gray-500 mb-2"><?php echo translate('gm_cmd_broadcast_help', 'Sends a one-time global message to online players (.announce).'); ?></p>
            <input type="text" id="broadcast-message" name="broadcast_message" class="wow-input" maxlength="256" placeholder="<?php echo translate('gm_cmd_broadcast_placeholder', 'Maintenance in 10 minutes…'); ?>">
            <button type="submit" class="btn-game btn-gold">
                <i class="fas fa-paper-plane"></i>
                <?php echo translate('gm_cmd_broadcast_send', 'Send broadcast'); ?>
            </button>
        </form>
    <?php endif; ?>
</div>
