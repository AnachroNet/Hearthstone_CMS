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
require_once $project_root . 'includes/token_gift_settings.php';

// ==================== NEW: Handle Website Title ====================
$site_title_name_new = trim($_POST['site_title_name'] ?? '');
if (empty($site_title_name_new)) {
    $site_title_name_new = 'SahtoutCMS'; // fallback if empty
}
// Sanitize: prevent PHP injection when writing to config
$site_title_name_new = trim($site_title_name_new);

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
$site_breaking_news_enabled_new = isset($_POST['site_breaking_news_enabled']);
$registration_allow_shared_email_new = isset($_POST['registration_allow_shared_email']);
$registration_signup_tokens_enabled_new = isset($_POST['registration_signup_tokens_enabled']);
$registration_signup_tokens_amount_new = max(0, min(9999999, (int) ($_POST['registration_signup_tokens_amount'] ?? 0)));

$shop_token_gifts_enabled_new = ($_SESSION['role'] ?? '') === 'admin' ? isset($_POST['shop_token_gifts_enabled']) : null;

$shop_token_gift_loot_ids_raw = trim((string) ($_POST['shop_token_gift_loot_ids'] ?? ''));
$shop_token_gift_loot_ids_new = preg_replace('/[^0-9,\s;]+/', '', $shop_token_gift_loot_ids_raw) ?? '';
$shop_token_gift_world_ids_raw = trim((string) ($_POST['shop_token_gift_world_ids'] ?? ''));
$shop_token_gift_world_ids_new = preg_replace('/[^0-9,\s;]+/', '', $shop_token_gift_world_ids_raw) ?? '';
$shop_token_gift_item_template_ref_new = max(1, min(9999999, (int) ($_POST['shop_token_gift_item_template_ref'] ?? 2223)));
$shop_token_gift_quest_template_ref_new = max(0, min(9999999, (int) ($_POST['shop_token_gift_quest_template_ref'] ?? 0)));
$site_timezone_new = trim((string) ($_POST['site_timezone'] ?? ''));
if (!in_array($site_timezone_new, sahtout_site_timezone_ids(), true)) {
    $errors[] = translate('error_invalid_timezone', 'Please choose a timezone from the list.');
}

if (empty($errors)) {
    if (!isset($site_db) || !($site_db instanceof mysqli)) {
        $errors[] = translate('error_database', 'Database error. Please try again later.');
    } elseif (!sahtout_site_settings_table_exists($site_db)) {
        $errors[] = translate('error_file_write_failed', 'Site settings table is missing. Import site_settings.sql on hearthstone_site.');
    } else {
        $editorId = (int) ($_SESSION['user_id'] ?? 0);
        $ok = true;
        $ok = $ok && sahtout_site_setting_set($site_db, 'site.title', $site_title_name_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'site.logo_path', $logo_path, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'social.links', $social_links_new, 'json', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'discord.guild_id', $discord_guild_id_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'discord.widget_enabled', $discord_widget_enabled_new, 'bool', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'site.breaking_news_enabled', $site_breaking_news_enabled_new, 'bool', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'site.timezone', $site_timezone_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'registration.allow_shared_email', $registration_allow_shared_email_new, 'bool', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'registration.signup_tokens_enabled', $registration_signup_tokens_enabled_new, 'bool', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'registration.signup_tokens_amount', $registration_signup_tokens_amount_new, 'int', $editorId);
        if ($shop_token_gifts_enabled_new !== null) {
            $ok = $ok && sahtout_site_setting_set($site_db, 'shop.token_gifts_enabled', $shop_token_gifts_enabled_new, 'bool', $editorId);
        }
        $ok = $ok && sahtout_site_setting_set($site_db, 'shop.token_gift_loot_ids', $shop_token_gift_loot_ids_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'shop.token_gift_world_ids', $shop_token_gift_world_ids_new, 'string', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'shop.token_gift_item_template_ref', $shop_token_gift_item_template_ref_new, 'int', $editorId);
        $ok = $ok && sahtout_site_setting_set($site_db, 'shop.token_gift_quest_template_ref', $shop_token_gift_quest_template_ref_new, 'int', $editorId);
        if (($_SESSION['role'] ?? '') === 'admin') {
            $shop_token_gift_admin_access_new = trim((string) ($_POST['shop_token_gift_admin_access'] ?? SAHTOUT_TOKEN_GIFT_ACCESS_ADMIN_ONLY));
            if (!in_array($shop_token_gift_admin_access_new, sahtout_token_gift_admin_access_modes(), true)) {
                $shop_token_gift_admin_access_new = SAHTOUT_TOKEN_GIFT_ACCESS_ADMIN_ONLY;
            }
            $ok = $ok && sahtout_site_setting_set(
                $site_db,
                'shop.token_gift_admin_access',
                $shop_token_gift_admin_access_new,
                'string',
                $editorId
            );
        }

        if ($ok && $registration_allow_shared_email_new) {
            if (!sahtout_pending_accounts_drop_unique_email($site_db)
                || !sahtout_password_resets_ensure_username_column($site_db)) {
                $errors[] = translate('error_shared_email_index', 'Could not update the pending signups table for shared emails—required schema may be missing. Re-import SQL/Hearthstone_DATABASE/FULL_UPDATE/hearthstone_site_install_demo.sql into your site database.');
                $ok = false;
            }
        }

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