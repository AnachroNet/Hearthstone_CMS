<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/site_calendar_events.php';
require_once $project_root . 'includes/admin_page_styles.php';
require_once $project_root . 'includes/admin_subtabs.php';
require_once $project_root . 'includes/admin_pagination.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

$page_class = 'events';
global $site_db;

$tableReady = isset($site_db) && $site_db instanceof mysqli && sahtout_site_calendar_events_table_exists($site_db);
$recurrenceSchemaReady = $tableReady && sahtout_site_calendar_events_supports_recurrence($site_db);
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
                header('Location: ' . $base_path . 'admin/events?subtab=list&status=deleted');
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
            $recurrence = sahtout_site_calendar_events_recurrence_from_request($_POST);
            $recurrenceError = $recurrenceSchemaReady
                ? sahtout_site_calendar_events_validate_recurrence(
                    $recurrence['is_recurring'],
                    $recurrence['recurrence_days'],
                    $startDate,
                    $endDate
                )
                : null;
            if ($dateError !== null) {
                $flash = 'error:' . $dateError;
            } elseif ($recurrenceError !== null) {
                $flash = 'error:' . $recurrenceError;
            } elseif ($action === 'add') {
                if ($recurrenceSchemaReady) {
                    $stmt = $site_db->prepare(
                        'INSERT INTO site_calendar_events (title, description, start_date, end_date, is_recurring, recurrence_days, is_active)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param(
                        'ssssiii',
                        $title,
                        $description,
                        $startDate,
                        $endDate,
                        $recurrence['is_recurring'],
                        $recurrence['recurrence_days'],
                        $isActive
                    );
                } else {
                    $stmt = $site_db->prepare(
                        'INSERT INTO site_calendar_events (title, description, start_date, end_date, is_active)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('ssssi', $title, $description, $startDate, $endDate, $isActive);
                }
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    header('Location: ' . $base_path . 'admin/events?subtab=list&status=added');
                    exit;
                }
                $flash = 'error:' . translate('admin_events_save_failed', 'Could not save changes.');
            } else {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    if ($recurrenceSchemaReady) {
                        $stmt = $site_db->prepare(
                            'UPDATE site_calendar_events SET title = ?, description = ?, start_date = ?, end_date = ?, is_recurring = ?, recurrence_days = ?, is_active = ? WHERE id = ?'
                        );
                        $stmt->bind_param(
                            'ssssiiii',
                            $title,
                            $description,
                            $startDate,
                            $endDate,
                            $recurrence['is_recurring'],
                            $recurrence['recurrence_days'],
                            $isActive,
                            $id
                        );
                    } else {
                        $stmt = $site_db->prepare(
                            'UPDATE site_calendar_events SET title = ?, description = ?, start_date = ?, end_date = ?, is_active = ? WHERE id = ?'
                        );
                        $stmt->bind_param('ssssii', $title, $description, $startDate, $endDate, $isActive, $id);
                    }
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok) {
                        header('Location: ' . $base_path . 'admin/events?subtab=list&status=updated');
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

$events_subtab = sahtout_admin_resolve_subtab(['list', 'add'], 'list');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flash !== '' && str_starts_with($flash, 'error:')) {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'add' || $postAction === 'update') {
        $events_subtab = 'add';
    }
}

$allEvents = $tableReady ? sahtout_site_calendar_events_fetch_all($site_db, false) : [];
$events_list_per_page = 10;
$eventsPagination = sahtout_admin_pagination_state(count($allEvents), $events_list_per_page, 'page');
$eventsListPage = $eventsPagination['current_page'];
$eventsTotalPages = $eventsPagination['total_pages'];
$pagedEvents = array_slice(
    $allEvents,
    $eventsPagination['offset'],
    $eventsPagination['items_per_page']
);

$page_title = translate('admin_events_page_title', 'Event Management');
$page_meta_description = translate('admin_events_meta', 'Manage custom calendar events on the home page');
$page_meta_robots = 'noindex';

