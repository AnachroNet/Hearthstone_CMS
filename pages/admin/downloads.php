<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/site_downloads.php';
require_once $project_root . 'includes/admin_page_styles.php';
require_once $project_root . 'includes/admin_subtabs.php';
require_once $project_root . 'includes/admin_pagination.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

$page_class = 'downloads';
global $site_db;

$categories = sahtout_download_categories();
$tableReady = isset($site_db) && $site_db instanceof mysqli && sahtout_downloads_table_exists($site_db);
$formAction = htmlspecialchars($base_path . 'admin/downloads', ENT_QUOTES, 'UTF-8');

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    $action = $_POST['action'] ?? '';
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $flash = 'error:' . translate('error_csrf_invalid', 'Invalid CSRF token.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $site_db->prepare('DELETE FROM site_downloads WHERE id = ?');
            $stmt->bind_param('i', $id);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                header('Location: ' . $base_path . 'admin/downloads?subtab=list&status=deleted');
                exit;
            }
            $flash = 'error:' . translate('admin_downloads_save_failed', 'Could not save changes.');
        }
    } elseif ($action === 'add' || $action === 'update') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $url = sahtout_normalize_download_url((string) ($_POST['url'] ?? ''));
        $category = sahtout_normalize_download_category((string) ($_POST['category'] ?? 'other'));
        $sizeLabel = trim((string) ($_POST['size_label'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '' || $url === '') {
            $flash = 'error:' . translate('admin_downloads_validation', 'Title and a valid https URL are required.');
        } elseif ($action === 'add') {
            $stmt = $site_db->prepare(
                'INSERT INTO site_downloads (title, description, url, category, size_label, sort_order, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sssssii', $title, $description, $url, $category, $sizeLabel, $sortOrder, $isActive);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                header('Location: ' . $base_path . 'admin/downloads?subtab=list&status=added');
                exit;
            }
            $flash = 'error:' . translate('admin_downloads_save_failed', 'Could not save changes.');
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $site_db->prepare(
                    'UPDATE site_downloads SET title = ?, description = ?, url = ?, category = ?, size_label = ?, sort_order = ?, is_active = ? WHERE id = ?'
                );
                $stmt->bind_param('sssssiii', $title, $description, $url, $category, $sizeLabel, $sortOrder, $isActive, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    header('Location: ' . $base_path . 'admin/downloads?subtab=list&status=updated');
                    exit;
                }
                $flash = 'error:' . translate('admin_downloads_save_failed', 'Could not save changes.');
            }
        }
    }
}

if (isset($_GET['status'])) {
    $flash = match ($_GET['status']) {
        'added' => 'success:' . translate('admin_downloads_added', 'Download link added.'),
        'updated' => 'success:' . translate('admin_downloads_updated', 'Download link updated.'),
        'deleted' => 'success:' . translate('admin_downloads_deleted', 'Download link removed.'),
        default => 'success:' . translate('admin_downloads_saved', 'Changes saved.'),
    };
}

$downloads_subtab = sahtout_admin_resolve_subtab(['list', 'add'], 'list');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flash !== '' && str_starts_with($flash, 'error:')) {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'add' || $postAction === 'update') {
        $downloads_subtab = 'add';
    }
}

$allDownloads = $tableReady ? sahtout_fetch_downloads($site_db, false) : [];
$downloads_list_per_page = 10;
$downloadsPagination = sahtout_admin_pagination_state(count($allDownloads), $downloads_list_per_page, 'page');
$downloadsListPage = $downloadsPagination['current_page'];
$downloadsTotalPages = $downloadsPagination['total_pages'];
$pagedDownloads = array_slice(
    $allDownloads,
    $downloadsPagination['offset'],
    $downloadsPagination['items_per_page']
);

