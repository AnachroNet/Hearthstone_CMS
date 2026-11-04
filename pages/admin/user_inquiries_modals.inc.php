<?php
if (!defined('ALLOWED_ACCESS')) {
    exit;
}
if (empty($inquiryData['available']) || empty($inquiryData['inquiries'])) {
    return;
}
foreach ($inquiryData['inquiries'] as $inq):
    $inqId = (int) $inq['id'];
    $status = (string) ($inq['status'] ?? 'pending');
    $isClosed = $status === 'closed';
    ?>
    <div id="inquiryModal-<?php echo $inqId; ?>" class="modal-backdrop">
        <div class="panel p-6 md:p-8 relative max-w-2xl w-full">
            <button type="button" class="absolute top-4 right-4 text-gray-400 hover:text-white text-2xl close-inquiry-modal-btn" data-modal="inquiryModal-<?php echo $inqId; ?>">&times;</button>
            <h3 class="wow-title text-xl md:text-2xl mb-2"><?php echo translate('admin_inquiries_modal_title', 'Inquiry'); ?> #<?php echo $inqId; ?></h3>
            <p class="text-gray-400 text-sm mb-4"><?php echo htmlspecialchars((string) $inq['account_username']); ?> · <?php echo htmlspecialchars(sahtout_format_inquiry_time($inq['created_at'])); ?></p>

            <form method="post" action="<?php echo htmlspecialchars($base_path . 'admin/tickets?tab=inquiries'); ?>">
                <input type="hidden" name="action" value="update_inquiry">
                <input type="hidden" name="inquiry_id" value="<?php echo $inqId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                <input type="hidden" name="return_inquiry_status" value="<?php echo htmlspecialchars($inquiryStatusFilter); ?>">
                <input type="hidden" name="return_inquiry_search" value="<?php echo htmlspecialchars($inquirySearch); ?>">
                <input type="hidden" name="return_inquiry_page" value="<?php echo (int) $inquiryPage; ?>">
                <input type="hidden" name="state_action" value="save" class="inquiry-state-action-input">

                <div class="mb-4">
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2"><?php echo translate('admin_inquiries_subject', 'Subject'); ?></label>
                    <div class="input-dark opacity-90"><?php echo htmlspecialchars((string) $inq['subject']); ?></div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2"><?php echo translate('admin_inquiries_message', 'Player message'); ?></label>
                    <div class="input-dark textarea-dark opacity-90 cursor-default min-h-[6rem]"><?php echo nl2br(htmlspecialchars((string) $inq['message'])); ?></div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2" for="inquiry-response-<?php echo $inqId; ?>"><?php echo translate('admin_inquiries_response', 'Staff response'); ?></label>
                    <textarea id="inquiry-response-<?php echo $inqId; ?>" name="response_text" class="input-dark textarea-dark" rows="5"><?php echo htmlspecialchars((string) ($inq['response_text'] ?? '')); ?></textarea>
                    <?php if (!empty($inq['responder_username'])): ?>
                        <p class="text-xs text-gray-500 mt-1"><?php echo translate('admin_inquiries_last_responder', 'Last replied by'); ?>: <?php echo htmlspecialchars((string) $inq['responder_username']); ?>
                            <?php if (!empty($inq['responded_at'])): ?>
                                · <?php echo htmlspecialchars(sahtout_format_inquiry_time($inq['responded_at'])); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap justify-end gap-3 pt-2 border-t border-[rgba(201,162,39,0.12)]">
                    <button type="button" class="btn-iron close-inquiry-modal-btn" data-modal="inquiryModal-<?php echo $inqId; ?>"><?php echo translate('admin_inquiries_cancel', 'Cancel'); ?></button>
                    <?php if (!$isClosed): ?>
                        <button type="submit" class="btn-iron btn-danger-outline inquiry-state-btn" data-state="close"><?php echo translate('admin_inquiries_close', 'Close inquiry'); ?></button>
                    <?php else: ?>
                        <button type="submit" class="btn-iron inquiry-state-btn" data-state="reopen"><?php echo translate('admin_inquiries_reopen', 'Reopen as pending'); ?></button>
                    <?php endif; ?>
                    <button type="submit" class="btn-gold inquiry-state-btn" data-state="save"><?php echo translate('admin_inquiries_save', 'Save response'); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>
