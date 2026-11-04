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

/** @return list<string> Empty string = use config.local / PHP default. */
function sahtout_site_timezone_ids(): array
{
    return [
        '',
        'America/St_Johns',
        'America/Halifax',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Phoenix',
        'America/Los_Angeles',
        'America/Anchorage',
        'Pacific/Honolulu',
    ];
}

function sahtout_site_setting_defaults(): array
{
    return [
        'site.title' => 'SahtoutCMS',
        'site.logo_path' => 'img/logo.png',
        'site.timezone' => '',
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
        'registration.allow_shared_email' => '0',
        'registration.signup_tokens_enabled' => '0',
        'registration.signup_tokens_amount' => '0',
        'shop.token_gift_loot_ids' => '2276,2277,2278,2279,2280,2284',
        'shop.token_gift_item_template_ref' => '2223',
        'shop.token_gift_quest_template_ref' => '123',
        'shop.token_gift_id_base' => '9100000',
    ];
}

/** When true, multiple game accounts may share the same registration email (username stays unique). */
function sahtout_registration_allow_shared_email(): bool
{
    global $sahtout_site_settings;

    if (!isset($sahtout_site_settings) || !is_array($sahtout_site_settings)) {
        return false;
    }

    return !empty($sahtout_site_settings['registration.allow_shared_email']);
}

/** Tokens granted to new accounts when registration.signup_tokens_enabled is on (0 when disabled). */
function sahtout_registration_signup_tokens_amount(): int
{
    global $sahtout_site_settings;

    if (!isset($sahtout_site_settings) || !is_array($sahtout_site_settings)) {
        return 0;
    }
    if (empty($sahtout_site_settings['registration.signup_tokens_enabled'])) {
        return 0;
    }

    return max(0, min(9999999, (int) ($sahtout_site_settings['registration.signup_tokens_amount'] ?? 0)));
}

/** After account INSERT (trigger creates user_currencies with 0 tokens), apply admin signup token grant. */
function sahtout_apply_registration_signup_tokens(mysqli $site_db, int $accountId): void
{
    if ($accountId <= 0) {
        return;
    }
    $amount = sahtout_registration_signup_tokens_amount();
    if ($amount <= 0) {
        return;
    }

    $stmt = $site_db->prepare('UPDATE user_currencies SET tokens = ? WHERE account_id = ?');
    if (!$stmt) {
        error_log('Registration signup tokens: prepare failed: ' . $site_db->error);

        return;
    }
    $stmt->bind_param('ii', $amount, $accountId);
    if (!$stmt->execute()) {
        error_log('Registration signup tokens: update failed for account ' . $accountId . ': ' . $stmt->error);
    }
    $stmt->close();
}

/** Drops pending_accounts.unique_email so multiple pending signups can share an email. Safe if already removed. */
function sahtout_pending_accounts_drop_unique_email(mysqli $site_db): bool
{
    $res = $site_db->query("SHOW INDEX FROM pending_accounts WHERE Key_name = 'unique_email'");
    if (!$res) {
        return false;
    }
    $exists = $res->num_rows > 0;
    $res->free();
    if (!$exists) {
        return true;
    }

    return (bool) $site_db->query('ALTER TABLE pending_accounts DROP INDEX unique_email');
}

function sahtout_password_resets_ensure_username_column(mysqli $site_db): bool
{
    $res = $site_db->query("SHOW COLUMNS FROM password_resets LIKE 'username'");
    if (!$res) {
        return false;
    }
    if ($res->num_rows > 0) {
        $res->free();

        return true;
    }
    $res->free();

    return (bool) $site_db->query(
        'ALTER TABLE password_resets ADD COLUMN username varchar(32) DEFAULT NULL AFTER email'
    );
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

function sahtout_normalize_external_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
}

/**
 * URL for “Join Discord” buttons (invite or profile link from Admin → General social links).
 */
function sahtout_discord_join_url(): string
{
    global $social_links;

    $url = sahtout_normalize_external_url((string) ($social_links['discord'] ?? ''));
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://(?:www\.)?(?:discord\.gg|discord(?:app)?\.com)/#i', $url)) {
        return $url;
    }

    return '';
}

function sahtout_apply_site_settings_globals(array $settings): void
{
    global $site_title_name, $site_logo, $site_timezone;
    global $social_links, $discord_guild_id, $discord_widget_enabled, $discord_join_url;

    $site_title_name = (string) ($settings['site.title'] ?? 'SahtoutCMS');
    $site_logo = (string) ($settings['site.logo_path'] ?? 'img/logo.png');

    $tz = trim((string) ($settings['site.timezone'] ?? ''));
    if ($tz !== '') {
        try {
            new DateTimeZone($tz);
            $site_timezone = $tz;
        } catch (Exception $e) {
            error_log('[site_settings] invalid site.timezone: ' . $tz);
        }
    }

    $defaultSocial = sahtout_site_setting_defaults()['social.links'];
    $social = $settings['social.links'] ?? $defaultSocial;
    $social_links = is_array($social) ? array_replace($defaultSocial, $social) : $defaultSocial;

    $discord_guild_id = preg_replace('/\D/', '', (string) ($settings['discord.guild_id'] ?? ''));
    $discord_widget_enabled = !empty($settings['discord.widget_enabled']) && $discord_guild_id !== '';
    $discord_join_url = sahtout_discord_join_url();
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
    sahtout_site_setting_set($site_db, 'social.links', $legacy['social.links'], 'json');
}
