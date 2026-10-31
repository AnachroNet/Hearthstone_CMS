<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/admin_achievement_rewards.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'forbidden', 'achievements' => []]);
    exit;
}

/** @var mysqli $site_db */
global $site_db;

if (!isset($site_db) || $site_db->connect_error) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'site_unavailable', 'achievements' => []]);
    exit;
}

$query = isset($_GET['q']) ? (string) $_GET['q'] : '';
$result = sahtout_admin_search_achievement_catalog($site_db, $query);

if (!$result['ok']) {
    $status = match ($result['message']) {
        'catalog_missing', 'search_failed' => 503,
        default => 400,
    };
    http_response_code($status);
}

echo json_encode([
    'ok' => $result['ok'],
    'message' => $result['message'],
    'achievements' => $result['achievements'],
]);
