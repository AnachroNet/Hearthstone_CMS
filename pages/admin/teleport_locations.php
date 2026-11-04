<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/admin_teleport_locations.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

$page_class = 'teleport_locations';
global $site_db;

$tableReady = isset($site_db) && $site_db instanceof mysqli && sahtout_admin_teleport_locations_table_exists($site_db);
$formAction = htmlspecialchars($base_path . 'admin/teleport_locations', ENT_QUOTES, 'UTF-8');
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    $action = (string) ($_POST['action'] ?? '');
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $flash = 'error:' . translate('error_csrf_invalid', 'Invalid CSRF token.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $site_db->prepare('DELETE FROM admin_teleport_locations WHERE id = ?');
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                header('Location: ' . $base_path . 'admin/teleport_locations?status=deleted');
                exit;
            }
            $flash = 'error:' . translate('admin_teleport_locations_save_failed', 'Could not save changes.');
        }
    } elseif ($action === 'add' || $action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $map = max(0, (int) ($_POST['map'] ?? 0));
        $posX = (float) ($_POST['position_x'] ?? 0);
        $posY = (float) ($_POST['position_y'] ?? 0);
        $posZ = (float) ($_POST['position_z'] ?? 0);
        $orientation = (float) ($_POST['orientation'] ?? 0);
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isGmOnly = isset($_POST['is_gm_only']) ? 1 : 0;

        if ($name === '' || ($posX == 0.0 && $posY == 0.0)) {
            $flash = 'error:' . translate('admin_teleport_locations_validation', 'Name and non-zero X/Y coordinates are required.');
        } elseif ($action === 'add') {
            $stmt = $site_db->prepare(
                'INSERT INTO admin_teleport_locations (name, map, position_x, position_y, position_z, orientation, sort_order, is_active, is_gm_only)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('siddddiii', $name, $map, $posX, $posY, $posZ, $orientation, $sortOrder, $isActive, $isGmOnly);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                header('Location: ' . $base_path . 'admin/teleport_locations?status=added');
                exit;
            }
            $flash = 'error:' . translate('admin_teleport_locations_save_failed', 'Could not save changes.');
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $site_db->prepare(
                    'UPDATE admin_teleport_locations SET name = ?, map = ?, position_x = ?, position_y = ?, position_z = ?, orientation = ?, sort_order = ?, is_active = ?, is_gm_only = ? WHERE id = ?'
                );
                $stmt->bind_param('siddddiiii', $name, $map, $posX, $posY, $posZ, $orientation, $sortOrder, $isActive, $isGmOnly, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    header('Location: ' . $base_path . 'admin/teleport_locations?status=updated');
                    exit;
                }
                $flash = 'error:' . translate('admin_teleport_locations_save_failed', 'Could not save changes.');
            }
        }
    }
}

if (isset($_GET['status'])) {
    $flash = match ((string) $_GET['status']) {
        'added' => 'success:' . translate('admin_teleport_locations_added', 'Teleport location added.'),
        'updated' => 'success:' . translate('admin_teleport_locations_updated', 'Teleport location updated.'),
        'deleted' => 'success:' . translate('admin_teleport_locations_deleted', 'Teleport location removed.'),
        default => '',
    };
}

$allLocations = $tableReady ? sahtout_fetch_admin_teleport_locations($site_db, false) : [];

