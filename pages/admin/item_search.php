<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/admin_item_search.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'forbidden', 'items' => []]);
    exit;
}

/** @var mysqli $world_db */
global $world_db;

if (!isset($world_db) || $world_db->connect_error) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'world_unavailable', 'items' => []]);
    exit;
}

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$result = sahtout_admin_search_item_template($world_db, $query);

if (!$result['ok']) {
    $status = match ($result['message']) {
        'forbidden' => 403,
        'world_unavailable', 'search_failed' => 503,
        default => 400,
    };
    http_response_code($status);
}

echo json_encode([
    'ok' => $result['ok'],
    'message' => $result['message'],
    'items' => $result['items'],
]);
