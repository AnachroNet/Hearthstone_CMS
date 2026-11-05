<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/admin_teleport_locations.php';
require_once $project_root . 'includes/admin_page_styles.php';
require_once $project_root . 'includes/admin_subtabs.php';
require_once $project_root . 'includes/admin_pagination.php';

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
                header('Location: ' . $base_path . 'admin/teleport_locations?subtab=list&status=deleted');
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
                header('Location: ' . $base_path . 'admin/teleport_locations?subtab=list&status=added');
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
                    header('Location: ' . $base_path . 'admin/teleport_locations?subtab=list&status=updated');
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

$teleport_subtab = sahtout_admin_resolve_subtab(['list', 'add'], 'list');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flash !== '' && str_starts_with($flash, 'error:')) {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'add' || $postAction === 'update') {
        $teleport_subtab = 'add';
    }
}

$allLocations = $tableReady ? sahtout_fetch_admin_teleport_locations($site_db, false) : [];
$teleport_list_per_page = 10;
$teleportPagination = sahtout_admin_pagination_state(count($allLocations), $teleport_list_per_page, 'page');
$teleportListPage = $teleportPagination['current_page'];
$teleportTotalPages = $teleportPagination['total_pages'];
$pagedLocations = array_slice(
    $allLocations,
    $teleportPagination['offset'],
    $teleportPagination['items_per_page']
);

