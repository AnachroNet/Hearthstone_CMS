<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    $_SESSION['debug_errors'] = [translate('error_access_denied', 'Access denied.')];
    header("Location: {$base_path}login");
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['debug_errors'] = [translate('error_csrf_invalid', 'Invalid CSRF token.')];
    header("Location: {$base_path}admin/settings/general?status=error&message=" . urlencode(translate('error_csrf_invalid', 'Invalid CSRF token.')));
    exit;
}

require_once $project_root . 'includes/site_settings.php';

function normalizeYouTubeEmbedUrl(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('~^(?:https?:)?//(?:www\.)?youtube\.com/embed/([A-Za-z0-9_-]{11})(?:[?&].*)?$~', $value, $matches)) {
        return 'https://www.youtube.com/embed/' . $matches[1] . '?rel=0&modestbranding=1';
    }

    if (preg_match('~^(?:https?:)?//(?:www\.)?youtu\.be/([A-Za-z0-9_-]{11})(?:[?&].*)?$~', $value, $matches)) {
        return 'https://www.youtube.com/embed/' . $matches[1] . '?rel=0&modestbranding=1';
    }

    if (preg_match('~^(?:https?:)?//(?:www\.)?youtube\.com/watch\?v=([A-Za-z0-9_-]{11})(?:[&?].*)?$~', $value, $matches)) {
        return 'https://www.youtube.com/embed/' . $matches[1] . '?rel=0&modestbranding=1';
    }

    if (preg_match('~^(?:https?:)?//(?:www\.)?youtube\.com/shorts/([A-Za-z0-9_-]{11})(?:[?&].*)?$~', $value, $matches)) {
        return 'https://www.youtube.com/embed/' . $matches[1] . '?rel=0&modestbranding=1';
    }

    return '';
}

// ==================== NEW: Handle Website Title ====================
$site_title_name_new = trim($_POST['site_title_name'] ?? '');
if (empty($site_title_name_new)) {
    $site_title_name_new = 'SahtoutCMS'; // fallback if empty
}
// Sanitize: prevent PHP injection when writing to config
$site_title_name_new = trim($site_title_name_new);

$youtube_embed_url_new = normalizeYouTubeEmbedUrl((string)($_POST['youtube_embed_url'] ?? ''));
if ($youtube_embed_url_new === '') {
    $youtube_embed_url_new = $youtube_embed_url;
}

$youtube_title_new = trim($_POST['youtube_title'] ?? '');
if ($youtube_title_new === '') {
    $youtube_title_new = 'Featured Video';
}
$youtube_title_new = trim($youtube_title_new);

$youtube_description_new = trim($_POST['youtube_description'] ?? '');
if ($youtube_description_new === '') {
    $youtube_description_new = 'Watch a featured video here. Replace it with your own channel or highlight later.';
}
$youtube_description_new = trim($youtube_description_new);

// Initialize variables
$errors = [];
$success = false;

