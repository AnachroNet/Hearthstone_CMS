<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'includes/adopt_a_bot.php';

global $site_db, $char_db, $auth_db, $base_path;

if (empty($_SESSION['user_id'])) {
    header('Location: ' . $base_path . 'login?return=' . rawurlencode('shop'));
    exit;
}

$accountId = (int) $_SESSION['user_id'];

if (!sahtout_adopt_a_bot_enabled($site_db)) {
    header('Location: ' . $base_path . 'shop?status=adopt_a_bot_disabled');
    exit;
}

sahtout_adopt_a_bot_claims_ensure_schema($site_db);

$existingOpen = sahtout_adopt_a_bot_get_open_claim($site_db, $accountId);
if ($existingOpen !== null) {
    header('Location: ' . sahtout_adopt_a_bot_resume_url($existingOpen, $base_path));
    exit;
}

if (!sahtout_adopt_a_bot_has_character_slot($char_db, $accountId)) {
    header('Location: ' . $base_path . 'shop?status=adopt_no_slot');
    exit;
}
if (!sahtout_adopt_a_bot_pool_configured($auth_db, $site_db)) {
    header('Location: ' . $base_path . 'shop?status=adopt_pool_missing');
    exit;
}
if (!sahtout_adopt_a_bot_pool_has_eligible_bot($char_db, $auth_db, $site_db, $accountId)) {
    header('Location: ' . $base_path . 'shop?status=adopt_no_eligible_bots');
    exit;
}

if (!sahtout_adopt_a_bot_claims_supports_unpaid_draft($site_db)) {
    $_SESSION['error'] = 'Adopt a Bot checkout needs a database update. Contact staff.';
    header('Location: ' . $base_path . 'shop');
    exit;
}

if (sahtout_adopt_a_bot_create_draft_claim($site_db, $accountId) === null) {
    $_SESSION['error'] = 'Could not start adoption. Try again or finish your current session.';
    header('Location: ' . $base_path . 'shop');
    exit;
}

header('Location: ' . $base_path . 'adopt-a-bot');
exit;
