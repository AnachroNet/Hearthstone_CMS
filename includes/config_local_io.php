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

function sahtout_is_legacy_monolithic_config_source(string $src): bool
{
    if (!preg_match('/\$db_auth_host\s*=/', $src)) {
        return false;
    }
    // Current bootstrap config.php uses config.local.php, not inline DB assignments.
    if (str_contains($src, 'sahtout_load_local_config')) {
        return false;
    }

    return true;
}

function sahtout_legacy_include_is_shim_only(string $src): bool
{
    return str_contains($src, '/config.php')
        && str_contains($src, 'SAHTOUT_CONFIG_LOADED')
        && !preg_match('/\$(?:db_|smtp_|recaptcha_|soap_)/', $src);
}

function sahtout_parse_legacy_scalar_vars(string $src, array $varNames): array
{
    $out = [];
    foreach ($varNames as $var) {
        if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*([^;]+);/', $src, $m)) {
            $out[$var] = sahtout_eval_exported_value($m[1]);
        }
    }

    return $out;
}

function sahtout_parse_legacy_array_var(string $src, string $varName): ?array
{
    if (!preg_match('/\$' . preg_quote($varName, '/') . '\s*=\s*/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $start = $m[0][1] + strlen($m[0][0]);
    $expr = sahtout_extract_balanced_php_value($src, $start);
    if ($expr === null) {
        return null;
    }

    try {
        $val = eval('return ' . $expr . ';');
    } catch (Throwable $e) {
        return null;
    }

    return is_array($val) ? $val : null;
}

function sahtout_extract_balanced_php_value(string $src, int $start): ?string
{
    $len = strlen($src);
    if ($start >= $len) {
        return null;
    }

    $i = $start;
    while ($i < $len && ctype_space($src[$i])) {
        $i++;
    }
    if ($i >= $len) {
        return null;
    }

    if ($src[$i] === '[') {
        $open = '[';
        $close = ']';
    } elseif (str_starts_with(substr($src, $i), 'array')) {
        if (!preg_match('/\Garray\s*\(/', $src, $m, 0, $i)) {
            return null;
        }
        $open = '(';
        $close = ')';
        $i = strpos($src, '(', $i);
    } else {
        return null;
    }

    $depth = 0;
    $inString = false;
    $stringQuote = '';
    $j = $i;
    for (; $j < $len; $j++) {
        $ch = $src[$j];
        if ($inString) {
            if ($ch === '\\' && $j + 1 < $len) {
                $j++;
                continue;
            }
            if ($ch === $stringQuote) {
                $inString = false;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $inString = true;
            $stringQuote = $ch;
            continue;
        }
        if ($ch === $open) {
            $depth++;
            continue;
        }
        if ($ch === $close) {
            $depth--;
            if ($depth === 0) {
                return substr($src, $i, $j - $i + 1);
            }
        }
    }

    return null;
}

function sahtout_migrate_apply_legacy_split_files(string $dir, array $cfg): array
{
    $mailPath = $dir . '/config.mail.php';
    if (is_file($mailPath)) {
        $mailSrc = file_get_contents($mailPath);
        if ($mailSrc !== false && !sahtout_legacy_include_is_shim_only($mailSrc)) {
            $v = sahtout_parse_legacy_scalar_vars($mailSrc, [
                'smtp_enabled', 'smtp_host', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_name', 'smtp_port', 'smtp_secure', 'smtp_allow_self_signed',
            ]);
            $cfg['smtp'] = [
                'enabled' => !empty($v['smtp_enabled']),
                'host' => (string) ($v['smtp_host'] ?? ''),
                'user' => (string) ($v['smtp_user'] ?? ''),
                'pass' => (string) ($v['smtp_pass'] ?? ''),
                'from' => (string) ($v['smtp_from'] ?? 'noreply@yourdomain.com'),
                'name' => (string) ($v['smtp_name'] ?? 'Sahtout Account'),
                'port' => (int) ($v['smtp_port'] ?? 587),
                'secure' => (string) ($v['smtp_secure'] ?? 'tls'),
                'allow_self_signed' => !empty($v['smtp_allow_self_signed']),
            ];
        }
    }

    $capPath = $dir . '/config.cap.php';
    if (is_file($capPath)) {
        $capSrc = file_get_contents($capPath);
        if ($capSrc !== false && !sahtout_legacy_include_is_shim_only($capSrc)) {
            $v = sahtout_parse_legacy_scalar_vars($capSrc, [
                'recaptcha_enabled', 'recaptcha_site_key', 'recaptcha_secret_key',
            ]);
            $cfg['recaptcha'] = [
                'enabled' => !empty($v['recaptcha_enabled']),
                'site_key' => (string) ($v['recaptcha_site_key'] ?? ''),
                'secret_key' => (string) ($v['recaptcha_secret_key'] ?? ''),
            ];
        }
    }

    $soapPath = $dir . '/soap.conf.php';
    if (is_file($soapPath)) {
        $soapSrc = file_get_contents($soapPath);
        if ($soapSrc !== false && !sahtout_legacy_include_is_shim_only($soapSrc)) {
            $v = sahtout_parse_legacy_scalar_vars($soapSrc, ['soap_url', 'soap_user', 'soap_pass']);
            $cfg['soap'] = [
                'url' => (string) ($v['soap_url'] ?? ''),
                'user' => (string) ($v['soap_user'] ?? ''),
                'pass' => (string) ($v['soap_pass'] ?? ''),
            ];
        }
    }

    $realmPath = $dir . '/realm_config.php';
    if (is_file($realmPath)) {
        $realmSrc = file_get_contents($realmPath);
        if ($realmSrc !== false && !sahtout_legacy_include_is_shim_only($realmSrc)) {
            $realmlist = sahtout_parse_legacy_array_var($realmSrc, 'realmlist');
            if (!empty($realmlist)) {
                $cfg['realms'] = array_values($realmlist);
            }
        }
    }

    $armoryPath = $dir . '/armory_config.php';
    if (is_file($armoryPath)) {
        $armorySrc = file_get_contents($armoryPath);
        if ($armorySrc !== false && !sahtout_legacy_include_is_shim_only($armorySrc)) {
            $armoryConfig = sahtout_parse_legacy_array_var($armorySrc, 'armoryConfig');
            if (!empty($armoryConfig)) {
                $cfg['armory'] = array_replace($cfg['armory'], $armoryConfig);
            }
        }
    }

    return $cfg;
}

function sahtout_migrate_database_from_config_source(string $src, array $cfg): array
{
    foreach (['auth', 'world', 'char', 'site'] as $key) {
        foreach (['host', 'port', 'user', 'pass', 'name'] as $field) {
            $var = "db_{$key}_{$field}";
            if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*([^;]+);/', $src, $m)) {
                $cfg['database'][$key][$field] = sahtout_eval_exported_value($m[1]);
            }
        }
    }

    return $cfg;
}

function sahtout_migrate_has_database_credentials(array $cfg): bool
{
    foreach (['auth', 'world', 'char', 'site'] as $key) {
        if (trim((string) ($cfg['database'][$key]['user'] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Parse a legacy monolithic config.php (or backup copy) without bootstrapping the app.
 */
function sahtout_migrate_from_config_source(string $src): ?array
{
    if (!sahtout_is_legacy_monolithic_config_source($src)) {
        return null;
    }

    $cfg = sahtout_default_local_config();
    $cfg = sahtout_migrate_database_from_config_source($src, $cfg);
    $cfg = sahtout_migrate_apply_legacy_split_files(__DIR__, $cfg);

    return sahtout_migrate_has_database_credentials($cfg) ? $cfg : null;
}

/**
 * One-time migration from legacy split config files (pre–config.local.php).
 *
 * @param string|null $legacyMainPath Optional path to old monolithic config.php (backup)
 */
function sahtout_migrate_legacy_config_files(?string $legacyMainPath = null): ?array
{
    $dir = __DIR__;
    $candidates = array_filter([
        $legacyMainPath,
        $dir . '/config.legacy.php',
        $dir . '/config.php.bak',
        $dir . '/config.php.old',
    ]);

    foreach ($candidates as $path) {
        if (!is_string($path) || $path === '' || !is_file($path)) {
            continue;
        }
        $mainSrc = file_get_contents($path);
        if ($mainSrc === false) {
            continue;
        }
        $migrated = sahtout_migrate_from_config_source($mainSrc);
        if ($migrated !== null) {
            return $migrated;
        }
    }

    $cfg = sahtout_default_local_config();
    $cfg = sahtout_migrate_apply_legacy_split_files($dir, $cfg);

    return sahtout_migrate_has_database_credentials($cfg) ? $cfg : null;
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
