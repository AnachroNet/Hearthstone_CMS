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

global $char_db, $auth_db;

$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['open', 'closed', 'all'], true)
    ? $_GET['status']
    : 'open';
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 15;

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

$listReturn = $base_path . 'admin/tickets?' . http_build_query(array_filter([
    'status' => $statusFilter !== 'open' ? $statusFilter : null,
    'search' => $search !== '' ? $search : null,
    'page' => $page > 1 ? $page : null,
]));

function admin_ticket_status_label(string $status): string
{
    return match ($status) {
        'open' => translate('admin_tickets_status_open_label', 'Open'),
        'completed' => translate('admin_tickets_status_completed_label', 'Completed'),
        'closed' => translate('admin_tickets_status_closed_label', 'Closed'),
        default => translate('admin_tickets_status_closed_label', 'Closed'),
    };
}

function admin_ticket_status_class(string $status): string
{
    return match ($status) {
        'open' => 'text-amber-300 border-amber-400/40 bg-amber-500/10',
        'completed' => 'text-emerald-300 border-emerald-400/40 bg-emerald-500/10',
        default => 'text-gray-300 border-gray-500/40 bg-gray-500/10',
    };
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
        .section-title {
            font-family: 'Cinzel', serif;
            font-weight: 700;
            color: #f2cf5b;
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
        .table-dark { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .table-dark th {
            text-align: left;
            padding: .75rem 1rem;
            color: #f2cf5b;
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid rgba(201,162,39,.2);
            background: rgba(201,162,39,.06);
        }
        .table-dark td {
            padding: .75rem 1rem;
            border-bottom: 1px solid rgba(201,162,39,.08);
            vertical-align: top;
        }
        .table-dark tr:hover td { background: rgba(242,207,82,.04); }
        .ticket-detail-row td { background: rgba(0,0,0,.25); }
        .ticket-body-block { white-space: pre-wrap; word-break: break-word; }
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
            <p class="text-gray-400 text-sm max-w-3xl"><?php echo translate('admin_tickets_intro', 'Open tickets from the in-game Help (?) button. Manage responses in-game; this page is a read-only overview.'); ?></p>

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
                            <table class="table-dark min-w-[720px]">
                                <thead>
                                    <tr>
                                        <th><?php echo translate('admin_tickets_table_id', 'ID'); ?></th>
                                        <th><?php echo translate('admin_tickets_table_status', 'Status'); ?></th>
                                        <th><?php echo translate('admin_tickets_table_character', 'Character'); ?></th>
                                        <th><?php echo translate('admin_tickets_table_account', 'Account'); ?></th>
                                        <th><?php echo translate('admin_tickets_table_updated', 'Last updated'); ?></th>
                                        <th><?php echo translate('admin_tickets_table_flags', 'Flags'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ticketData['tickets'] as $ticket):
                                        $accId = (int) ($ticket['account_id'] ?? 0);
                                        $accName = $accId > 0 ? ($accountNames[$accId] ?? ('#' . $accId)) : translate('admin_tickets_orphan_character', 'Character deleted');
                                        $statusCls = admin_ticket_status_class($ticket['status']);
                                    ?>
                                    <tr>
                                        <td class="font-mono text-[#f2cf5b]">#<?php echo (int) $ticket['id']; ?></td>
                                        <td>
                                            <span class="inline-flex px-2 py-0.5 rounded border text-[10px] font-bold uppercase <?php echo $statusCls; ?>">
                                                <?php echo htmlspecialchars(admin_ticket_status_label($ticket['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($ticket['player_guid'] > 0): ?>
                                                <a class="text-cyan-300 hover:text-white" href="<?php echo $base_path; ?>character?guid=<?php echo (int) $ticket['player_guid']; ?>&amp;return=<?php echo urlencode($listReturn); ?>">
                                                    <?php echo htmlspecialchars($ticket['character_name']); ?>
                                                </a>
                                                <?php if ($ticket['character_online']): ?>
                                                    <span class="text-[10px] text-emerald-400 ml-1"><?php echo translate('admin_tickets_char_online', 'Online'); ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($ticket['character_name']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($accId > 0): ?>
                                                <a class="text-gray-300 hover:text-white" href="<?php echo $base_path; ?>admin/users?search_username=<?php echo urlencode($accountNames[$accId] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($accountNames[$accId] ?? translate('admin_tickets_unknown_account', 'Unknown account')); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-500">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-gray-400 text-xs whitespace-nowrap"><?php echo htmlspecialchars(sahtout_format_ticket_time($ticket['last_modified_time'])); ?></td>
                                        <td class="text-xs text-gray-400">
                                            <?php if ($ticket['viewed']): ?><span class="text-emerald-400/90"><?php echo translate('admin_tickets_viewed', 'Viewed'); ?></span><?php endif; ?>
                                            <?php if ($ticket['need_more_help']): ?><span class="text-amber-300/90 block"><?php echo translate('admin_tickets_need_help', 'Needs help'); ?></span><?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr class="ticket-detail-row">
                                        <td colspan="6">
                                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 py-2 text-sm">
                                                <div>
                                                    <div class="text-[10px] uppercase tracking-wider text-[#f2cf5b] mb-1"><?php echo translate('admin_tickets_message', 'Player message'); ?></div>
                                                    <div class="ticket-body-block text-gray-200"><?php echo nl2br(htmlspecialchars($ticket['description'])); ?></div>
                                                </div>
                                                <div>
                                                    <div class="text-[10px] uppercase tracking-wider text-[#f2cf5b] mb-1"><?php echo translate('admin_tickets_comment', 'Staff comment'); ?></div>
                                                    <div class="ticket-body-block text-gray-300"><?php echo trim($ticket['comment']) !== '' ? nl2br(htmlspecialchars($ticket['comment'])) : translate('admin_tickets_no_comment', '—'); ?></div>
                                                </div>
                                                <div>
                                                    <div class="text-[10px] uppercase tracking-wider text-[#f2cf5b] mb-1"><?php echo translate('admin_tickets_response', 'Staff response'); ?></div>
                                                    <div class="ticket-body-block text-gray-300"><?php echo trim($ticket['response']) !== '' ? nl2br(htmlspecialchars($ticket['response'])) : translate('admin_tickets_no_response', '—'); ?></div>
                                                </div>
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
                                    $queryBase = array_filter(['status' => $statusFilter, 'search' => $search !== '' ? $search : null]);
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

<?php include $project_root . 'includes/footer.php'; ?>
