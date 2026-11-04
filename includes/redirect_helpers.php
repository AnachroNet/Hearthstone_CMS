<?php
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access to this file is not allowed.');
}

/**
 * Build a safe same-site redirect target from a relative path (e.g. "contact" or "account").
 */
function sahtout_safe_internal_return_path(string $candidate, string $base_path): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }
    if (strpos($candidate, '://') !== false || strncmp($candidate, '//', 2) === 0) {
        return null;
    }
    if (strlen($candidate) > 512) {
        return null;
    }
    if (preg_match('#\.\.[\\\\/]#', $candidate)) {
        return null;
    }

    $path = ltrim(str_replace('\\', '/', $candidate), '/');
    if ($path === '') {
        return null;
    }

    return rtrim($base_path, '/') . '/' . $path;
}
