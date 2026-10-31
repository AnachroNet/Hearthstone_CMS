<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

function sahtout_site_settings_table_exists(mysqli $site_db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $site_db->query("SHOW TABLES LIKE 'site_settings'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();
    return $exists;
}

function sahtout_site_setting_defaults(): array
{
    return [
        'site.title' => 'SahtoutCMS',
        'site.logo_path' => 'img/logo.png',
        'home.youtube_embed_url' => '',
        'home.youtube_title' => 'Featured Video',
        'home.youtube_description' => '',
        'social.links' => [
            'facebook' => '',
            'twitter' => '',
            'tiktok' => '',
            'youtube' => '',
            'discord' => '',
            'twitch' => '',
            'kick' => '',
            'instagram' => '',
            'github' => '',
            'linkedin' => '',
        ],
        'discord.guild_id' => '',
        'discord.widget_enabled' => '1',
    ];
}

function sahtout_site_settings_load_all(mysqli $site_db): array
{
    $settings = sahtout_site_setting_defaults();
    if (!sahtout_site_settings_table_exists($site_db)) {
        return $settings;
    }

    $res = $site_db->query('SELECT setting_key, setting_value, value_type FROM site_settings');
    if (!$res) {
        return $settings;
    }

    while ($row = $res->fetch_assoc()) {
        $key = $row['setting_key'];
        $type = $row['value_type'] ?? 'string';
        $raw = $row['setting_value'];
        if ($type === 'json') {
            $decoded = json_decode($raw ?? '', true);
            $settings[$key] = is_array($decoded) ? $decoded : sahtout_site_setting_defaults()[$key] ?? [];
        } elseif ($type === 'bool') {
            $settings[$key] = in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
        } elseif ($type === 'int') {
            $settings[$key] = (int) $raw;
        } else {
            $settings[$key] = (string) $raw;
        }
    }
    $res->free();

    return $settings;
}

function sahtout_site_setting_set(mysqli $site_db, string $key, mixed $value, string $type = 'string', ?int $updatedBy = null): bool
{
    if (!sahtout_site_settings_table_exists($site_db)) {
        return false;
    }

    if ($type === 'json') {
        $stored = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($stored === false) {
            return false;
        }
    } elseif ($type === 'bool') {
        $stored = $value ? '1' : '0';
    } else {
        $stored = (string) $value;
    }

    $stmt = $site_db->prepare(
        'INSERT INTO site_settings (setting_key, setting_value, value_type, updated_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_by = VALUES(updated_by)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sssi', $key, $stored, $type, $updatedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function sahtout_apply_site_settings_globals(array $settings): void
{
    global $site_title_name, $site_logo, $youtube_embed_url, $youtube_title, $youtube_description;
    global $social_links, $discord_guild_id, $discord_widget_enabled;

    $site_title_name = (string) ($settings['site.title'] ?? 'SahtoutCMS');
    $site_logo = (string) ($settings['site.logo_path'] ?? 'img/logo.png');
    $youtube_embed_url = (string) ($settings['home.youtube_embed_url'] ?? '');
    $youtube_title = (string) ($settings['home.youtube_title'] ?? '');
    $youtube_description = (string) ($settings['home.youtube_description'] ?? '');

    $defaultSocial = sahtout_site_setting_defaults()['social.links'];
    $social = $settings['social.links'] ?? $defaultSocial;
    $social_links = is_array($social) ? array_replace($defaultSocial, $social) : $defaultSocial;

    $discord_guild_id = preg_replace('/\D/', '', (string) ($settings['discord.guild_id'] ?? ''));
    $discord_widget_enabled = !empty($settings['discord.widget_enabled']) && $discord_guild_id !== '';
}

function sahtout_site_settings_from_legacy_file(): ?array
{
    $path = __DIR__ . '/config.settings.php';
    if (!is_file($path)) {
        return null;
    }

    include $path;
    $settings = sahtout_site_setting_defaults();
    if (isset($site_title_name)) {
        $settings['site.title'] = $site_title_name;
    }
    if (isset($site_logo)) {
        $settings['site.logo_path'] = $site_logo;
    }
    if (isset($youtube_embed_url)) {
        $settings['home.youtube_embed_url'] = $youtube_embed_url;
    }
    if (isset($youtube_title)) {
        $settings['home.youtube_title'] = $youtube_title;
    }
    if (isset($youtube_description)) {
        $settings['home.youtube_description'] = $youtube_description;
    }
    if (isset($social_links) && is_array($social_links)) {
        $settings['social.links'] = array_replace($settings['social.links'], $social_links);
    }

    return $settings;
}

function sahtout_seed_site_settings_from_legacy(mysqli $site_db): void
{
    if (!sahtout_site_settings_table_exists($site_db)) {
        return;
    }

    $res = $site_db->query('SELECT COUNT(*) AS c FROM site_settings');
    if ($res && ($row = $res->fetch_assoc()) && (int) $row['c'] > 0) {
        $res->free();
        return;
    }
    $res?->free();

    $legacy = sahtout_site_settings_from_legacy_file();
    if ($legacy === null) {
        return;
    }

    sahtout_site_setting_set($site_db, 'site.title', $legacy['site.title'], 'string');
    sahtout_site_setting_set($site_db, 'site.logo_path', $legacy['site.logo_path'], 'string');
    sahtout_site_setting_set($site_db, 'home.youtube_embed_url', $legacy['home.youtube_embed_url'], 'string');
    sahtout_site_setting_set($site_db, 'home.youtube_title', $legacy['home.youtube_title'], 'string');
    sahtout_site_setting_set($site_db, 'home.youtube_description', $legacy['home.youtube_description'], 'string');
    sahtout_site_setting_set($site_db, 'social.links', $legacy['social.links'], 'json');
}
