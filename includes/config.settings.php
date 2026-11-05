<?php
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access not allowed.');
}

if (!defined('SAHTOUT_CONFIG_LOADED')) {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/page_backgrounds.php';
