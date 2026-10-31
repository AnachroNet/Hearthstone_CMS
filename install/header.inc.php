<?php
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access to this file is not allowed.');
}
require_once __DIR__ . '/../includes/paths.php';
include 'languages/language.php';
if (!function_exists('translate')) {
    error_log('translate() function not defined. Ensure language.php is included before header.inc.php.');
    die('Internal server error: Translation function not available. Please contact the administrator.');
}

$langCode = 'en';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= translate('installer_title', 'Sahtout CMS Installer') ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($base_path ?? '/') ?>img/logo.png">
    <link rel="stylesheet" href="<?= htmlspecialchars($base_path ?? '/') ?>assets/css/tailwind.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($base_path ?? '/') ?>assets/fontawesome-free/css/all.min.css">
    <style>
        .glass-nav {
            background: rgba(5, 7, 11, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(201,162,39,0.3);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.6);
            position: relative;
            z-index: 999;
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;900&display=swap" rel="stylesheet">
</head>
<body>
    <nav class="glass-nav flex flex-wrap items-center justify-between px-4 md:px-8 py-3 gap-2">
        <div class="flex items-center">
            <img src="<?php echo $base_path; ?>install/logo.png" alt="<?= translate('logo_alt', 'Sahtout Logo') ?>" class="h-16 md:h-20 mr-3 md:mr-4 rounded transition-transform duration-300 hover:scale-105">
            <span class="font-['Cinzel'] text-xl md:text-2xl font-bold text-[#f2cf5b] tracking-wide drop-shadow-[0_0_20px_rgba(242,207,82,0.2)]">
                <?= translate('installer_title', 'Sahtout CMS Installer') ?>
            </span>
        </div>
    </nav>
