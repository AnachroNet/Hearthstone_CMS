<?php
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access to this file is not allowed.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$langCode = 'en';

$globalLangFile = __DIR__ . '/en/global.php';
$lang = (file_exists($globalLangFile) && is_readable($globalLangFile)) ? include $globalLangFile : [];

$basePath = realpath(__DIR__ . '/../');
$scriptPath = realpath($_SERVER['SCRIPT_FILENAME']);
$relativePath = str_replace($basePath, '', $scriptPath);
$relativePath = trim($relativePath, '/\\');
$pageLangFileName = str_replace(['/', '\\'], '_', $relativePath);
$pageLangFile = __DIR__ . '/en/' . $pageLangFileName;

if (file_exists($pageLangFile) && is_readable($pageLangFile)) {
    $pageLang = include $pageLangFile;
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
