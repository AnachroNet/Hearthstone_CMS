<?php
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access to this file is not allowed.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$langCode = 'en';

$globalFile = __DIR__ . '/en/global.php';
$lang = (file_exists($globalFile) && is_readable($globalFile)) ? include $globalFile : [];

$basePath = realpath(__DIR__ . '/../');
$scriptPath = realpath($_SERVER['SCRIPT_FILENAME']);
$relativePath = str_replace($basePath, '', $scriptPath);
$pageFileName = pathinfo($relativePath, PATHINFO_FILENAME) . '.php';
$pageFile = __DIR__ . '/en/' . $pageFileName;

if (file_exists($pageFile) && is_readable($pageFile)) {
    $pageLang = include $pageFile;
    if (is_array($pageLang)) {
        $lang = array_merge($lang, $pageLang);
    }
}

function translate($key, $default = '', ...$args)
{
    global $lang;
    $string = $lang[$key] ?? $default;
    if (!empty($args)) {
        return vsprintf($string, $args);
    }

    return $string;
}
