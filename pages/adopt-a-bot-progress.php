<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'includes/adopt_a_bot.php';

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(25);

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth', 'message' => 'Not signed in.']);

    exit;
}

$accountId = (int) $_SESSION['user_id'];

global $site_db, $char_db, $auth_db, $db_char_name, $db_auth_name, $base_path;

try {
    sahtout_adopt_a_bot_claims_ensure_schema($site_db);
    sahtout_adopt_a_bot_claims_upgrade_schema($site_db);

    $open = sahtout_adopt_a_bot_get_open_claim($site_db, $accountId);
    if ($open === null || (string) ($open['status'] ?? '') !== 'processing') {
        echo json_encode([
            'ok' => false,
            'error' => 'not_processing',
            'done' => false,
            'message' => 'No adoption is running for this account. Pause and confirm again, or open the resume link from your account.',
            'progress_schema' => sahtout_adopt_a_bot_claims_extended_schema($site_db),
            'polled_at' => time(),
        ]);
        session_write_close();
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
    $claimRow = $openAfter ?? $open;
    if (!empty($result['done'])) {
        $claimRow = $claimRow ?? $open;
    }

    $payload = sahtout_adopt_a_bot_build_progress_payload(
        $site_db,
        $accountId,
        $result,
        $claimRow,
        $base_path . 'account'
    );
    session_write_close();
    echo json_encode($payload);
} catch (Throwable $e) {
    error_log('adopt_a_bot progress: ' . $e->getMessage());
    session_write_close();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server',
        'done' => false,
        'message' => 'Progress check failed: ' . $e->getMessage(),
        'polled_at' => time(),
    ]);
}
