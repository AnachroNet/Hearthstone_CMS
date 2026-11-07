<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/adopt_a_bot.php';

global $site_db, $char_db, $auth_db, $base_path;

if (empty($_SESSION['user_id'])) {
    header('Location: ' . $base_path . 'login?return=' . rawurlencode('adopt-a-bot'));
    exit;
}

$accountId = (int) $_SESSION['user_id'];
$guid = (int) ($_GET['guid'] ?? 0);
$step = (string) ($_GET['step'] ?? '');

if ($guid <= 0) {
    header('Location: ' . $base_path . 'adopt-a-bot');
    exit;
}

sahtout_adopt_a_bot_claims_ensure_schema($site_db);
$openClaim = sahtout_adopt_a_bot_get_open_claim($site_db, $accountId);
if ($openClaim === null) {
    $_SESSION['error'] = translate('adopt_a_bot_error_no_claim', 'You do not have an Adopt a Bot purchase ready to redeem.');
    header('Location: ' . $base_path . 'account');
    exit;
}

$status = (string) ($openClaim['status'] ?? '');
if ($step === 'processing') {
    if ($status !== 'processing' || (int) ($openClaim['bot_character_guid'] ?? 0) !== $guid) {
        header('Location: ' . sahtout_adopt_a_bot_resume_url($openClaim, $base_path));
        exit;
    }
    define('SAHTOUT_ADOPT_A_BOT_CHARACTER_PAGE', 'processing');
} else {
    if ($status === 'processing') {
        header('Location: ' . sahtout_adopt_a_bot_resume_url($openClaim, $base_path));
        exit;
    }
    if ($status !== 'pending') {
        header('Location: ' . $base_path . 'adopt-a-bot');
        exit;
    }
    $eligible = sahtout_adopt_a_bot_fetch_eligible_pool_character($char_db, $auth_db, $site_db, $accountId, $guid);
    if ($eligible === null) {
        $_SESSION['error'] = translate('adopt_a_bot_error_bot_missing', 'That character is no longer available.');
        header('Location: ' . $base_path . 'adopt-a-bot');
        exit;
    }
    define('SAHTOUT_ADOPT_A_BOT_CHARACTER_PAGE', 'preview');
}

require __DIR__ . '/character.php';
