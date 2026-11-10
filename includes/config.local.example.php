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
            'name' => 'hearthstone_site',
        ],
        /** Optional: Playerbots mod DB on the same MySQL server (SELECT only). Empty name disables bot/human splits. */
        'playerbots' => [
            'name' => 'acore_playerbots',
        ],
    ],
    'smtp' => [
        'enabled' => false,
        'host' => '',
        'user' => '',
        'pass' => '',
        'from' => 'noreply@yourdomain.com',
        'name' => 'Hearthstone CMS',
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
        'solo_pvp_include_scored_bots' => true,
        'arena_2v2_display' => 'all',
        'arena_3v3_display' => 'all',
        'arena_5v5_display' => 'all',
    ],
    /** IANA timezone for ticket timestamps (e.g. America/New_York). Empty = PHP default timezone. */
    'timezone' => '',
    /**
     * Optional feature toggles. Usually use Admin → General (site_settings) instead.
     * shop_token_gifts_enabled false forces token gift letters off even if the DB still has them enabled
     * (emergency recovery if staff disabled the feature in the UI).
     */
    'features' => [
        'shop_token_gifts_enabled' => null,
        /** Force Adopt a Bot on/off regardless of Admin → General (null = use database). */
        'adopt_a_bot_enabled' => null,
        /** Force live player map on/off regardless of Admin → General (null = use database). */
        'playermap_enabled' => null,
    ],
    /**
     * Optional AzerothCore playermap tuning (Admin → General: enable + faction visibility).
     * Uses auth/characters/realms from this file; no separate playermap_config.php.
     */
    'playermap' => [
        'gm_online' => true,
        'gm_online_count' => 100,
        'map_gm_show_online_only_gmoff' => 1,
        'map_gm_show_online_only_gmvisible' => 1,
        /** Prefix online GM names with "<GM>" on the map (legacy playermap key name). */
        'map_gm_add_suffix' => 1,
        /** Seconds between live map polls (player positions + realm online graphic). */
        'map_time' => 5,
        'developer_test_mode' => false,
        'multi_realm_mode' => false,
    ],
];
