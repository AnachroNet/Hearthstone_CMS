<?php
if (!defined('ALLOWED_ACCESS')) {
    exit;
}
/** @var array $inquiryData */
/** @var string $inquiryStatusFilter */
/** @var string $inquirySearch */
/** @var int $inquiryPage */
/** @var int $inquiryTotalPages */
/** @var int $openEditInquiryId */
/** @var string $base_path */
?>
<div id="user-services-inquiries-tab" class="tab-content <?php echo ($servicesTab ?? '') === 'inquiries' ? 'active' : ''; ?> space-y-4 md:space-y-6">
    <p class="text-gray-400 text-sm max-w-3xl"><?php echo translate('admin_inquiries_intro', 'Messages submitted through the public Contact Us page. Replies are visible on the player Account Dashboard.'); ?></p>

    <?php if (!$inquiryData['available']): ?>
        <div class="panel p-6 text-center text-gray-400">
            <?php echo translate('admin_inquiries_unavailable', 'The user_inquiries table is not available. Run SQL/Hearthstone_DATABASE/SINGLE_UPDATE/user_inquiries.sql on the website database.'); ?>
        </div>
    <?php else: ?>
        <div class="panel p-4 md:p-6 lg:p-8">
            <form method="get" action="<?php echo htmlspecialchars($base_path . 'admin/tickets'); ?>" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <input type="hidden" name="tab" value="inquiries">
                <select name="inquiry_status" class="input-dark">
                    <option value="pending" <?php echo $inquiryStatusFilter === 'pending' ? 'selected' : ''; ?>><?php echo translate('admin_inquiries_status_pending', 'Pending only'); ?></option>
                    <option value="answered" <?php echo $inquiryStatusFilter === 'answered' ? 'selected' : ''; ?>><?php echo translate('admin_inquiries_status_answered', 'Answered'); ?></option>
                    <option value="closed" <?php echo $inquiryStatusFilter === 'closed' ? 'selected' : ''; ?>><?php echo translate('admin_inquiries_status_closed', 'Closed'); ?></option>
                    <option value="all" <?php echo $inquiryStatusFilter === 'all' ? 'selected' : ''; ?>><?php echo translate('admin_inquiries_status_all', 'All inquiries'); ?></option>
                </select>
                <input type="text" name="inquiry_search" class="input-dark sm:col-span-2" placeholder="<?php echo translate('admin_inquiries_search_placeholder', 'Inquiry # or account name…'); ?>" value="<?php echo htmlspecialchars($inquirySearch); ?>">
                <button type="submit" class="btn-gold"><?php echo translate('admin_inquiries_apply', 'Apply'); ?></button>
            </form>

            <?php if (empty($inquiryData['inquiries'])): ?>
                <p class="text-gray-400 text-center py-8"><?php echo translate('admin_inquiries_none', 'No inquiries match your filters.'); ?></p>
            <?php else: ?>
                <div class="overflow-x-auto -mx-4 md:-mx-6 px-4 md:px-6">
                    <table class="table-dark min-w-[800px]">
                        <thead>
                            <tr>
                                <th class="col-num"><?php echo translate('admin_inquiries_col_id', 'ID'); ?></th>
                                <th><?php echo translate('admin_inquiries_col_account', 'Account'); ?></th>
                                <th><?php echo translate('admin_inquiries_col_subject', 'Subject'); ?></th>
                                <th class="align-center"><?php echo translate('admin_inquiries_col_status', 'Status'); ?></th>
                                <th><?php echo translate('admin_inquiries_col_created', 'Submitted'); ?></th>
                                <th class="col-actions align-right"><?php echo translate('admin_inquiries_col_actions', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inquiryData['inquiries'] as $inq): ?>
                                <?php
                                $inqId = (int) $inq['id'];
                                $status = (string) ($inq['status'] ?? 'pending');
                                $statusKey = sahtout_inquiry_status_label_key($status);
                                $statusClass = sahtout_inquiry_status_css_class($status);
                                ?>
                                <tr>
                                    <td class="col-num">#<?php echo $inqId; ?></td>
                                    <td><?php echo htmlspecialchars((string) $inq['account_username']); ?></td>
                                    <td class="max-w-[16rem] truncate" title="<?php echo htmlspecialchars((string) $inq['subject']); ?>"><?php echo htmlspecialchars((string) $inq['subject']); ?></td>
                                    <td class="align-center"><span class="text-xs px-2 py-0.5 rounded border <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars(translate($statusKey, ucfirst($status))); ?></span></td>
                                    <td class="whitespace-nowrap text-sm text-gray-400"><?php echo htmlspecialchars(sahtout_format_inquiry_time($inq['created_at'])); ?></td>
                                    <td class="actions-cell">
                                        <div class="action-buttons">
                                        <button type="button" class="btn-gold text-xs py-1 px-3 open-inquiry-modal-btn" data-modal="inquiryModal-<?php echo $inqId; ?>">
                                            <?php echo translate('admin_inquiries_view', 'View / reply'); ?>
                                        </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php
                sahtout_admin_render_pagination_nav([
                    'current_page' => $inquiryPage,
                    'total_pages' => (int) $inquiryTotalPages,
                    'base_path' => $base_path,
                    'route' => 'admin/tickets',
                    'page_param' => 'inquiry_page',
                    'query' => array_filter([
                        'tab' => 'inquiries',
                        'inquiry_status' => $inquiryStatusFilter !== 'pending' ? $inquiryStatusFilter : null,
                        'inquiry_search' => $inquirySearch !== '' ? $inquirySearch : null,
                    ]),
                    'aria_label' => translate('admin_inquiries_pagination_aria', 'Inquiry pagination'),
                    'prev_label' => translate('admin_pagination_previous', 'Previous'),
                    'next_label' => translate('admin_pagination_next', 'Next'),
                ]);
                ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
