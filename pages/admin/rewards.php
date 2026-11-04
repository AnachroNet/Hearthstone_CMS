<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';

$query = [];
if (isset($_GET['status']) && (string) $_GET['status'] !== '') {
    $query['reward_status'] = (string) $_GET['status'];
}
$query['tab'] = 'rewards';

$location = $base_path . 'admin/ashop';
if ($query !== []) {
    $location .= '?' . http_build_query($query);
}

header('Location: ' . $location, true, 302);
exit;
