<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

function sahtout_default_local_config(): array
{
    $example = __DIR__ . '/config.local.example.php';
    if (is_file($example)) {
        $data = require $example;
        if (is_array($data)) {
            return $data;
        }
    }

    return [
        'database' => [
            'auth' => ['host' => 'localhost', 'port' => 3306, 'user' => '', 'pass' => '', 'name' => 'acore_auth'],
            'world' => ['host' => 'localhost', 'port' => 3306, 'user' => '', 'pass' => '', 'name' => 'acore_world'],
            'char' => ['host' => 'localhost', 'port' => 3306, 'user' => '', 'pass' => '', 'name' => 'acore_characters'],
            'site' => ['host' => 'localhost', 'port' => 3306, 'user' => '', 'pass' => '', 'name' => 'sahtout_site'],
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
            'pass' => '',
        ],
        'realms' => [],
        'armory' => [
            'solo_pvp_display' => 'humans_only',
            'arena_2v2_display' => 'all',
            'arena_3v3_display' => 'all',
            'arena_5v5_display' => 'all',
        ],
    ];
}

function sahtout_local_config_path(): string
{
    return __DIR__ . '/config.local.php';
}

function sahtout_load_local_config(bool $reload = false): array
{
    static $cached = null;
    if (!$reload && $cached !== null) {
        return $cached;
    }

    $path = sahtout_local_config_path();
    if (is_file($path)) {
        $data = require $path;
        if (is_array($data)) {
            $cached = array_replace_recursive(sahtout_default_local_config(), $data);
            return $cached;
        }
    }

    $migrated = sahtout_migrate_legacy_config_files();
    if ($migrated !== null) {
        sahtout_save_local_config($migrated);
        $cached = $migrated;
        return $cached;
    }

    $cached = sahtout_default_local_config();
    return $cached;
}

function sahtout_save_local_config(array $config): bool
{
    $path = sahtout_local_config_path();
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    $export = var_export($config, true);
    $content = "<?php\n/** Auto-generated — edit via installer or admin settings. */\nreturn {$export};\n";

    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($tmp, $content) === false) {
        return false;
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);

    return true;
}

function sahtout_update_local_config(array $patch): bool
{
    $current = sahtout_load_local_config(true);
    $merged = array_replace_recursive($current, $patch);
    if (!sahtout_save_local_config($merged)) {
        return false;
    }
    sahtout_load_local_config(true);
    return true;
}

/**
 * One-time migration from legacy split config files (pre–config.local.php).
 */
function sahtout_migrate_legacy_config_files(): ?array
{
    $dir = __DIR__;
    $legacyMain = $dir . '/config.php';
    if (!is_file($legacyMain)) {
        return null;
    }

    $mainSrc = file_get_contents($legacyMain);
    if ($mainSrc === false || !str_contains($mainSrc, '$db_auth_host')) {
        return null;
    }

    $cfg = sahtout_default_local_config();

    foreach (['auth', 'world', 'char', 'site'] as $key) {
        foreach (['host', 'port', 'user', 'pass', 'name'] as $field) {
            $var = "db_{$key}_{$field}";
            if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*([^;]+);/', $mainSrc, $m)) {
                $cfg['database'][$key][$field] = sahtout_eval_exported_value($m[1]);
            }
        }
    }

    if (is_file($dir . '/config.mail.php')) {
        include $dir . '/config.mail.php';
        $cfg['smtp'] = [
            'enabled' => !empty($smtp_enabled),
            'host' => $smtp_host ?? '',
            'user' => $smtp_user ?? '',
            'pass' => $smtp_pass ?? '',
            'from' => $smtp_from ?? 'noreply@yourdomain.com',
            'name' => $smtp_name ?? 'Sahtout Account',
            'port' => (int) ($smtp_port ?? 587),
            'secure' => $smtp_secure ?? 'tls',
            'allow_self_signed' => !empty($smtp_allow_self_signed),
        ];
    }

    if (is_file($dir . '/config.cap.php')) {
        include $dir . '/config.cap.php';
        $cfg['recaptcha'] = [
            'enabled' => !empty($recaptcha_enabled),
            'site_key' => $recaptcha_site_key ?? '',
            'secret_key' => $recaptcha_secret_key ?? '',
        ];
    }

    if (is_file($dir . '/soap.conf.php')) {
        include $dir . '/soap.conf.php';
        $cfg['soap'] = [
            'url' => $soap_url ?? '',
            'user' => $soap_user ?? '',
            'pass' => $soap_pass ?? '',
        ];
    }

    if (is_file($dir . '/realm_config.php')) {
        include $dir . '/realm_config.php';
        if (!empty($realmlist) && is_array($realmlist)) {
            $cfg['realms'] = array_values($realmlist);
        }
    }

    if (is_file($dir . '/armory_config.php')) {
        include $dir . '/armory_config.php';
        if (!empty($armoryConfig) && is_array($armoryConfig)) {
            $cfg['armory'] = array_replace($cfg['armory'], $armoryConfig);
        }
    }

    return $cfg;
}

function sahtout_eval_exported_value(string $exported): mixed
{
    $exported = trim($exported);
    if ($exported === '') {
        return '';
    }
    try {
        return eval('return ' . $exported . ';');
    } catch (Throwable $e) {
        return trim($exported, " \t\n\r\0\x0B'\"");
    }
}

function sahtout_installer_db_groups_to_local(array $dbGroups): array
{
    $patch = ['database' => []];
    foreach ($dbGroups as $key => $g) {
        $patch['database'][$key] = [
            'host' => $g['host'] ?? 'localhost',
            'port' => (int) ($g['port'] ?? 3306),
            'user' => $g['user'] ?? '',
            'pass' => $g['pass'] ?? '',
            'name' => $g['name'] ?? '',
        ];
    }
    return $patch;
}
