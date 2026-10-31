<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/site_downloads.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

$page_class = 'downloads';
global $site_db;

$categories = sahtout_download_categories();
$tableReady = isset($site_db) && $site_db instanceof mysqli && sahtout_downloads_table_exists($site_db);

$flash = '';
$editRow = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($tableReady && $editId > 0) {
    $stmt = $site_db->prepare('SELECT id, title, description, url, category, size_label, sort_order, is_active FROM site_downloads WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $res = $stmt->get_result();
        $editRow = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

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
            $flash = $ok ? 'success:' . translate('admin_downloads_deleted', 'Download link removed.') : 'error:' . translate('admin_downloads_save_failed', 'Could not save changes.');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($id > 0) {
            $stmt = $site_db->prepare('UPDATE site_downloads SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $active, $id);
            $ok = $stmt->execute();
            $stmt->close();
            $flash = $ok ? 'success:' . translate('admin_downloads_updated', 'Download link updated.') : 'error:' . translate('admin_downloads_save_failed', 'Could not save changes.');
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
            $flash = $ok ? 'success:' . translate('admin_downloads_added', 'Download link added.') : 'error:' . translate('admin_downloads_save_failed', 'Could not save changes.');
            if ($ok) {
                header('Location: ' . $base_path . 'admin/downloads?status=success');
                exit;
            }
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $site_db->prepare(
                    'UPDATE site_downloads SET title = ?, description = ?, url = ?, category = ?, size_label = ?, sort_order = ?, is_active = ? WHERE id = ?'
                );
                $stmt->bind_param('sssssiii', $title, $description, $url, $category, $sizeLabel, $sortOrder, $isActive, $id);
                $ok = $stmt->execute();
                $stmt->close();
                $flash = $ok ? 'success:' . translate('admin_downloads_updated', 'Download link updated.') : 'error:' . translate('admin_downloads_save_failed', 'Could not save changes.');
                if ($ok) {
                    header('Location: ' . $base_path . 'admin/downloads?status=success');
                    exit;
                }
            }
        }
    }
}

if (isset($_GET['status']) && $_GET['status'] === 'success') {
    $flash = 'success:' . translate('admin_downloads_saved', 'Changes saved.');
}

$allDownloads = $tableReady ? sahtout_fetch_downloads($site_db, false) : [];

$formAction = $editRow ? 'update' : 'add';
$formTitle = $editRow['title'] ?? '';
$formDescription = $editRow['description'] ?? '';
$formUrl = $editRow['url'] ?? '';
$formCategory = $editRow['category'] ?? 'client';
$formSize = $editRow['size_label'] ?? '';
$formSort = isset($editRow['sort_order']) ? (int) $editRow['sort_order'] : 0;
$formActive = !isset($editRow['is_active']) || (int) $editRow['is_active'] === 1;