// Handle logo upload (YOUR ORIGINAL CODE - UNTOUCHED)
$logo_path = $site_logo;
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['logo']['tmp_name'];
    $file_name = $_FILES['logo']['name'];
    $file_size = $_FILES['logo']['size'];
    $file_type = $_FILES['logo']['type'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $allowed_exts = ['png', 'svg', 'jpg', 'jpeg'];
    $max_size = 3 * 1024 * 1024;

    if ($file_size > $max_size) {
        $errors[] = translate('error_file_too_large', 'File size exceeds 3MB.');
    }
    elseif (!in_array($file_ext, $allowed_exts)) {
        $errors[] = translate('error_invalid_file_type', 'Invalid file type. Only PNG, SVG, or JPG allowed.');
    }
    elseif ($file_ext === 'png' && $file_type !== 'image/png') {
        $errors[] = translate('error_invalid_file_type', 'Invalid PNG file. MIME type must be image/png.');
    }
    elseif (in_array($file_ext, ['jpg', 'jpeg']) && !in_array($file_type, ['image/jpeg', 'image/jpg'])) {
        $errors[] = translate('error_invalid_file_type', 'Invalid JPG file. MIME type must be image/jpeg or image/jpg.');
    }
    elseif ($file_ext === 'svg' && $file_type !== 'image/svg+xml') {
        $errors[] = translate('error_invalid_file_type', 'Invalid SVG file. MIME type must be image/svg+xml.');
    }
    else {
        $upload_dir = $project_root . 'img/';
        if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
            $errors[] = translate('error_file_upload_failed', 'Upload directory is not accessible or writable.');
        } else {
            $new_file_name = 'logo.' . $file_ext;
            $destination = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp, $destination)) {
                $logo_path = "img/$new_file_name";
            } else {
                $errors[] = translate('error_file_upload_failed', 'Failed to upload logo. Check server permissions.');
            }
        }
    }
} elseif (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    // Your full upload error handling (kept 100% intact)
    switch ($_FILES['logo']['error']) {
        case UPLOAD_ERR_INI_SIZE:
            $errors[] = translate('error_file_too_large', 'Logo file exceeds server upload limit (3MB).');
            break;
        case UPLOAD_ERR_FORM_SIZE:
            $errors[] = translate('error_file_too_large', 'Logo file exceeds form limit (3MB).');
            break;
        case UPLOAD_ERR_PARTIAL:
            $errors[] = translate('error_file_upload_failed', 'Logo file was only partially uploaded.');
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            $errors[] = translate('error_file_upload_failed', 'Server error: Temporary directory missing.');
            break;
        case UPLOAD_ERR_CANT_WRITE:
            $errors[] = translate('error_file_upload_failed', 'Server error: Failed to write file to disk.');
            break;
        default:
            $errors[] = translate('error_file_upload_failed', 'Error uploading logo: Code ' . $_FILES['logo']['error']);
    }
}

$socialPlatforms = ['facebook', 'twitter', 'tiktok', 'youtube', 'discord', 'twitch', 'kick', 'instagram', 'github', 'linkedin'];
$social_links_new = [];
foreach ($socialPlatforms as $platform) {
    $raw = trim((string) ($_POST[$platform] ?? ''));
    $social_links_new[$platform] = sahtout_normalize_external_url($raw);
}

$discord_guild_id_new = preg_replace('/\D/', '', trim($_POST['discord_guild_id'] ?? ''));
$discord_widget_enabled_new = isset($_POST['discord_widget_enabled']);

if (empty($errors)) {
    if (!isset($site_db) || !($site_db instanceof mysqli)) {
        $errors[] = translate('error_database', 'Database error. Please try again later.');
    } elseif (!sahtout_site_settings_table_exists($site_db)) {
        $errors[] = translate('error_file_write_failed', 'Site settings table is missing. Import site_settings.sql on sahtout_site.');
    } else {
        $editorId = (int) ($_SESSION['user_id'] ?? 0);
        $ok = true;
        $ok = $ok && sahtout_site_setting_set($site_db, 'site.title', $site_title_name_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'site.logo_path', $logo_path, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'home.youtube_embed_url', $youtube_embed_url_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'home.youtube_title', $youtube_title_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'home.youtube_description', $youtube_description_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'social.links', $social_links_new, 'json', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'discord.guild_id', $discord_guild_id_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'discord.widget_enabled', $discord_widget_enabled_new, 'bool', $editorId);

        if ($ok) {
            $success = true;
        } else {
            $errors[] = translate('error_file_write_failed', 'Failed to update site settings.');
        }
    }
}

// Redirect
$redirect_url = "{$base_path}admin/settings/general";
if ($success) {
    header("Location: $redirect_url?status=success");
} else {
    $_SESSION['debug_errors'] = $errors;
    header("Location: $redirect_url?status=error&message=" . urlencode(implode(', ', $errors)));
}
exit;
?>