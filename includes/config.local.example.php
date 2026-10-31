<?php
/**
 * Installation-specific settings (copy to config.local.php and customize).
 * Do not commit config.local.php — it contains secrets.
 */
return [
    'database' => [
        'auth' => [
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'sahtout_user',
            'pass' => 'change-me',
            'name' => 'acore_auth',
        ],
        'world' => [
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'sahtout_user',
            'pass' => 'change-me',
            'name' => 'acore_world',
        ],
        'char' => [
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'sahtout_user',
            'pass' => 'change-me',
            'name' => 'acore_characters',
        ],
        'site' => [
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'sahtout_user',
            'pass' => 'change-me',
            'name' => 'sahtout_site',
        ],
    ],
    'smtp' => [
        'enabled' => false,
        'host' => '',
        'user' => '',
        'pass' => '',
        'from' => 'noreply@yourdomain.com',
        'name' => 'Sahtout Account',
        'port' => 587,
        'secure' => 'tls',
        'allow_self_signed' => false,
    ],
    'recaptcha' => [
        'enabled' => false,
        'site_key' => '',
        'secret_key' => '',
    ],
    'soap' => [
        'url' => 'http://127.0.0.1:7878',
        'user' => 'system',
        'pass' => 'change-me',
    ],
    'realms' => [
        [
            'id' => 1,
            'name' => 'My Realm',
            'address' => '127.0.0.1',
            'check_address' => '127.0.0.1',
            'port' => 8085,
            'logo' => 'img/logos/realm_logo.png',
            'player_display' => 'separate',
        ],
    ],
    'armory' => [
        'solo_pvp_display' => 'humans_only',
        'arena_2v2_display' => 'all',
        'arena_3v3_display' => 'all',
        'arena_5v5_display' => 'all',
    ],
];
