<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/page_backgrounds.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'], true)) {
    header("Location: {$base_path}login");
    exit;
}

$redirectBase = $base_path . 'admin/settings/images';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectBase);
    exit;
}

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if ($csrfToken === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrfToken)) {
    header('Location: ' . $redirectBase . '?status=error&message=' . urlencode(translate('error_csrf_invalid', 'Invalid CSRF token.')));
    exit;
}

$key = (string) ($_POST['background_key'] ?? '');
if (sahtout_page_background_catalog_entry($key) === null) {
    header('Location: ' . $redirectBase . '?status=error&message=' . urlencode(translate('err_bg_invalid_key', 'Unknown page background.')));
    exit;
}

$file = $_FILES['background_image'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    header('Location: ' . $redirectBase . '?status=error&message=' . urlencode(translate('err_bg_no_file', 'Please choose an image to upload.')));
    exit;
}

$result = sahtout_save_page_background_upload($key, $file);
if (!$result['ok']) {
    $errorKey = match ($result['error']) {
        'size' => 'err_bg_upload_size',
        'type' => 'err_bg_upload_type',
        'image' => 'err_bg_upload_image',
        'write' => 'err_bg_upload_write',
        default => 'err_bg_upload_failed',
    };
    header('Location: ' . $redirectBase . '?status=error&message=' . urlencode(translate($errorKey, 'Could not save the background image.')));
    exit;
}

header('Location: ' . $redirectBase . '?status=success&bg=' . rawurlencode($key));
exit;
