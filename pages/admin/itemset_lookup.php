<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/itemset_dbc.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'forbidden', 'set' => null]);
    exit;
}

/** @var mysqli $site_db */
/** @var mysqli|null $world_db */
global $site_db, $world_db;

$dbcDb = sahtout_itemset_dbc_db();
if ($dbcDb === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'itemset_dbc_missing', 'set' => null]);
    exit;
}

$setId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($setId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'invalid_id', 'set' => null]);
    exit;
}

$detail = sahtout_itemset_dbc_detail($site_db, $world_db, $dbcDb, $setId);
if ($detail === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'not_found', 'set' => null]);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'ok',
    'set' => $detail,
]);
