<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/site_calendar_events.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

$page_class = 'events';
global $site_db;

$tableReady = isset($site_db) && $site_db instanceof mysqli && sahtout_site_calendar_events_table_exists($site_db);
$formAction = htmlspecialchars($base_path . 'admin/events', ENT_QUOTES, 'UTF-8');
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    $action = (string) ($_POST['action'] ?? '');
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $flash = 'error:' . translate('error_csrf_invalid', 'Invalid CSRF token.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $site_db->prepare('DELETE FROM site_calendar_events WHERE id = ?');
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                header('Location: ' . $base_path . 'admin/events?status=deleted');
                exit;
            }
            $flash = 'error:' . translate('admin_events_save_failed', 'Could not save changes.');
        }
    } elseif ($action === 'add' || $action === 'update') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            $flash = 'error:' . translate('admin_events_validation_title', 'Title is required.');
        } else {
            $dateError = sahtout_site_calendar_events_validate_dates($startDate, $endDate);
            if ($dateError !== null) {
                $flash = 'error:' . $dateError;
            } elseif ($action === 'add') {
                $stmt = $site_db->prepare(
                    'INSERT INTO site_calendar_events (title, description, start_date, end_date, is_active)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('ssssi', $title, $description, $startDate, $endDate, $isActive);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    header('Location: ' . $base_path . 'admin/events?status=added');
                    exit;
                }
                $flash = 'error:' . translate('admin_events_save_failed', 'Could not save changes.');
            } else {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    $stmt = $site_db->prepare(
                        'UPDATE site_calendar_events SET title = ?, description = ?, start_date = ?, end_date = ?, is_active = ? WHERE id = ?'
                    );
                    $stmt->bind_param('ssssii', $title, $description, $startDate, $endDate, $isActive, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        header('Location: ' . $base_path . 'admin/events?status=updated');
                        exit;
                    }
                    $flash = 'error:' . translate('admin_events_save_failed', 'Could not save changes.');
                }
            }
        }
    }
}

if (isset($_GET['status'])) {
    $flash = match ($_GET['status']) {
        'added' => 'success:' . translate('admin_events_added', 'Event added.'),
        'updated' => 'success:' . translate('admin_events_updated', 'Event updated.'),
        'deleted' => 'success:' . translate('admin_events_deleted', 'Event removed.'),
        default => 'success:' . translate('admin_events_saved', 'Changes saved.'),
    };
}

$allEvents = $tableReady ? sahtout_site_calendar_events_fetch_all($site_db, false) : [];

