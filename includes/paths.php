<?php
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ? 'https://' : 'http://';
} elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $protocol = 'https://';
} else {
    $protocol = 'http://';
}

$host = $_SERVER['HTTP_HOST'];

$projectFolder = str_replace($_SERVER['DOCUMENT_ROOT'], '', str_replace('\\', '/', __DIR__ . '/..'));


$base_path = '/';

$project_root = rtrim(realpath(__DIR__ . '/..'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

$site_url = $protocol . $host . $base_path;

if (!function_exists('sahtout_public_asset_url')) {
    /**
     * Build a public URL for a project-relative asset (e.g. img/logo.png).
     * Prepends $base_path so logos and images resolve when the site lives in a subdirectory.
     */
    function sahtout_public_asset_url(string $relativePath): string
    {
        global $base_path;

        $rel = str_replace('\\', '/', $relativePath);
        $rel = ltrim($rel, '/');

        $prefix = (string) ($base_path ?? '/');
        if ($prefix !== '' && !str_starts_with($prefix, '/')) {
            $prefix = '/' . $prefix;
        }

        if ($prefix === '/' || $prefix === '') {
            return '/' . $rel;
        }

        return rtrim($prefix, '/') . '/' . $rel;
    }
}