$page_title = translate('admin_teleport_locations_page_title', 'Teleport Locations');
$page_meta_description = translate('admin_teleport_locations_meta', 'Manage fixed teleport destinations for character management');
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
?>
<div class="flex relative min-h-screen">
    <?php include $project_root . 'includes/admin_sidebar.php'; ?>
    <main class="main-content-area flex-1 p-3 sm:p-4 md:p-6 lg:p-8 transition-all duration-300 lg:ml-[280px]">
        <div class="content-wrapper">
            <div class="space-y-4 md:space-y-6 lg:space-y-8">
            <h1 class="wow-title text-2xl md:text-3xl lg:text-4xl"><?php echo translate('admin_teleport_locations_heading', 'Teleport Locations'); ?></h1>
            <p class="text-gray-400 text-sm"><?php echo translate('admin_teleport_locations_help', 'These destinations appear in Character Management → Teleport Directly. Lower sort order appears first in the dropdown.'); ?></p>

            <?php echo $alertHtml; ?>

            <?php if (!$tableReady): ?>
                <div class="panel p-6 text-amber-300 border border-amber-500/30">
                    <?php echo translate('admin_teleport_locations_table_missing', 'Run SQL/Hearthstone_DATABASE/SINGLE_UPDATE/admin_teleport_locations.sql on the site database first.'); ?>
                </div>
            <?php else: ?>

            <?php
            sahtout_admin_render_subtab_nav(
                [
                    ['id' => 'list', 'label' => translate('admin_teleport_subtab_list', 'Locations'), 'icon' => 'fas fa-map-marker-alt'],
                    ['id' => 'add', 'label' => translate('admin_teleport_subtab_add', 'Add Location'), 'icon' => 'fas fa-plus'],
                ],
                $teleport_subtab,
                $base_path,
                'admin/teleport_locations',
                [],
                translate('admin_teleport_locations_heading', 'Teleport Locations')
            );
            ?>

            <div class="<?php echo sahtout_admin_subtab_panel_class('add', $teleport_subtab); ?>">
            <div class="panel p-4 md:p-6 lg:p-8">
                <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6"><?php echo translate('admin_teleport_locations_add', 'Add location'); ?></h2>
                <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="add">
                    <?php include __DIR__ . '/teleport_locations_form_fields.php'; ?>
                    <button type="submit" class="btn-gold"><i class="fas fa-plus"></i> <?php echo translate('admin_teleport_locations_create', 'Add location'); ?></button>
                </form>
            </div>
            </div>

            <div class="<?php echo sahtout_admin_subtab_panel_class('list', $teleport_subtab); ?>">
            <div class="panel p-4 md:p-6 lg:p-8 overflow-x-auto">
                <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6"><?php echo translate('admin_teleport_locations_list', 'All locations'); ?></h2>
                <?php if ($allLocations === []): ?>
                    <p class="text-gray-500"><?php echo translate('admin_teleport_locations_empty', 'No locations yet.'); ?></p>
                <?php else: ?>
                <table class="table-dark">
                    <thead>
                        <tr>
                            <th><?php echo translate('admin_teleport_locations_col_name', 'Name'); ?></th>
                            <th class="col-num"><?php echo translate('admin_teleport_locations_col_map', 'Map'); ?></th>
                            <th><?php echo translate('admin_teleport_locations_col_coords', 'Coordinates'); ?></th>
                            <th class="col-num"><?php echo translate('admin_teleport_locations_col_sort', 'Order'); ?></th>
                            <th class="align-center"><?php echo translate('admin_teleport_locations_col_status', 'Status'); ?></th>
                            <th><?php echo translate('admin_teleport_locations_col_visibility', 'Visibility'); ?></th>
                            <th class="col-actions align-right"><?php echo translate('admin_teleport_locations_col_actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedLocations as $row): ?>
                        <tr>
                            <td class="font-semibold text-white"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td class="col-num"><?php echo (int) $row['map']; ?></td>
                            <td class="text-xs text-gray-400 font-mono">
                                <?php echo number_format($row['position_x'], 2); ?>,
                                <?php echo number_format($row['position_y'], 2); ?>,
                                <?php echo number_format($row['position_z'], 2); ?>
                            </td>
                            <td class="col-num"><?php echo (int) $row['sort_order']; ?></td>
                            <td class="align-center">
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
                            <td class="actions-cell">
                                <div class="action-buttons">
                                    <button type="button" onclick="openModal('editModal-<?php echo (int) $row['id']; ?>')" class="btn-iron btn-iron-sm text-xs py-2 px-3">
                                        <i class="fas fa-edit"></i> <?php echo translate('admin_teleport_locations_edit_short', 'Edit'); ?>
                                    </button>
                                    <button type="button" onclick="openModal('deleteModal-<?php echo (int) $row['id']; ?>')" class="btn-danger btn-danger-sm text-xs py-2 px-3">
                                        <i class="fas fa-trash"></i> <?php echo translate('admin_teleport_locations_delete', 'Delete'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                sahtout_admin_render_pagination_nav([
                    'current_page' => $teleportListPage,
                    'total_pages' => $teleportTotalPages,
                    'base_path' => $base_path,
                    'route' => 'admin/teleport_locations',
                    'query' => ['subtab' => 'list'],
                    'aria_label' => translate('admin_teleport_locations_pagination_aria', 'Teleport list pagination'),
                ]);
                ?>
                <?php endif; ?>
            </div>

            <?php foreach ($allLocations as $row):
                $rid = (int) $row['id'];
            ?>
            <div id="editModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="panel w-full max-w-2xl p-6 md:p-8 relative my-8 max-h-[90vh] overflow-y-auto">
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
                            <button type="submit" class="btn-gold"><?php echo translate('admin_teleport_locations_save', 'Save changes'); ?></button>
                            <button type="button" class="btn-iron btn-iron-sm" onclick="closeModal('editModal-<?php echo $rid; ?>')"><?php echo translate('admin_teleport_locations_cancel', 'Cancel'); ?></button>
                        </div>
                    </form>
                </div>
            </div>
            <div id="deleteModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="panel w-full max-w-md p-6 relative">
                    <h3 class="wow-title text-lg mb-4"><?php echo translate('admin_teleport_locations_delete_heading', 'Delete location'); ?></h3>
                    <p class="text-gray-400 text-sm mb-6"><?php echo translate('admin_teleport_locations_confirm_delete', 'Remove this teleport destination?'); ?></p>
                    <form method="post" action="<?php echo $formAction; ?>" class="flex justify-end gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                        <button type="button" class="btn-iron btn-iron-sm" onclick="closeModal('deleteModal-<?php echo $rid; ?>')"><?php echo translate('admin_teleport_locations_cancel', 'Cancel'); ?></button>
                        <button type="submit" class="btn-danger btn-danger-sm"><?php echo translate('admin_teleport_locations_delete', 'Delete'); ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

            </div><!-- /teleport list subtab -->

            <?php endif; ?>
            </div>
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