$page_title = translate('admin_events_page_title', 'Event Management');
$page_meta_description = translate('admin_events_meta', 'Manage custom calendar events on the home page');
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
        .wow-panel {
            position: relative;
            background: linear-gradient(180deg, rgba(22,25,32,.92), rgba(8,10,14,.9));
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 12px 32px rgba(0,0,0,.55);
        }
        .wow-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .btn-game {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .65rem 1.25rem;
            font-weight: 800;
            font-size: .75rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px);
            transition: transform .2s ease, filter .2s ease;
            text-decoration: none;
            border: none;
            cursor: pointer;
        }
        .btn-game:hover { transform: translateY(-2px) scale(1.02); }
        .btn-gold {
            background: linear-gradient(180deg, #f6d478 0%, #c9a227 48%, #8a6a14 100%);
            color: #1a1200;
            text-shadow: 0 1px 0 rgba(255,255,255,.35);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.28), inset 0 -8px 14px rgba(0,0,0,.25);
        }
        .btn-iron {
            background: linear-gradient(180deg, #3a404d 0%, #232833 55%, #141821 100%);
            color: #cfe1ff;
            box-shadow: inset 0 0 0 1px rgba(120,160,255,.25), inset 0 -8px 14px rgba(0,0,0,.4);
        }
        .btn-danger {
            background: linear-gradient(180deg, #ff4d4d 0%, #cc0000 48%, #8a0000 100%);
            color: #fff;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.2), inset 0 -8px 14px rgba(0,0,0,.3);
        }
        .wow-input, .wow-textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(10, 14, 22, 0.85);
            border: 1px solid rgba(201,162,39,0.3);
            color: #e5e7eb;
            outline: none;
        }
        .wow-input:focus, .wow-textarea:focus {
            border-color: #f2cf5b;
            box-shadow: 0 0 10px rgba(242,207,82,.15);
        }
        .main-content-area { transition: margin-left 0.3s ease; min-height: calc(100vh - 72px); width: 100%; }
        @media (min-width: 1024px) { .main-content-area { margin-left: 280px; } }
        .table-dark { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .table-dark th {
            text-align: left;
            padding: 0.75rem;
            color: #f2cf5b;
            font-family: 'Cinzel', serif;
            border-bottom: 1px solid rgba(201,162,39,.35);
        }
        .table-dark td {
            padding: 0.75rem;
            border-bottom: 1px solid rgba(201,162,39,.1);
            vertical-align: top;
        }
        .badge-inactive { color: #f87171; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .badge-active { color: #4ade80; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .modal-backdrop {
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
        }
        .modal-backdrop .wow-panel {
            max-height: 90vh;
            overflow-y: auto;
        }
    </style>
<?php
$page_head = ob_get_clean();
include $project_root . 'includes/header.php';

$alertHtml = '';
if ($flash !== '') {
    [$type, $msg] = explode(':', $flash, 2);
    $alertHtml = $type === 'success'
        ? '<div class="bg-green-900/40 border border-green-500/50 text-green-300 px-4 py-3 rounded-sm mb-6">' . htmlspecialchars($msg) . '</div>'
        : '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6">' . htmlspecialchars($msg) . '</div>';
}

function sahtout_admin_format_event_dates(array $row): string
{
    $start = (string) ($row['start_date'] ?? '');
    $end = (string) ($row['end_date'] ?? '');
    if ($start === '' || $end === '') {
        return '';
    }
    if ($start === $end) {
        return date('M j, Y', strtotime($start));
    }

    return date('M j, Y', strtotime($start)) . ' – ' . date('M j, Y', strtotime($end));
}
?>
<div class="flex relative min-h-screen">
    <?php include $project_root . 'includes/admin_sidebar.php'; ?>
    <main class="main-content-area flex-1 p-4 md:p-8">
        <div class="max-w-5xl mx-auto space-y-8">
            <h1 class="wow-title text-2xl md:text-3xl"><?php echo translate('admin_events_heading', 'Event Management'); ?></h1>
            <p class="text-gray-400 text-sm"><?php echo translate('admin_events_help', 'Custom events appear on the home Event Calendar alongside WoW world holidays (silver chips). Inactive events are hidden from the public calendar.'); ?></p>

            <?php echo $alertHtml; ?>

            <?php if (!$tableReady): ?>
                <div class="wow-panel p-6 text-amber-300 border border-amber-500/30">
                    <?php echo translate('admin_events_table_missing', 'Run SQL/Sahtout_site DATABASE/SINGLE UPDATE/site_calendar_events.sql on the site database first.'); ?>
                </div>
            <?php else: ?>

            <div class="wow-panel p-6">
                <h2 class="text-[#f2cf5b] font-['Cinzel'] text-lg mb-4"><?php echo translate('admin_events_add', 'Add event'); ?></h2>
                <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="add">

                    <div>
                        <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_events_label_title', 'Title'); ?></label>
                        <input type="text" name="title" class="wow-input" maxlength="200" required>
                    </div>

                    <div>
                        <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_events_label_description', 'Description'); ?></label>
                        <textarea name="description" class="wow-textarea" rows="4"></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_events_label_start', 'Start date'); ?></label>
                            <input type="date" name="start_date" class="wow-input" required>
                        </div>
                        <div>
                            <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_events_label_end', 'End date'); ?></label>
                            <input type="date" name="end_date" class="wow-input" required>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <?php echo translate('admin_events_label_active', 'Published (visible on home calendar)'); ?>
                    </label>

                    <div class="pt-2">
                        <button type="submit" class="btn-game btn-gold">
                            <i class="fas fa-plus"></i>
                            <?php echo translate('admin_events_create', 'Add event'); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="wow-panel p-6 overflow-x-auto">
                <h2 class="text-[#f2cf5b] font-['Cinzel'] text-lg mb-4"><?php echo translate('admin_events_list', 'All events'); ?></h2>
                <?php if ($allEvents === []): ?>
                    <p class="text-gray-500"><?php echo translate('admin_events_empty', 'No custom events yet.'); ?></p>
                <?php else: ?>
                <table class="table-dark">
                    <thead>
                        <tr>
                            <th><?php echo translate('admin_events_col_title', 'Title'); ?></th>
                            <th><?php echo translate('admin_events_col_dates', 'Dates'); ?></th>
                            <th><?php echo translate('admin_events_col_status', 'Status'); ?></th>
                            <th class="text-right"><?php echo translate('admin_events_col_actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allEvents as $row): ?>
                        <tr>
                            <td>
                                <div class="font-semibold text-white"><?php echo htmlspecialchars($row['title']); ?></div>
                                <?php if (trim((string) ($row['description'] ?? '')) !== ''): ?>
                                    <?php
                                    $descPreview = (string) $row['description'];
                                    if (strlen($descPreview) > 120) {
                                        $descPreview = substr($descPreview, 0, 117) . '…';
                                    }
                                    ?>
                                    <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($descPreview); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-[#f2cf5b] text-sm whitespace-nowrap"><?php echo htmlspecialchars(sahtout_admin_format_event_dates($row)); ?></td>
                            <td>
                                <?php if ((int) ($row['is_active'] ?? 0) === 1): ?>
                                    <span class="badge-active"><?php echo translate('admin_events_status_active', 'Active'); ?></span>
                                <?php else: ?>
                                    <span class="badge-inactive"><?php echo translate('admin_events_status_hidden', 'Hidden'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="flex justify-end gap-2 flex-wrap">
                                    <button type="button" onclick="openModal('editModal-<?php echo (int) $row['id']; ?>')" class="btn-game btn-iron text-xs py-2 px-3">
                                        <i class="fas fa-edit"></i>
                                        <?php echo translate('admin_events_edit_short', 'Edit'); ?>
                                    </button>
                                    <button type="button" onclick="openModal('deleteModal-<?php echo (int) $row['id']; ?>')" class="btn-game btn-danger text-xs py-2 px-3">
                                        <i class="fas fa-trash"></i>
                                        <?php echo translate('admin_events_delete', 'Delete'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php foreach ($allEvents as $row):
                $rid = (int) $row['id'];
                $rowActive = (int) ($row['is_active'] ?? 0) === 1;
            ?>
            <div id="editModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="wow-panel w-full max-w-2xl p-6 md:p-8 relative my-8">
                    <button type="button" class="absolute top-4 right-4 text-gray-400 hover:text-white text-2xl" onclick="closeModal('editModal-<?php echo $rid; ?>')" aria-label="Close">&times;</button>
                    <h3 class="wow-title text-xl mb-6"><?php echo translate('admin_events_edit', 'Edit event'); ?>: <?php echo htmlspecialchars($row['title']); ?></h3>
                    <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">

                        <div>
                            <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_events_label_title', 'Title'); ?></label>
                            <input type="text" name="title" class="wow-input" maxlength="200" required value="<?php echo htmlspecialchars($row['title']); ?>">
                        </div>
                        <div>
                            <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_events_label_description', 'Description'); ?></label>
                            <textarea name="description" class="wow-textarea" rows="4"><?php echo htmlspecialchars($row['description']); ?></textarea>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_events_label_start', 'Start date'); ?></label>
                                <input type="date" name="start_date" class="wow-input" required value="<?php echo htmlspecialchars($row['start_date']); ?>">
                            </div>
                            <div>
                                <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_events_label_end', 'End date'); ?></label>
                                <input type="date" name="end_date" class="wow-input" required value="<?php echo htmlspecialchars($row['end_date']); ?>">
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-300">
                            <input type="checkbox" name="is_active" value="1" <?php echo $rowActive ? 'checked' : ''; ?>>
                            <?php echo translate('admin_events_label_active', 'Published (visible on home calendar)'); ?>
                        </label>
                        <div class="flex gap-3 pt-2">
                            <button type="submit" class="btn-game btn-gold"><?php echo translate('admin_events_save', 'Save changes'); ?></button>
                            <button type="button" class="btn-game btn-iron" onclick="closeModal('editModal-<?php echo $rid; ?>')"><?php echo translate('admin_downloads_cancel_btn', 'Cancel'); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="deleteModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="wow-panel w-full max-w-md p-6 relative">
                    <h3 class="wow-title text-lg mb-4"><?php echo translate('admin_events_delete_heading', 'Delete event'); ?></h3>
                    <p class="text-gray-400 text-sm mb-6"><?php echo translate('admin_events_confirm_delete', 'Remove this event from the calendar?'); ?></p>
                    <form method="post" action="<?php echo $formAction; ?>" class="flex gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                        <button type="submit" class="btn-game btn-danger"><?php echo translate('admin_events_delete', 'Delete'); ?></button>
                        <button type="button" class="btn-game btn-iron" onclick="closeModal('deleteModal-<?php echo $rid; ?>')"><?php echo translate('admin_downloads_cancel_btn', 'Cancel'); ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </main>
</div>

<script>
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}
function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal-backdrop[id]').forEach(function (modal) {
        document.body.appendChild(modal);
    });
    document.querySelectorAll('.modal-backdrop[id]').forEach(function (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal(modal.id);
            }
        });
    });
});
</script>

<?php include $project_root . 'includes/footer.php'; ?>
