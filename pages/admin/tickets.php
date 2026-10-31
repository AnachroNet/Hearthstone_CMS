<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/account_gm_tickets.php';

$page_class = 'tickets';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

define('DB_AUTH', $db_auth_name);
define('DB_CHAR', $db_char_name);

global $char_db, $auth_db;

$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['open', 'closed', 'all'], true)
    ? $_GET['status']
    : 'open';
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 15;
$openEditId = isset($_GET['edit']) ? max(0, (int) $_GET['edit']) : 0;

$flashMessage = null;
$flashType = 'success';

$listReturn = $base_path . 'admin/tickets?' . http_build_query(array_filter([
    'status' => $statusFilter !== 'open' ? $statusFilter : null,
    'search' => $search !== '' ? $search : null,
    'page' => $page > 1 ? $page : null,
]));

$gmCharacters = [];
if (isset($auth_db, $char_db) && $auth_db instanceof mysqli && $char_db instanceof mysqli
    && !$auth_db->connect_error && !$char_db->connect_error) {
    $gmCharacters = sahtout_gm_level3_characters($auth_db, $char_db, DB_AUTH);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_ticket') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $flashMessage = translate('admin_tickets_csrf_error', 'Security token validation failed.');
        $flashType = 'danger';
    } elseif (!isset($char_db) || $char_db->connect_error) {
        $flashMessage = translate('admin_tickets_unavailable', 'The gm_ticket table is not available on the character database.');
        $flashType = 'danger';
    } else {
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $comment = (string) ($_POST['comment'] ?? '');
        $response = (string) ($_POST['response'] ?? '');
        $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
        $stateAction = (string) ($_POST['state_action'] ?? 'save');

        $result = sahtout_admin_save_gm_ticket(
            $char_db,
            $ticketId,
            $comment,
            $response,
            $assignedTo,
            $stateAction,
            $gmCharacters
        );

        $redirectParams = array_filter([
            'status' => $_POST['return_status'] ?? null,
            'search' => $_POST['return_search'] ?? null,
            'page' => $_POST['return_page'] ?? null,
            'edit' => $ticketId > 0 ? $ticketId : null,
        ]);

        if (!$result['ok']) {
            $redirectParams['msg'] = $result['message'];
            $redirectParams['msg_type'] = 'danger';
        } else {
            $redirectParams['msg'] = $stateAction === 'close'
                ? 'closed'
                : ($stateAction === 'reopen' ? 'reopened' : 'saved');
            if ($result['reload_ok'] === false) {
                $redirectParams['reload'] = '0';
            }
        }

        header('Location: ' . $base_path . 'admin/tickets?' . http_build_query($redirectParams));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $msgKey = (string) $_GET['msg'];
    $flashType = ($_GET['msg_type'] ?? '') === 'danger' ? 'danger' : 'success';
    $flashMessage = match ($msgKey) {
        'saved' => translate('admin_tickets_saved', 'Ticket saved.'),
        'closed' => translate('admin_tickets_closed', 'Ticket closed.'),
        'reopened' => translate('admin_tickets_reopened', 'Ticket reopened.'),
        'not_found' => translate('admin_tickets_not_found', 'Ticket not found.'),
        'invalid_assignee' => translate('admin_tickets_invalid_assignee', 'Selected GM is not valid for assignment.'),
        'already_closed' => translate('admin_tickets_already_closed', 'Ticket is already closed.'),
        'already_open' => translate('admin_tickets_already_open', 'Ticket is already open.'),
        'update_failed' => translate('admin_tickets_update_failed', 'Could not update ticket.'),
        default => htmlspecialchars($msgKey),
    };
    if (isset($_GET['reload']) && $_GET['reload'] === '0') {
        $flashMessage .= ' ' . translate('admin_tickets_reload_hint', 'Database updated; run reload gm_tickets on the worldserver (or configure SOAP) for in-game sync.');
    }
}

$ticketData = ['available' => false, 'tickets' => [], 'total' => 0];
if (isset($char_db) && $char_db instanceof mysqli && !$char_db->connect_error) {
    $ticketData = sahtout_admin_gm_tickets($char_db, $statusFilter, $search, $page, $perPage);
}

$accountNames = [];
if ($ticketData['available'] && !empty($ticketData['tickets']) && isset($auth_db) && !$auth_db->connect_error) {
    $accountIds = array_column($ticketData['tickets'], 'account_id');
    $accountNames = sahtout_gm_ticket_account_names($auth_db, $accountIds);
}