$page_title = translate('admin_downloads_page_title', 'Download links');
$page_meta_description = translate('admin_downloads_meta', 'Manage external download links');
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
        .wow-input, .wow-select, .wow-textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(10, 14, 22, 0.85);
            border: 1px solid rgba(201,162,39,0.3);
            color: #e5e7eb;
            outline: none;
        }
        .wow-input:focus, .wow-select:focus, .wow-textarea:focus {
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
    <main class="main-content-area flex-1 p-4 md:p-8">
        <div class="max-w-5xl mx-auto space-y-8">
            <h1 class="wow-title text-2xl md:text-3xl"><?php echo translate('admin_downloads_heading', 'Download links'); ?></h1>
            <p class="text-gray-400 text-sm"><?php echo translate('admin_downloads_help', 'Add public URLs (Google Drive, torrent pages, etc.). Lower sort order appears first within each category.'); ?></p>

            <?php echo $alertHtml; ?>

            <?php if (!$tableReady): ?>
                <div class="wow-panel p-6 text-amber-300 border border-amber-500/30">
                    <?php echo translate('admin_downloads_table_missing', 'Run SQL/Sahtout_site DATABASE/SINGLE UPDATE/site_downloads.sql on the site database first.'); ?>
                </div>
            <?php else: ?>

            <div class="wow-panel p-6">
                <h2 class="text-[#f2cf5b] font-['Cinzel'] text-lg mb-4">
                    <?php echo $editRow ? translate('admin_downloads_edit', 'Edit link') : translate('admin_downloads_add', 'Add link'); ?>
                </h2>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="<?php echo htmlspecialchars($formAction); ?>">
                    <?php if ($editRow): ?>
                        <input type="hidden" name="id" value="<?php echo (int) $editRow['id']; ?>">
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_downloads_label_title', 'Title'); ?></label>
                            <input type="text" name="title" class="wow-input" maxlength="128" required value="<?php echo htmlspecialchars($formTitle); ?>">
                        </div>
                        <div>
                            <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_downloads_label_category', 'Category'); ?></label>
                            <select name="category" class="wow-select">
                                <?php foreach ($categories as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $formCategory === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_downloads_label_url', 'URL'); ?></label>
                        <input type="url" name="url" class="wow-input" required placeholder="https://..." value="<?php echo htmlspecialchars($formUrl); ?>">
                    </div>

                    <div>
                        <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_downloads_label_description', 'Description'); ?></label>
                        <textarea name="description" class="wow-textarea" rows="3"><?php echo htmlspecialchars($formDescription); ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                        <div>
                            <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_downloads_label_size', 'Approx. size'); ?></label>
                            <input type="text" name="size_label" class="wow-input" maxlength="64" placeholder="e.g. ~35 GB" value="<?php echo htmlspecialchars($formSize); ?>">
                        </div>
                        <div>
                            <label class="block text-sm text-[#f2cf5b] mb-1"><?php echo translate('admin_downloads_label_sort', 'Sort order'); ?></label>
                            <input type="number" name="sort_order" class="wow-input" value="<?php echo (int) $formSort; ?>">
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-300 pb-2">
                            <input type="checkbox" name="is_active" value="1" <?php echo $formActive ? 'checked' : ''; ?>>
                            <?php echo translate('admin_downloads_label_active', 'Published (visible on download page)'); ?>
                        </label>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="px-5 py-2.5 bg-gradient-to-b from-[#f6d478] to-[#8a6a14] text-[#1a1200] font-bold uppercase text-xs tracking-wide">
                            <?php echo $editRow ? translate('admin_downloads_save', 'Save changes') : translate('admin_downloads_create', 'Add link'); ?>
                        </button>
                        <?php if ($editRow): ?>
                            <a href="<?php echo $base_path; ?>admin/downloads" class="px-5 py-2.5 border border-[#c9a227]/40 text-[#f2cf5b] text-xs uppercase tracking-wide"><?php echo translate('admin_downloads_cancel', 'Cancel edit'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="wow-panel p-6 overflow-x-auto">
                <h2 class="text-[#f2cf5b] font-['Cinzel'] text-lg mb-4"><?php echo translate('admin_downloads_list', 'All links'); ?></h2>
                <?php if ($allDownloads === []): ?>
                    <p class="text-gray-500"><?php echo translate('admin_downloads_empty', 'No links yet.'); ?></p>
                <?php else: ?>
                <table class="table-dark">
                    <thead>
                        <tr>
                            <th><?php echo translate('admin_downloads_col_title', 'Title'); ?></th>
                            <th><?php echo translate('admin_downloads_col_category', 'Category'); ?></th>
                            <th><?php echo translate('admin_downloads_col_sort', 'Order'); ?></th>
                            <th><?php echo translate('admin_downloads_col_status', 'Status'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allDownloads as $row): ?>
                        <tr>
                            <td>
                                <div class="font-semibold text-white"><?php echo htmlspecialchars($row['title']); ?></div>
                                <?php if (!empty($row['size_label'])): ?>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($row['size_label']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($categories[$row['category']] ?? $row['category']); ?></td>
                            <td><?php echo (int) $row['sort_order']; ?></td>
                            <td>
                                <?php if ($row['is_active']): ?>
                                    <span class="badge-active"><?php echo translate('admin_downloads_status_active', 'Active'); ?></span>
                                <?php else: ?>
                                    <span class="badge-inactive"><?php echo translate('admin_downloads_status_hidden', 'Hidden'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="<?php echo $base_path; ?>admin/downloads?edit=<?php echo (int) $row['id']; ?>" class="text-[#f2cf5b] text-xs mr-3"><?php echo translate('admin_downloads_edit_short', 'Edit'); ?></a>
                                <form method="post" class="inline" onsubmit="return confirm('<?php echo htmlspecialchars(translate('admin_downloads_confirm_delete', 'Remove this link?'), ENT_QUOTES); ?>');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="text-red-400 text-xs"><?php echo translate('admin_downloads_delete', 'Delete'); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </div>
    </main>
</div>
<?php include $project_root . 'includes/footer.php'; ?>
