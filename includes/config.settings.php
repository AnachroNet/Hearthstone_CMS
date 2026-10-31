<?php
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access not allowed.');
}

// Site Title (Editable from Admin Panel)
$site_title_name = 'Joezekistan';

// Logo
$site_logo = 'img/logo.png';

// Featured YouTube video
$youtube_embed_url = 'https://www.youtube.com/embed/IIqtuupvdWg?rel=0&modestbranding=1';
$youtube_title = 'Testing';
$youtube_description = 'Just testing the server...';

// Social links
$social_links = [
    'facebook' => '',
    'twitter' => '',
    'tiktok' => '',
    'youtube' => '',
    'discord' => 'https://discord.gg/chxXTXXQ6M',
    'twitch' => '',
    'kick' => '',
    'instagram' => '',
    'github' => '',
    'linkedin' => '',
];
