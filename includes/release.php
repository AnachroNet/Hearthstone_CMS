<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

if (!defined('SAHTOUT_CODE_RELEASE')) {
    $releaseFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'RELEASE';
    $raw = is_file($releaseFile) ? trim((string) file_get_contents($releaseFile)) : '0';
    define('SAHTOUT_CODE_RELEASE', (int) preg_replace('/\D/', '', $raw));
}