$totalPages = max(1, (int) ceil($ticketData['total'] / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$page_title = translate('admin_tickets_page_title', 'Support Tickets');
$page_meta_description = translate('admin_tickets_meta_description', 'View in-game GM tickets');
$page_meta_robots = 'noindex';

ob_start();
?>
<style>
        * { font-family: 'Inter', sans-serif; }
        body {
            min-height: 100vh;
            color: #d8d8d8;
            background: #05070b;
            background-image:
                radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.14), transparent 65%),
                linear-gradient(180deg, #0a0e16 0%, #060810 45%, #03040a 100%);
            background-attachment: fixed;
        }
        .wow-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .panel {
            background: linear-gradient(180deg, rgba(22,25,32,.92), rgba(8,10,14,.9));
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 12px 32px rgba(0,0,0,.55);
        }
        .input-dark {
            width: 100%;
            padding: .65rem .85rem;
            background: rgba(0,0,0,.45);
            border: 1px solid rgba(201,162,39,.25);
            color: #e5e7eb;
            font-size: .875rem;
        }
        .input-dark:focus { outline: none; border-color: #f2cf5b; }
        .textarea-dark {
            min-height: 6rem;
            resize: vertical;
            white-space: pre-wrap;
        }
        .btn-gold {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            padding: .65rem 1.1rem;
            font-weight: 700;
            font-size: .8rem;
            text-transform: uppercase;
            background: linear-gradient(180deg, #f6d478, #c9a227, #8a6a14);
            color: #1a1200;
            border: 1px solid rgba(138,106,20,.8);
            cursor: pointer;
        }
        .btn-iron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .35rem;
            padding: .5rem 1rem;
            font-weight: 700;
            font-size: .75rem;
            text-transform: uppercase;
            border-radius: 2px;
            transition: transform .2s ease;
            background: linear-gradient(180deg, #3a404d 0%, #232833 55%, #141821 100%);
            color: #cfe1ff;
            box-shadow: inset 0 0 0 1px rgba(120,160,255,.25), inset 0 -8px 14px rgba(0,0,0,.4);
            border: none;
            cursor: pointer;
        }
        .btn-iron:hover { transform: translateY(-1px); }
        .btn-iron-sm { padding: .4rem .85rem; font-size: .7rem; }
        .btn-danger-outline {
            background: rgba(127, 29, 29, 0.35);
            color: #fca5a5;
            box-shadow: inset 0 0 0 1px rgba(248, 113, 113, 0.35);
        }
        .table-dark { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .table-dark td {
            padding: .85rem 1rem;
            border-bottom: 1px solid rgba(201,162,39,.08);
            vertical-align: middle;
        }
        .table-dark tr:hover td { background: rgba(242,207,82,.04); }
        .ticket-admin-summary {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .35rem .5rem;
            min-width: 0;
            flex: 1;
            font-size: .8125rem;
            line-height: 1.4;
        }
        .ticket-admin-summary .sep { color: rgba(156, 163, 175, 0.65); }
        .ticket-admin-summary .label { color: rgba(156, 163, 175, 0.85); }
        .ticket-admin-summary .val { color: #e5e7eb; }
        .ticket-admin-summary .val-muted { color: #9ca3af; }
        .ticket-status-open { color: #fbbf24; border-color: rgba(251, 191, 36, 0.35); background: rgba(251, 191, 36, 0.08); }
        .ticket-status-assigned { color: #60a5fa; border-color: rgba(96, 165, 250, 0.35); background: rgba(96, 165, 250, 0.08); }
        .ticket-status-closed { color: #9ca3af; border-color: rgba(156, 163, 175, 0.35); background: rgba(156, 163, 175, 0.08); }
        .ticket-admin-row-inner {
            display: flex;
            align-items: center;
            gap: .75rem;
            justify-content: space-between;
        }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-backdrop.active { display: flex; }
        .modal-backdrop .panel { max-height: 90vh; overflow-y: auto; width: 100%; max-width: 42rem; }
        .main-content-area {
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 72px);
            width: 100%;
        }
        @media (min-width: 1024px) {
            .main-content-area.lg\:ml-70 { margin-left: 280px; }
        }
        @media (max-width: 1023px) {
            .main-content-area { margin-left: 0 !important; }
        }
</style>
<?php $page_head = ob_get_clean(); ?>

<?php include $project_root . 'includes/header.php'; ?>

<div class="flex relative min-h-screen">
    <?php include $project_root . 'includes/admin_sidebar.php'; ?>

    <main class="main-content-area flex-1 p-3 sm:p-4 md:p-6 lg:p-8 transition-all duration-300 lg:ml-70">
        <div class="max-w-[1400px] mx-auto space-y-6">
            <h1 class="wow-title text-2xl md:text-3xl"><?php echo translate('admin_tickets_title', 'Support Tickets'); ?></h1>
            <p class="text-gray-400 text-sm max-w-3xl"><?php echo translate('admin_tickets_intro', 'Review and update in-game Help (?) tickets. Changes are written to the character database and reloaded on the worldserver when SOAP is configured.'); ?></p>

            <?php if ($flashMessage): ?>
                <div class="<?php echo $flashType === 'danger' ? 'bg-red-900/40 border-red-500/50 text-red-300' : 'bg-green-900/40 border-green-500/50 text-green-300'; ?> border px-4 py-3 rounded-sm text-sm">
                    <?php echo htmlspecialchars($flashMessage); ?>
                </div>
            <?php endif; ?>

            <?php if (!$ticketData['available']): ?>
                <div class="panel p-6 text-center text-gray-400">
                    <?php echo translate('admin_tickets_unavailable', 'The gm_ticket table is not available on the character database.'); ?>
                </div>
            <?php else: ?>
                <div class="panel p-4 md:p-6">
                    <form method="get" action="<?php echo htmlspecialchars($base_path . 'admin/tickets'); ?>" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                        <select name="status" class="input-dark">
                            <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>><?php echo translate('admin_tickets_status_open', 'Open only'); ?></option>
                            <option value="closed" <?php echo $statusFilter === 'closed' ? 'selected' : ''; ?>><?php echo translate('admin_tickets_status_closed', 'Closed / completed'); ?></option>
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>><?php echo translate('admin_tickets_status_all', 'All tickets'); ?></option>
                        </select>
                        <input type="text" name="search" class="input-dark sm:col-span-2" placeholder="<?php echo translate('admin_tickets_search_placeholder', 'Ticket # or character name…'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-gold"><?php echo translate('admin_tickets_apply', 'Apply'); ?></button>
                    </form>

                    <?php if (empty($ticketData['tickets'])): ?>
                        <p class="text-gray-400 text-center py-8"><?php echo translate('admin_tickets_none', 'No tickets match your filters.'); ?></p>
                    <?php else: ?>
                        <div class="overflow-x-auto -mx-4 md:-mx-6 px-4 md:px-6">
                            <table class="table-dark min-w-[640px]">
                                <tbody>
                                    <?php foreach ($ticketData['tickets'] as $ticket):
                                        $accId = (int) ($ticket['account_id'] ?? 0);
                                        $accName = $accId > 0 ? ($accountNames[$accId] ?? ('#' . $accId)) : translate('admin_tickets_orphan_character', 'Character deleted');
                                        $assignedLabel = trim((string) ($ticket['assigned_to_name'] ?? ''));
                                        if ($assignedLabel === '') {
                                            $assignedLabel = translate('admin_tickets_unassigned', 'Unassigned');
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="ticket-admin-row-inner">
                                                <div class="ticket-admin-summary">
                                                    <?php echo sahtout_render_ticket_status_badge_html($ticket); ?>
                                                    <span class="sep">·</span>
                                                    <span class="font-mono text-[#f2cf5b]">#<?php echo (int) $ticket['id']; ?></span>
                                                    <span class="sep">·</span>
                                                    <span class="label"><?php echo translate('admin_tickets_table_character', 'Character'); ?>:</span>
                                                    <?php if ($ticket['player_guid'] > 0): ?>
                                                        <a class="val text-cyan-300 hover:text-white" href="<?php echo $base_path; ?>character?guid=<?php echo (int) $ticket['player_guid']; ?>&amp;return=<?php echo urlencode($listReturn); ?>">
                                                            <?php echo htmlspecialchars($ticket['character_name']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="val"><?php echo htmlspecialchars($ticket['character_name']); ?></span>
                                                    <?php endif; ?>
                                                    <span class="sep">·</span>
                                                    <span class="label"><?php echo translate('admin_tickets_table_account', 'Account'); ?>:</span>
                                                    <?php if ($accId > 0): ?>
                                                        <a class="val text-gray-300 hover:text-white" href="<?php echo $base_path; ?>admin/users?search_username=<?php echo urlencode($accountNames[$accId] ?? ''); ?>">
                                                            <?php echo htmlspecialchars($accName); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="val-muted">—</span>
                                                    <?php endif; ?>
                                                    <span class="sep">·</span>
                                                    <span class="label"><?php echo translate('admin_tickets_assigned_to', 'Assigned To'); ?>:</span>
                                                    <span class="val"><?php echo htmlspecialchars($assignedLabel); ?></span>
                                                    <span class="sep">·</span>
                                                    <span class="label"><?php echo translate('admin_tickets_table_updated', 'Last updated'); ?>:</span>
                                                    <span class="val-muted whitespace-nowrap"><?php echo htmlspecialchars(sahtout_format_ticket_time($ticket['last_modified_time'])); ?></span>
                                                </div>
                                                <button type="button" class="btn-iron btn-iron-sm open-modal-btn shrink-0" data-modal="ticketModal-<?php echo (int) $ticket['id']; ?>">
                                                    <i class="fas fa-pen"></i> <?php echo translate('admin_tickets_update_button', 'Update'); ?>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($ticketData['total'] > $perPage): ?>
                            <nav class="flex flex-wrap items-center justify-between gap-3 mt-6 text-sm text-gray-400" aria-label="<?php echo translate('admin_tickets_pagination', 'Ticket pagination'); ?>">
                                <span>
                                    <?php echo translate('admin_tickets_showing', 'Showing'); ?>
                                    <?php echo (int) (($page - 1) * $perPage + 1); ?>–<?php echo (int) min($page * $perPage, $ticketData['total']); ?>
                                    <?php echo translate('admin_tickets_of', 'of'); ?> <?php echo (int) $ticketData['total']; ?>
                                </span>
                                <div class="flex gap-2">
                                    <?php
                                    $queryBase = array_filter(['status' => $statusFilter !== 'open' ? $statusFilter : null, 'search' => $search !== '' ? $search : null]);
                                    if ($page > 1):
                                        $prevQ = $queryBase + ['page' => $page - 1];
                                    ?>
                                        <a class="px-3 py-1 border border-[rgba(201,162,39,.3)] text-[#f2cf5b] hover:bg-[rgba(242,207,82,.08)]" href="<?php echo htmlspecialchars($base_path . 'admin/tickets?' . http_build_query($prevQ)); ?>">&larr;</a>
                                    <?php endif; ?>
                                    <?php if ($page < $totalPages):
                                        $nextQ = $queryBase + ['page' => $page + 1];
                                    ?>
                                        <a class="px-3 py-1 border border-[rgba(201,162,39,.3)] text-[#f2cf5b] hover:bg-[rgba(242,207,82,.08)]" href="<?php echo htmlspecialchars($base_path . 'admin/tickets?' . http_build_query($nextQ)); ?>">&rarr;</a>
                                    <?php endif; ?>
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<div id="modals-container">
    <?php if ($ticketData['available'] && !empty($ticketData['tickets'])): ?>
        <?php foreach ($ticketData['tickets'] as $ticket):
            $modalId = (int) $ticket['id'];
            $isOpen = sahtout_gm_ticket_is_open($ticket);
        ?>
        <div id="ticketModal-<?php echo $modalId; ?>" class="modal-backdrop">
            <div class="panel p-6 md:p-8 relative">
                <button type="button" class="absolute top-4 right-4 text-gray-400 hover:text-white text-2xl close-modal-btn" data-modal="ticketModal-<?php echo $modalId; ?>">&times;</button>
                <h3 class="wow-title text-xl md:text-2xl mb-1"><?php echo translate('admin_tickets_modal_title', 'Ticket'); ?> #<?php echo $modalId; ?></h3>
                <p class="text-gray-400 text-sm mb-6"><?php echo htmlspecialchars($ticket['character_name']); ?> · <?php echo sahtout_render_ticket_status_badge_html($ticket); ?></p>

                <form method="post" action="<?php echo htmlspecialchars($base_path . 'admin/tickets'); ?>">
                    <input type="hidden" name="action" value="update_ticket">
                    <input type="hidden" name="ticket_id" value="<?php echo $modalId; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                    <input type="hidden" name="return_search" value="<?php echo htmlspecialchars($search); ?>">
                    <input type="hidden" name="return_page" value="<?php echo (int) $page; ?>">
                    <input type="hidden" name="state_action" value="save" class="ticket-state-action-input">

                    <div class="mb-4">
                        <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2"><?php echo translate('admin_tickets_message', 'Player message'); ?></label>
                        <div class="input-dark textarea-dark opacity-90 cursor-default"><?php echo nl2br(htmlspecialchars($ticket['description'])); ?></div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2" for="comment-<?php echo $modalId; ?>"><?php echo translate('admin_tickets_comment', 'Internal comment'); ?></label>
                        <textarea id="comment-<?php echo $modalId; ?>" name="comment" class="input-dark textarea-dark" rows="4"><?php echo htmlspecialchars($ticket['comment']); ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2" for="response-<?php echo $modalId; ?>"><?php echo translate('admin_tickets_response', 'Staff response'); ?></label>
                        <textarea id="response-<?php echo $modalId; ?>" name="response" class="input-dark textarea-dark" rows="4"><?php echo htmlspecialchars($ticket['response']); ?></textarea>
                    </div>

                    <div class="mb-6">
                        <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-2" for="assigned-<?php echo $modalId; ?>"><?php echo translate('admin_tickets_assign_gm', 'Assign to GM character'); ?></label>
                        <select id="assigned-<?php echo $modalId; ?>" name="assigned_to" class="input-dark">
                            <option value="0" <?php echo (int) ($ticket['assigned_to'] ?? 0) === 0 ? 'selected' : ''; ?>><?php echo translate('admin_tickets_unassigned', 'Unassigned'); ?></option>
                            <?php foreach ($gmCharacters as $gm): ?>
                                <option value="<?php echo (int) $gm['guid']; ?>" <?php echo (int) ($ticket['assigned_to'] ?? 0) === (int) $gm['guid'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gm['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($gmCharacters === []): ?>
                            <p class="text-amber-300/80 text-xs mt-2"><?php echo translate('admin_tickets_no_gm_chars', 'No GM level 3 characters found. Create a GM account with level 3 access and at least one character.'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3 pt-2 border-t border-[rgba(201,162,39,0.12)]">
                        <button type="button" class="btn-iron close-modal-btn" data-modal="ticketModal-<?php echo $modalId; ?>"><?php echo translate('admin_tickets_cancel', 'Cancel'); ?></button>
                        <?php if ($isOpen): ?>
                            <button type="submit" class="btn-iron btn-danger-outline ticket-state-btn" data-state="close"><?php echo translate('admin_tickets_close_button', 'Close ticket'); ?></button>
                        <?php else: ?>
                            <button type="submit" class="btn-iron ticket-state-btn" data-state="reopen"><?php echo translate('admin_tickets_reopen_button', 'Reopen ticket'); ?></button>
                        <?php endif; ?>
                        <button type="submit" class="btn-gold ticket-state-btn" data-state="save"><?php echo translate('admin_tickets_save_button', 'Save changes'); ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    function openModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) modal.classList.add('active');
    }
    function closeModal(modalId) {
        var modal = document.getElementById(modalId);
        if (modal) modal.classList.remove('active');
    }

    document.addEventListener('click', function (e) {
        var openBtn = e.target.closest('.open-modal-btn');
        if (openBtn) {
            e.preventDefault();
            var id = openBtn.getAttribute('data-modal');
            if (id) openModal(id);
            return;
        }
        var closeBtn = e.target.closest('.close-modal-btn');
        if (closeBtn) {
            e.preventDefault();
            var closeId = closeBtn.getAttribute('data-modal');
            if (closeId) closeModal(closeId);
            return;
        }
        if (e.target.classList.contains('modal-backdrop') && e.target.classList.contains('active')) {
            closeModal(e.target.id);
        }
    });

    document.addEventListener('click', function (e) {
        var stateBtn = e.target.closest('.ticket-state-btn');
        if (!stateBtn) return;
        var form = stateBtn.closest('form');
        if (!form) return;
        var input = form.querySelector('.ticket-state-action-input');
        if (input) input.value = stateBtn.getAttribute('data-state') || 'save';
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.active').forEach(function (m) {
                m.classList.remove('active');
            });
        }
    });

    <?php if ($openEditId > 0): ?>
    document.addEventListener('DOMContentLoaded', function () {
        openModal('ticketModal-<?php echo (int) $openEditId; ?>');
    });
    <?php endif; ?>
})();
</script>

<?php include $project_root . 'includes/footer.php'; ?>
