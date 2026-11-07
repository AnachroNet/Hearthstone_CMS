<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'includes/adopt_a_bot.php';

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(20);

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);

    exit;
}

$accountId = (int) $_SESSION['user_id'];
session_write_close();

global $site_db, $char_db, $auth_db, $db_char_name, $db_auth_name;
sahtout_adopt_a_bot_claims_ensure_schema($site_db);

$open = sahtout_adopt_a_bot_get_open_claim($site_db, $accountId);
if ($open === null || (string) ($open['status'] ?? '') !== 'processing') {
    echo json_encode(['ok' => false, 'error' => 'not_processing', 'done' => false]);

    exit;
}

$result = sahtout_adopt_a_bot_process_open_claim(
    $site_db,
    $char_db,
    $auth_db,
    $accountId,
    $db_char_name,
    $db_auth_name
);

$openAfter = sahtout_adopt_a_bot_get_open_claim($site_db, $accountId);
$lines = sahtout_adopt_a_bot_progress_log_lines($openAfter ?? $open);
$message = trim((string) (($openAfter ?? $open)['progress_message'] ?? ''));

$payload = [
    'ok' => !empty($result['ok']),
    'done' => !empty($result['done']),
    'error' => $result['error'] ?? null,
    'message' => $message,
    'lines' => $lines,
    'character_name' => $result['character_name'] ?? null,
    'account_url' => $base_path . 'account',
];

echo json_encode($payload);
