<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';

$guid = (int) ($_GET['guid'] ?? 0);
if ($guid > 0) {
    header('Location: ' . $base_path . 'character?guid=' . $guid . '&adopt=1');
} else {
    header('Location: ' . $base_path . 'adopt-a-bot');
}
exit;
