<?php
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access to this file is not allowed.');
}

/**
 * Configurable full-page background images (files under img/backgrounds/).
 *
 * @return array<string, array{label_key: string, label_default: string, filename: string}>
 */
function sahtout_page_backgrounds_catalog(): array
{
    return [
        'login' => [
            'label_key' => 'bg_label_login',
            'label_default' => 'Login',
            'filename' => 'bg-login.jpg',
        ],
        'register' => [
            'label_key' => 'bg_label_register',
            'label_default' => 'Register',
            'filename' => 'bg-register.jpg',
        ],
        'account' => [
            'label_key' => 'bg_label_account',
            'label_default' => 'Account',
            'filename' => 'bg-account.jpg',
        ],
        'shop' => [
            'label_key' => 'bg_label_shop',
            'label_default' => 'Shop',
            'filename' => 'bg-shop.jpg',
        ],
        'armory' => [
            'label_key' => 'bg_label_armory',
            'label_default' => 'Armory',
            'filename' => 'bg-armory.jpg',
        ],
        'events' => [
            'label_key' => 'bg_label_events',
            'label_default' => 'Events',
            'filename' => 'bg-events.jpg',
        ],
        'news' => [
            'label_key' => 'bg_label_news',
            'label_default' => 'News',
            'filename' => 'bg-news.jpg',
        ],
        'howto' => [
            'label_key' => 'bg_label_howto',
            'label_default' => 'How to Play',
            'filename' => 'bg-howto.jpg',
        ],
        'password' => [
            'label_key' => 'bg_label_password',
            'label_default' => 'Forgot Password',
            'filename' => 'bg-password.jpg',
        ],
        'reset_password' => [
            'label_key' => 'bg_label_reset_password',
            'label_default' => 'Reset Password',
            'filename' => 'bg-reset-password.jpg',
        ],
    ];
}

function sahtout_page_backgrounds_directory(): string
{
    global $project_root;

    return rtrim($project_root, '/\\') . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'backgrounds' . DIRECTORY_SEPARATOR;
}

function sahtout_page_background_catalog_entry(string $key): ?array
{
    $catalog = sahtout_page_backgrounds_catalog();

    return $catalog[$key] ?? null;
}

function sahtout_page_background_file_path(string $key): ?string
{
    $entry = sahtout_page_background_catalog_entry($key);
    if ($entry === null) {
        return null;
    }

    return sahtout_page_backgrounds_directory() . $entry['filename'];
}

/**
 * Public URL for a page background, with cache-busting when the file exists.
 */
function sahtout_page_background_url(string $key): string
{
    global $base_path;

    $entry = sahtout_page_background_catalog_entry($key);
    if ($entry === null) {
        return $base_path . 'img/backgrounds/bg-login.jpg';
    }

    $filename = $entry['filename'];
    $path = sahtout_page_backgrounds_directory() . $filename;
    $url = $base_path . 'img/backgrounds/' . rawurlencode($filename);

    if (is_file($path)) {
        $mtime = @filemtime($path);
        if ($mtime !== false) {
            $url .= '?v=' . $mtime;
        }
    }

    return $url;
}

/** Max upload size for page backgrounds (5 MB). */
function sahtout_page_background_max_upload_bytes(): int
{
    return 5 * 1024 * 1024;
}

/** @return array<string, string> MIME => extension hint (stored filenames are fixed in catalog). */
function sahtout_page_background_allowed_mime_types(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
}

/**
 * Validate an uploaded background image.
 *
 * @return array{ok: bool, error: ?string, mime: ?string}
 */
function sahtout_validate_page_background_upload(array $file, int $maxSize): array
{
    $fail = static function (string $error): array {
        return ['ok' => false, 'error' => $error, 'mime' => null];
    };

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return $fail('image');
    }

    if (isset($file['size']) && (int) $file['size'] > $maxSize) {
        return $fail('size');
    }

    $realSize = @filesize($file['tmp_name']);
    if ($realSize === false || $realSize <= 0 || $realSize > $maxSize) {
        return $fail('size');
    }

    if (!class_exists('finfo')) {
        return $fail('type');
    }

    $allowed = sahtout_page_background_allowed_mime_types();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!is_string($mime) || !isset($allowed[$mime])) {
        return $fail('type');
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1]) || empty($imageInfo['mime'])) {
        return $fail('image');
    }
    if (strtolower((string) $imageInfo['mime']) !== strtolower($mime)) {
        return $fail('type');
    }

    return ['ok' => true, 'error' => null, 'mime' => $mime];
}

/**
 * Save upload to the catalog filename for $key (JPEG on disk; PNG is converted).
 *
 * @return array{ok: bool, error: ?string}
 */
function sahtout_save_page_background_upload(string $key, array $file): array
{
    $entry = sahtout_page_background_catalog_entry($key);
    if ($entry === null) {
        return ['ok' => false, 'error' => 'invalid_key'];
    }

    $maxSize = sahtout_page_background_max_upload_bytes();
    $validation = sahtout_validate_page_background_upload($file, $maxSize);
    if (!$validation['ok']) {
        return ['ok' => false, 'error' => $validation['error']];
    }

    $destDir = sahtout_page_backgrounds_directory();
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        return ['ok' => false, 'error' => 'write'];
    }

    $destPath = $destDir . $entry['filename'];
    $mime = $validation['mime'];

    if ($mime === 'image/jpeg') {
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['ok' => false, 'error' => 'write'];
        }

        return ['ok' => true, 'error' => null];
    }

    if ($mime === 'image/png') {
        if (!function_exists('imagecreatefrompng') || !function_exists('imagejpeg')) {
            return ['ok' => false, 'error' => 'type'];
        }

        $image = @imagecreatefrompng($file['tmp_name']);
        if ($image === false) {
            return ['ok' => false, 'error' => 'image'];
        }

        imagealphablending($image, true);
        imagesavealpha($image, false);
        $saved = @imagejpeg($image, $destPath, 90);
        imagedestroy($image);
        @unlink($file['tmp_name']);

        if (!$saved) {
            return ['ok' => false, 'error' => 'write'];
        }

        return ['ok' => true, 'error' => null];
    }

    return ['ok' => false, 'error' => 'type'];
}
