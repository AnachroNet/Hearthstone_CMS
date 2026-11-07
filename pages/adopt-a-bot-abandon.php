<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'includes/adopt_a_bot.php';
require_once $project_root . 'languages/language.php';

global $site_db, $base_path;

if (empty($_SESSION['user_id'])) {
    header('Location: ' . $base_path . 'login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $base_path . 'account');
    exit;
}

if (
    empty($_POST['csrf_token'])
    || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $_POST['csrf_token'])
) {
    $_SESSION['error'] = 'Invalid form token.';
    header('Location: ' . $base_path . 'account');
    exit;
}

$accountId = (int) $_SESSION['user_id'];
sahtout_adopt_a_bot_claims_ensure_schema($site_db);

$open = sahtout_adopt_a_bot_get_open_claim($site_db, $accountId);
$status = (string) ($open['status'] ?? '');

if ($status === 'processing') {
    $_SESSION['error'] = translate(
        'adopt_a_bot_error_cannot_cancel_processing',
        'Adoption is already in progress and cannot be cancelled.'
    );
    header('Location: ' . sahtout_adopt_a_bot_resume_url($open, $base_path));
    exit;
}

if (sahtout_adopt_a_bot_cancel_draft_claim($site_db, $accountId)) {
    $_SESSION['message'] = translate(
        'adopt_a_bot_cancelled',
        'Adoption cancelled. No tokens were charged.'
    );
    header('Location: ' . $base_path . 'shop');
    exit;
}

if (sahtout_adopt_a_bot_revert_processing_claim($site_db, $accountId)) {
    $_SESSION['message'] = 'Adoption paused. You can choose a bot again or retry when ready.';
    header('Location: ' . $base_path . 'adopt-a-bot');
    exit;
}

$_SESSION['error'] = translate('adopt_a_bot_error_no_claim', 'You do not have an Adopt a Bot purchase ready to redeem.');
header('Location: ' . $base_path . 'shop');
exit;