ob_start();
sahtout_admin_page_styles();
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
    return sahtout_site_calendar_events_format_schedule($row);
}
?>
<div class="flex relative min-h-screen">
    <?php include $project_root . 'includes/admin_sidebar.php'; ?>
    <main class="main-content-area flex-1 p-3 sm:p-4 md:p-6 lg:p-8 transition-all duration-300 lg:ml-[280px]">
        <div class="content-wrapper">
            <div class="space-y-4 md:space-y-6 lg:space-y-8">
            <h1 class="wow-title text-2xl md:text-3xl lg:text-4xl"><?php echo translate('admin_events_heading', 'Event Management'); ?></h1>
            <p class="text-gray-400 text-sm"><?php echo translate('admin_events_help', 'Custom events appear on the home Event Calendar alongside WoW world holidays (silver chips). Inactive events are hidden from the public calendar.'); ?></p>

            <?php echo $alertHtml; ?>

            <?php if (!$tableReady): ?>
                <div class="panel p-6 text-amber-300 border border-amber-500/30">
                    <?php echo translate('admin_events_table_missing', 'Run SQL/Sahtout_site DATABASE/SINGLE UPDATE/site_calendar_events.sql on the site database first.'); ?>
                </div>
            <?php else: ?>

            <?php if (!$recurrenceSchemaReady): ?>
                <div class="panel p-4 text-amber-300 border border-amber-500/30 mb-4">
                    <?php echo translate('admin_events_recurrence_migration', 'Run SQL/Sahtout_site DATABASE/SINGLE UPDATE/site_calendar_events_recurrence.sql to enable weekly recurring events.'); ?>
                </div>
            <?php endif; ?>

            <?php
            sahtout_admin_render_subtab_nav(
                [
                    ['id' => 'list', 'label' => translate('admin_events_subtab_list', 'Current Events'), 'icon' => 'fas fa-calendar'],
                    ['id' => 'add', 'label' => translate('admin_events_subtab_add', 'Add Event'), 'icon' => 'fas fa-plus'],
                ],
                $events_subtab,
                $base_path,
                'admin/events',
                [],
                translate('admin_events_heading', 'Event Management')
            );
            ?>

            <div class="<?php echo sahtout_admin_subtab_panel_class('add', $events_subtab); ?>">
            <div class="panel p-4 md:p-6 lg:p-8">
                <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6"><?php echo translate('admin_events_add', 'Add event'); ?></h2>
                <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="add">

                    <div>
                        <label class="form-label"><?php echo translate('admin_events_label_title', 'Title'); ?></label>
                        <input type="text" name="title" class="input-dark" maxlength="200" required>
                    </div>

                    <div>
                        <label class="form-label"><?php echo translate('admin_events_label_description', 'Description'); ?></label>
                        <textarea name="description" class="input-dark textarea-dark" rows="4"></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label"><?php echo translate('admin_events_label_start', 'Start date'); ?></label>
                            <input type="date" name="start_date" class="input-dark" required>
                        </div>
                        <div>
                            <label class="form-label"><?php echo translate('admin_events_label_end', 'End date'); ?></label>
                            <input type="date" name="end_date" class="input-dark" required>
                        </div>
                    </div>

                    <?php if ($recurrenceSchemaReady): ?>
                        <?php
                        $recurrence_form_is_recurring = false;
                        $recurrence_form_days = 0;
                        include $project_root . 'includes/admin_event_recurrence_fields.inc.php';
                        ?>
                    <?php endif; ?>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <?php echo translate('admin_events_label_active', 'Published (visible on home calendar)'); ?>
                    </label>

                    <div class="pt-2">
                        <button type="submit" class="btn-gold">
                            <i class="fas fa-plus"></i>
                            <?php echo translate('admin_events_create', 'Add event'); ?>
                        </button>
                    </div>
                </form>
            </div>
            </div>

            <div class="<?php echo sahtout_admin_subtab_panel_class('list', $events_subtab); ?>">
            <div class="panel p-4 md:p-6 lg:p-8 overflow-x-auto">
                <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6"><?php echo translate('admin_events_list', 'All events'); ?></h2>
                <?php if ($allEvents === []): ?>
                    <p class="text-gray-500"><?php echo translate('admin_events_empty', 'No custom events yet.'); ?></p>
                <?php else: ?>
                <table class="table-dark">
                    <thead>
                        <tr>
                            <th><?php echo translate('admin_events_col_title', 'Title'); ?></th>
                            <th><?php echo translate('admin_events_col_dates', 'Dates'); ?></th>
                            <th class="align-center"><?php echo translate('admin_events_col_status', 'Status'); ?></th>
                            <th class="col-actions align-right"><?php echo translate('admin_events_col_actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedEvents as $row): ?>
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
                            <td class="align-center">
                                <?php if ((int) ($row['is_active'] ?? 0) === 1): ?>
                                    <span class="badge-active"><?php echo translate('admin_events_status_active', 'Active'); ?></span>
                                <?php else: ?>
                                    <span class="badge-inactive"><?php echo translate('admin_events_status_hidden', 'Hidden'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <div class="action-buttons">
                                    <button type="button" onclick="openModal('editModal-<?php echo (int) $row['id']; ?>')" class="btn-iron btn-iron-sm text-xs py-2 px-3">
                                        <i class="fas fa-edit"></i>
                                        <?php echo translate('admin_events_edit_short', 'Edit'); ?>
                                    </button>
                                    <button type="button" onclick="openModal('deleteModal-<?php echo (int) $row['id']; ?>')" class="btn-danger btn-danger-sm text-xs py-2 px-3">
                                        <i class="fas fa-trash"></i>
                                        <?php echo translate('admin_events_delete', 'Delete'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                sahtout_admin_render_pagination_nav([
                    'current_page' => $eventsListPage,
                    'total_pages' => $eventsTotalPages,
                    'base_path' => $base_path,
                    'route' => 'admin/events',
                    'query' => ['subtab' => 'list'],
                    'aria_label' => translate('admin_events_pagination_aria', 'Event list pagination'),
                ]);
                ?>
                <?php endif; ?>
            </div>

            <?php foreach ($allEvents as $row):
                $rid = (int) $row['id'];
                $rowActive = (int) ($row['is_active'] ?? 0) === 1;
            ?>
            <div id="editModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="panel w-full max-w-2xl p-6 md:p-8 relative my-8">
                    <button type="button" class="absolute top-4 right-4 text-gray-400 hover:text-white text-2xl" onclick="closeModal('editModal-<?php echo $rid; ?>')" aria-label="Close">&times;</button>
                    <h3 class="wow-title text-xl mb-6"><?php echo translate('admin_events_edit', 'Edit event'); ?>: <?php echo htmlspecialchars($row['title']); ?></h3>
                    <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">

                        <div>
                            <label class="form-label"><?php echo translate('admin_events_label_title', 'Title'); ?></label>
                            <input type="text" name="title" class="input-dark" maxlength="200" required value="<?php echo htmlspecialchars($row['title']); ?>">
                        </div>
                        <div>
                            <label class="form-label"><?php echo translate('admin_events_label_description', 'Description'); ?></label>
                            <textarea name="description" class="input-dark textarea-dark" rows="4"><?php echo htmlspecialchars($row['description']); ?></textarea>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label"><?php echo translate('admin_events_label_start', 'Start date'); ?></label>
                                <input type="date" name="start_date" class="input-dark" required value="<?php echo htmlspecialchars($row['start_date']); ?>">
                            </div>
                            <div>
                                <label class="form-label"><?php echo translate('admin_events_label_end', 'End date'); ?></label>
                                <input type="date" name="end_date" class="input-dark" required value="<?php echo htmlspecialchars($row['end_date']); ?>">
                            </div>
                        </div>
                        <?php if ($recurrenceSchemaReady): ?>
                            <?php
                            $recurrence_form_is_recurring = (int) ($row['is_recurring'] ?? 0) === 1;
                            $recurrence_form_days = (int) ($row['recurrence_days'] ?? 0);
                            include $project_root . 'includes/admin_event_recurrence_fields.inc.php';
                            ?>
                        <?php endif; ?>
                        <label class="flex items-center gap-2 text-sm text-gray-300">
                            <input type="checkbox" name="is_active" value="1" <?php echo $rowActive ? 'checked' : ''; ?>>
                            <?php echo translate('admin_events_label_active', 'Published (visible on home calendar)'); ?>
                        </label>
                        <div class="flex gap-3 pt-2">
                            <button type="submit" class="btn-gold"><?php echo translate('admin_events_save', 'Save changes'); ?></button>
                            <button type="button" class="btn-iron btn-iron-sm" onclick="closeModal('editModal-<?php echo $rid; ?>')"><?php echo translate('admin_downloads_cancel_btn', 'Cancel'); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="deleteModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="panel w-full max-w-md p-6 relative">
                    <h3 class="wow-title text-lg mb-4"><?php echo translate('admin_events_delete_heading', 'Delete event'); ?></h3>
                    <p class="text-gray-400 text-sm mb-6"><?php echo translate('admin_events_confirm_delete', 'Remove this event from the calendar?'); ?></p>
                    <form method="post" action="<?php echo $formAction; ?>" class="flex gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                        <button type="submit" class="btn-danger btn-danger-sm"><?php echo translate('admin_events_delete', 'Delete'); ?></button>
                        <button type="button" class="btn-iron btn-iron-sm" onclick="closeModal('deleteModal-<?php echo $rid; ?>')"><?php echo translate('admin_downloads_cancel_btn', 'Cancel'); ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

            </div><!-- /events list subtab -->

            <?php endif; ?>
            </div>
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
function sahtoutSyncEventRecurrenceForm(form) {
    const toggle = form.querySelector('.event-is-recurring');
    const panel = form.querySelector('.event-recurrence-days');
    if (!toggle || !panel) {
        return;
    }
    const on = toggle.checked;
    panel.classList.toggle('hidden', !on);
    panel.querySelectorAll('.event-recurrence-day').forEach(function (cb) {
        cb.disabled = !on;
    });
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
    document.querySelectorAll('form').forEach(function (form) {
        const toggle = form.querySelector('.event-is-recurring');
        if (!toggle) {
            return;
        }
        sahtoutSyncEventRecurrenceForm(form);
        toggle.addEventListener('change', function () {
            sahtoutSyncEventRecurrenceForm(form);
        });
    });
});
</script>

<?php include $project_root . 'includes/footer.php'; ?>