$page_title = translate('admin_downloads_page_title', 'Download Management');
$page_meta_description = translate('admin_downloads_meta', 'Manage external download links');
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
            <h1 class="wow-title text-2xl md:text-3xl lg:text-4xl"><?php echo translate('admin_downloads_heading', 'Download Management'); ?></h1>
            <p class="text-gray-400 text-sm"><?php echo translate('admin_downloads_help', 'Add public URLs (Google Drive, torrent pages, etc.). Lower sort order appears first within each category.'); ?></p>

            <?php echo $alertHtml; ?>

            <?php if (!$tableReady): ?>
                <div class="panel p-6 text-amber-300 border border-amber-500/30">
                    <?php echo translate('admin_downloads_table_missing', 'The site_downloads table is missing. Re-import SQL/Hearthstone_DATABASE/FULL_UPDATE/hearthstone_site_install_demo.sql into your site database.'); ?>
                </div>
            <?php else: ?>

            <?php
            sahtout_admin_render_subtab_nav(
                [
                    ['id' => 'list', 'label' => translate('admin_downloads_subtab_list', 'Current Downloads'), 'icon' => 'fas fa-list'],
                    ['id' => 'add', 'label' => translate('admin_downloads_subtab_add', 'Add Download Link'), 'icon' => 'fas fa-plus'],
                ],
                $downloads_subtab,
                $base_path,
                'admin/downloads',
                [],
                translate('admin_downloads_heading', 'Download Management')
            );
            ?>

            <div class="<?php echo sahtout_admin_subtab_panel_class('add', $downloads_subtab); ?>">
            <div class="panel p-4 md:p-6 lg:p-8">
                <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6"><?php echo translate('admin_downloads_add', 'Add link'); ?></h2>
                <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="add">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label"><?php echo translate('admin_downloads_label_title', 'Title'); ?></label>
                            <input type="text" name="title" class="input-dark" maxlength="128" required>
                        </div>
                        <div>
                            <label class="form-label"><?php echo translate('admin_downloads_label_category', 'Category'); ?></label>
                            <select name="category" class="input-dark">
                                <?php foreach ($categories as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="form-label"><?php echo translate('admin_downloads_label_url', 'URL'); ?></label>
                        <input type="url" name="url" class="input-dark" required placeholder="https://...">
                    </div>

                    <div>
                        <label class="form-label"><?php echo translate('admin_downloads_label_description', 'Description'); ?></label>
                        <textarea name="description" class="input-dark textarea-dark" rows="3"></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                        <div>
                            <label class="form-label"><?php echo translate('admin_downloads_label_size', 'Approx. size'); ?></label>
                            <input type="text" name="size_label" class="input-dark" maxlength="64" placeholder="e.g. ~35 GB">
                        </div>
                        <div>
                            <label class="form-label"><?php echo translate('admin_downloads_label_sort', 'Sort order'); ?></label>
                            <input type="number" name="sort_order" class="input-dark" value="0">
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-300 pb-2">
                            <input type="checkbox" name="is_active" value="1" checked>
                            <?php echo translate('admin_downloads_label_active', 'Published (visible on download page)'); ?>
                        </label>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="btn-gold">
                            <i class="fas fa-plus"></i>
                            <?php echo translate('admin_downloads_create', 'Add link'); ?>
                        </button>
                    </div>
                </form>
            </div>
            </div>

            <div class="<?php echo sahtout_admin_subtab_panel_class('list', $downloads_subtab); ?>">
            <div class="panel p-4 md:p-6 lg:p-8 overflow-x-auto">
                <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6"><?php echo translate('admin_downloads_list', 'All links'); ?></h2>
                <?php if ($allDownloads === []): ?>
                    <p class="text-gray-500"><?php echo translate('admin_downloads_empty', 'No links yet.'); ?></p>
                <?php else: ?>
                <table class="table-dark">
                    <thead>
                        <tr>
                            <th><?php echo translate('admin_downloads_col_title', 'Title'); ?></th>
                            <th><?php echo translate('admin_downloads_col_category', 'Category'); ?></th>
                            <th class="col-num"><?php echo translate('admin_downloads_col_sort', 'Order'); ?></th>
                            <th class="align-center"><?php echo translate('admin_downloads_col_status', 'Status'); ?></th>
                            <th class="col-actions align-right"><?php echo translate('admin_downloads_col_actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedDownloads as $row): ?>
                        <tr>
                            <td>
                                <div class="font-semibold text-white"><?php echo htmlspecialchars($row['title']); ?></div>
                                <?php if (!empty($row['size_label'])): ?>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($row['size_label']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($categories[$row['category']] ?? $row['category']); ?></td>
                            <td class="col-num"><?php echo (int) $row['sort_order']; ?></td>
                            <td class="align-center">
                                <?php if ($row['is_active']): ?>
                                    <span class="badge-active"><?php echo translate('admin_downloads_status_active', 'Active'); ?></span>
                                <?php else: ?>
                                    <span class="badge-inactive"><?php echo translate('admin_downloads_status_hidden', 'Hidden'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <div class="action-buttons">
                                    <button type="button" onclick="openModal('editModal-<?php echo (int) $row['id']; ?>')" class="btn-iron btn-iron-sm text-xs py-2 px-3">
                                        <i class="fas fa-edit"></i>
                                        <?php echo translate('admin_downloads_edit_short', 'Edit'); ?>
                                    </button>
                                    <button type="button" onclick="openModal('deleteModal-<?php echo (int) $row['id']; ?>')" class="btn-danger btn-danger-sm text-xs py-2 px-3">
                                        <i class="fas fa-trash"></i>
                                        <?php echo translate('admin_downloads_delete', 'Delete'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                sahtout_admin_render_pagination_nav([
                    'current_page' => $downloadsListPage,
                    'total_pages' => $downloadsTotalPages,
                    'base_path' => $base_path,
                    'route' => 'admin/downloads',
                    'query' => ['subtab' => 'list'],
                    'aria_label' => translate('admin_downloads_pagination_aria', 'Download list pagination'),
                ]);
                ?>
                <?php endif; ?>
            </div>

            <?php foreach ($allDownloads as $row):
                $rid = (int) $row['id'];
                $rowActive = (int) ($row['is_active'] ?? 0) === 1;
            ?>
            <div id="editModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="panel w-full max-w-2xl p-6 md:p-8 relative my-8">
                    <button type="button" class="absolute top-4 right-4 text-gray-400 hover:text-white text-2xl" onclick="closeModal('editModal-<?php echo $rid; ?>')" aria-label="Close">&times;</button>
                    <h3 class="wow-title text-xl mb-6"><?php echo translate('admin_downloads_edit', 'Edit link'); ?>: <?php echo htmlspecialchars($row['title']); ?></h3>
                    <form method="post" action="<?php echo $formAction; ?>" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="form-label"><?php echo translate('admin_downloads_label_title', 'Title'); ?></label>
                                <input type="text" name="title" class="input-dark" maxlength="128" required value="<?php echo htmlspecialchars($row['title']); ?>">
                            </div>
                            <div>
                                <label class="form-label"><?php echo translate('admin_downloads_label_category', 'Category'); ?></label>
                                <select name="category" class="input-dark">
                                    <?php foreach ($categories as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($row['category'] ?? '') === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="form-label"><?php echo translate('admin_downloads_label_url', 'URL'); ?></label>
                            <input type="url" name="url" class="input-dark" required value="<?php echo htmlspecialchars($row['url']); ?>">
                        </div>
                        <div>
                            <label class="form-label"><?php echo translate('admin_downloads_label_description', 'Description'); ?></label>
                            <textarea name="description" class="input-dark textarea-dark" rows="3"><?php echo htmlspecialchars($row['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                            <div>
                                <label class="form-label"><?php echo translate('admin_downloads_label_size', 'Approx. size'); ?></label>
                                <input type="text" name="size_label" class="input-dark" maxlength="64" value="<?php echo htmlspecialchars($row['size_label'] ?? ''); ?>">
                            </div>
                            <div>
                                <label class="form-label"><?php echo translate('admin_downloads_label_sort', 'Sort order'); ?></label>
                                <input type="number" name="sort_order" class="input-dark" value="<?php echo (int) $row['sort_order']; ?>">
                            </div>
                            <label class="flex items-center gap-2 text-sm text-gray-300 pb-2">
                                <input type="checkbox" name="is_active" value="1" <?php echo $rowActive ? 'checked' : ''; ?>>
                                <?php echo translate('admin_downloads_label_active', 'Published (visible on download page)'); ?>
                            </label>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" class="btn-iron btn-iron-sm" onclick="closeModal('editModal-<?php echo $rid; ?>')"><?php echo translate('admin_downloads_cancel', 'Cancel edit'); ?></button>
                            <button type="submit" class="btn-gold"><?php echo translate('admin_downloads_save', 'Save changes'); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="deleteModal-<?php echo $rid; ?>" class="fixed inset-0 z-[10060] hidden items-center justify-center p-4 modal-backdrop">
                <div class="panel w-full max-w-md p-6 relative">
                    <h3 class="wow-title text-lg mb-3"><?php echo translate('admin_downloads_delete_heading', 'Delete link'); ?></h3>
                    <p class="text-gray-300 mb-6"><?php echo translate('admin_downloads_confirm_delete', 'Remove this link?'); ?><br><span class="text-[#f2cf5b] font-semibold"><?php echo htmlspecialchars($row['title']); ?></span></p>
                    <form method="post" action="<?php echo $formAction; ?>" class="flex justify-end gap-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                        <button type="button" class="btn-iron btn-iron-sm" onclick="closeModal('deleteModal-<?php echo $rid; ?>')"><?php echo translate('admin_downloads_cancel_btn', 'Cancel'); ?></button>
                        <button type="submit" class="btn-danger btn-danger-sm"><?php echo translate('admin_downloads_delete', 'Delete'); ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

            </div><!-- /downloads list subtab -->

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
