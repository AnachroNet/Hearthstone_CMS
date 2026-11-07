<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'includes/adopt_a_bot.php';

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

if (sahtout_adopt_a_bot_revert_processing_claim($site_db, $accountId)) {
    $_SESSION['message'] = 'Adoption paused. You can choose a bot again or retry when ready.';
} else {
    $_SESSION['error'] = 'No in-progress adoption to pause.';
}

header('Location: ' . $base_path . 'adopt-a-bot');
exit;