$page_title = translate('admin_teleport_locations_page_title', 'Teleport Locations');
$page_meta_description = translate('admin_teleport_locations_meta', 'Manage fixed teleport destinations for character management');
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
            background: linear-gradient(180deg, rgba(22,25,32,.92), rgba(8,10,14,.9));
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 12px 32px rgba(0,0,0,.55);
        }
        .wow-title { font-family: 'Cinzel', serif; font-weight: 900; color: #f2cf5b; }
        .wow-input, .wow-select {
            width: 100%;
            padding: .65rem .85rem;
            background: rgba(0,0,0,.45);
            border: 1px solid rgba(201,162,39,.25);
            color: #e5e7eb;
        }
        .table-dark { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .table-dark th {
            text-align: left;
            padding: .75rem 1rem;
            color: #f2cf5b;
            font-size: .7rem;
            text-transform: uppercase;
            border-bottom: 1px solid rgba(201,162,39,.2);
        }
        .table-dark td { padding: .75rem 1rem; border-bottom: 1px solid rgba(201,162,39,.08); }
        .btn-game { display: inline-flex; align-items: center; gap: .4rem; padding: .55rem 1rem; font-weight: 700; font-size: .75rem; text-transform: uppercase; cursor: pointer; border: 1px solid rgba(201,162,39,.35); }
        .btn-gold { background: linear-gradient(180deg, #f6d478, #c9a227); color: #1a1200; }
        .btn-iron { background: rgba(0,0,0,.35); color: #e5e7eb; }
        .btn-danger { background: rgba(127,29,29,.5); color: #fecaca; border-color: rgba(248,113,113,.35); }
        .badge-active { color: #86efac; font-size: .75rem; }
        .badge-inactive { color: #9ca3af; font-size: .75rem; }
        .modal-backdrop { background: rgba(0,0,0,.85); backdrop-filter: blur(8px); }
        .main-content-area { min-height: calc(100vh - 72px); }
        @media (min-width: 1024px) { .main-content-area.lg\:ml-70 { margin-left: 280px; } }
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
?>
<div class="flex relative min-h-screen">
    <?php include $project_root . 'includes/admin_sidebar.php'; ?>
    <main class="main-content-area flex-1 p-4 md:p-8 lg:ml-70">
        <div class="max-w-5xl mx-auto space-y-8">
            <h1 class="wow-title text-2xl md:text-3xl"><?php echo translate('admin_teleport_locations_heading', 'Teleport Locations'); ?></h1>
            <p class="text-gray-400 text-sm"><?php echo translate('admin_teleport_locations_help', 'These destinations appear in Character Management → Teleport Directly. Lower sort order appears first in the dropdown.'); ?></p>

            <?php echo $alertHtml; ?>

            <?php if (!$tableReady): ?>
                <div class="wow-panel p-6 text-amber-300 border border-amber-500/30">
                    <?php echo translate('admin_teleport_locations_table_missing', 'Run SQL/Sahtout_site DATABASE/SINGLE UPDATE/admin_teleport_locations.sql on the site database first.'); ?>
                </div>
            <?php else: ?>

            <div class="wow-panel p-6">
                <h2 class="text-[#f2cf5b] font-['Cinzel'] text-lg mb-4"><?php echo translate('admin_teleport_locations_add', 'Add location'); ?></h2>
                <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="add">
                    <?php include __DIR__ . '/teleport_locations_form_fields.php'; ?>
                    <button type="submit" class="btn-game btn-gold"><i class="fas fa-plus"></i> <?php echo translate('admin_teleport_locations_create', 'Add location'); ?></button>
                </form>
            </div>

            <div class="wow-panel p-6 overflow-x-auto">
                <h2 class="text-[#f2cf5b] font-['Cinzel'] text-lg mb-4"><?php echo translate('admin_teleport_locations_list', 'All locations'); ?></h2>
                <?php if ($allLocations === []): ?>
                    <p class="text-gray-500"><?php echo translate('admin_teleport_locations_empty', 'No locations yet.'); ?></p>
                <?php else: ?>
                <table class="table-dark">
                    <thead>
                        <tr>
                            <th><?php echo translate('admin_teleport_locations_col_name', 'Name'); ?></th>
                            <th><?php echo translate('admin_teleport_locations_col_map', 'Map'); ?></th>
                            <th><?php echo translate('admin_teleport_locations_col_coords', 'Coordinates'); ?></th>
                            <th><?php echo translate('admin_teleport_locations_col_sort', 'Order'); ?></th>
                            <th><?php echo translate('admin_teleport_locations_col_status', 'Status'); ?></th>
                            <th><?php echo translate('admin_teleport_locations_col_visibility', 'Visibility'); ?></th>
                            <th class="text-right"><?php echo translate('admin_teleport_locations_col_actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allLocations as $row): ?>
                        <tr>
                            <td class="font-semibold text-white"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo (int) $row['map']; ?></td>
                            <td class="text-xs text-gray-400 font-mono">
                                <?php echo number_format($row['position_x'], 2); ?>,
                                <?php echo number_format($row['position_y'], 2); ?>,
                                <?php echo number_format($row['position_z'], 2); ?>
                            </td>
                            <td><?php echo (int) $row['sort_order']; ?></td>
                            <td>
                                <?php if ($row['is_active']): ?>
                                    <span class="badge-active"><?php echo translate('admin_teleport_locations_status_active', 'Active'); ?></span>
                                <?php else: ?>
                                    <span class="badge-inactive"><?php echo translate('admin_teleport_locations_status_hidden', 'Hidden'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['is_gm_only'])): ?>
                                    <span class="text-amber-300/90 text-xs"><?php echo translate('admin_teleport_locations_visibility_gm', 'GM only'); ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs"><?php echo translate('admin_teleport_locations_visibility_all', 'All players'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="flex justify-end gap-2 flex-wrap">
                                    <button type="button" onclick="openModal('editModal-<?php echo (int) $row['id']; ?>')" class="btn-game btn-iron text-xs py-2 px-3">
                                        <i class="fas fa-edit"></i> <?php echo translate('admin_teleport_locations_edit_short', 'Edit'); ?>
                                    </button>
                                    <button type="button" onclick="openModal('deleteModal-<?php echo (int) $row['id']; ?>')" class="btn-game btn-danger text-xs py-2 px-3">
                                        <i class="fas fa-trash"></i> <?php echo translate('admin_teleport_locations_delete', 'Delete'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php foreach ($allLocations as $row):
                $rid = (int) $row['id'];
            ?>
            <div id="editModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="wow-panel w-full max-w-2xl p-6 md:p-8 relative my-8 max-h-[90vh] overflow-y-auto">
                    <button type="button" class="absolute top-4 right-4 text-gray-400 hover:text-white text-2xl" onclick="closeModal('editModal-<?php echo $rid; ?>')">&times;</button>
                    <h3 class="wow-title text-xl mb-6"><?php echo translate('admin_teleport_locations_edit', 'Edit location'); ?>: <?php echo htmlspecialchars($row['name']); ?></h3>
                    <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                        <?php
                        $teleportFormRow = $row;
                        include __DIR__ . '/teleport_locations_form_fields.php';
                        ?>
                        <div class="flex gap-3 pt-2">
                            <button type="submit" class="btn-game btn-gold"><?php echo translate('admin_teleport_locations_save', 'Save changes'); ?></button>
                            <button type="button" class="btn-game btn-iron" onclick="closeModal('editModal-<?php echo $rid; ?>')"><?php echo translate('admin_teleport_locations_cancel', 'Cancel'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
            <div id="deleteModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="wow-panel w-full max-w-md p-6 relative">
                    <h3 class="wow-title text-lg mb-4"><?php echo translate('admin_teleport_locations_delete_heading', 'Delete location'); ?></h3>
                    <p class="text-gray-400 text-sm mb-6"><?php echo translate('admin_teleport_locations_confirm_delete', 'Remove this teleport destination?'); ?></p>
                    <form method="post" action="<?php echo $formAction; ?>" class="flex justify-end gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                        <button type="button" class="btn-game btn-iron" onclick="closeModal('deleteModal-<?php echo $rid; ?>')"><?php echo translate('admin_teleport_locations_cancel', 'Cancel'); ?></button>
                        <button type="submit" class="btn-game btn-danger"><?php echo translate('admin_teleport_locations_delete', 'Delete'); ?></button>
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
    var el = document.getElementById(id);
    if (el) { el.classList.remove('hidden'); el.classList.add('flex'); }
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) { el.classList.add('hidden'); el.classList.remove('flex'); }
}
</script>
<?php include $project_root . 'includes/footer.php'; ?>
