<?php
if (!defined('ALLOWED_ACCESS')) {
    define('ALLOWED_ACCESS', true);
}

if (defined('SAHTOUT_CONFIG_LOADED')) {
    return;
}

require_once __DIR__ . '/config_local_io.php';
require_once __DIR__ . '/site_settings.php';
require_once __DIR__ . '/release.php';

$sahtout_local = sahtout_load_local_config();
$localConfigPath = sahtout_local_config_path();
if (!is_file($localConfigPath) && trim((string) ($sahtout_local['database']['site']['user'] ?? '')) === '') {
    http_response_code(503);
    exit(
        'Missing includes/config.local.php. Copy includes/config.local.example.php to includes/config.local.php and configure your server.'
    );
}

$dbKeys = ['auth', 'world', 'char', 'site'];
foreach ($dbKeys as $dbKey) {
    $d = $sahtout_local['database'][$dbKey] ?? [];
    ${"db_{$dbKey}_host"} = (string) ($d['host'] ?? 'localhost');
    ${"db_{$dbKey}_port"} = (string) ($d['port'] ?? 3306);
    ${"db_{$dbKey}_user"} = (string) ($d['user'] ?? '');
    ${"db_{$dbKey}_pass"} = (string) ($d['pass'] ?? '');
    ${"db_{$dbKey}_name"} = (string) ($d['name'] ?? '');
}

$db_playerbots_name = trim((string) ($sahtout_local['database']['playerbots']['name'] ?? 'acore_playerbots'));

$auth_db = new mysqli($db_auth_host, $db_auth_user, $db_auth_pass, $db_auth_name, (int) $db_auth_port);
$world_db = new mysqli($db_world_host, $db_world_user, $db_world_pass, $db_world_name, (int) $db_world_port);
$char_db = new mysqli($db_char_host, $db_char_user, $db_char_pass, $db_char_name, (int) $db_char_port);
$site_db = new mysqli($db_site_host, $db_site_user, $db_site_pass, $db_site_name, (int) $db_site_port);

if ($auth_db->connect_error) {
    die('Auth DB Connection failed: ' . $auth_db->connect_error);
}
if ($world_db->connect_error) {
    die('World DB Connection failed: ' . $world_db->connect_error);
}
if ($char_db->connect_error) {
    die('Char DB Connection failed: ' . $char_db->connect_error);
}
if ($site_db->connect_error) {
    die('Site DB Connection failed: ' . $site_db->connect_error);
}

$smtp_cfg = $sahtout_local['smtp'] ?? [];
$smtp_enabled = !empty($smtp_cfg['enabled']);
$smtp_host = (string) ($smtp_cfg['host'] ?? '');
$smtp_user = (string) ($smtp_cfg['user'] ?? '');
$smtp_pass = (string) ($smtp_cfg['pass'] ?? '');
$smtp_from = (string) ($smtp_cfg['from'] ?? 'noreply@yourdomain.com');
$smtp_name = (string) ($smtp_cfg['name'] ?? 'Sahtout Account');
$smtp_port = (int) ($smtp_cfg['port'] ?? 587);
$smtp_secure = (string) ($smtp_cfg['secure'] ?? 'tls');
$smtp_allow_self_signed = !empty($smtp_cfg['allow_self_signed']);

$recaptcha_cfg = $sahtout_local['recaptcha'] ?? [];
$recaptcha_enabled = !empty($recaptcha_cfg['enabled']);
$recaptcha_site_key = (string) ($recaptcha_cfg['site_key'] ?? '');
$recaptcha_secret_key = (string) ($recaptcha_cfg['secret_key'] ?? '');
if (!defined('RECAPTCHA_ENABLED')) {
    define('RECAPTCHA_ENABLED', $recaptcha_enabled);
}
if (!defined('RECAPTCHA_SITE_KEY')) {
    define('RECAPTCHA_SITE_KEY', $recaptcha_site_key);
}
if (!defined('RECAPTCHA_SECRET_KEY')) {
    define('RECAPTCHA_SECRET_KEY', $recaptcha_secret_key);
}

$soap_cfg = $sahtout_local['soap'] ?? [];
$soap_url = (string) ($soap_cfg['url'] ?? '');
$soap_user = (string) ($soap_cfg['user'] ?? '');
$soap_pass = (string) ($soap_cfg['pass'] ?? '');

$realmlist = [];
foreach ($sahtout_local['realms'] ?? [] as $idx => $realm) {
    if (!is_array($realm)) {
        continue;
    }
    $realmlist[$idx] = $realm;
}

$armoryConfig = $sahtout_local['armory'] ?? [
    'solo_pvp_display' => 'humans_only',
    'solo_pvp_include_scored_bots' => true,
    'arena_2v2_display' => 'all',
    'arena_3v3_display' => 'all',
    'arena_5v5_display' => 'all',
];

$site_timezone = trim((string) ($sahtout_local['timezone'] ?? ''));
if ($site_timezone === '') {
    $site_timezone = date_default_timezone_get() ?: 'UTC';
}

sahtout_seed_site_settings_from_legacy($site_db);
$sahtout_site_settings = sahtout_site_settings_load_all($site_db);
sahtout_apply_site_settings_globals($sahtout_site_settings);

if (!function_exists('getMailer')) {
    function getMailer(): \PHPMailer\PHPMailer\PHPMailer
    {
        global $smtp_enabled, $smtp_host, $smtp_user, $smtp_pass, $smtp_from, $smtp_name, $smtp_port, $smtp_secure, $smtp_allow_self_signed;

        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new \RuntimeException(
                'PHPMailer is not installed. Run "composer install" in the project root to enable email features.'
            );
        }
        require_once $autoload;

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);

            if (!empty($smtp_enabled)) {
                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_user;
                $mail->Password = $smtp_pass;
                $mail->Port = (int) $smtp_port;
                $mail->SMTPSecure = $smtp_secure === 'ssl'
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : ($smtp_secure === 'tls'
                        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
                        : '');
                $mail->SMTPAutoTLS = !empty($smtp_secure);
                $mail->Timeout = 15;

                $isLocalHost = in_array(strtolower((string) $smtp_host), ['localhost', '127.0.0.1', '::1'], true)
                    || (is_string($smtp_host) && (str_ends_with(strtolower($smtp_host), '.local') || str_ends_with(strtolower($smtp_host), '.test')));
                if (!empty($smtp_allow_self_signed) || $isLocalHost) {
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ],
                    ];
                }
            } else {
                $mail->isMail();
            }

            $isPlaceholderFrom = empty($smtp_from) || $smtp_from === 'noreply@yourdomain.com';
            if ($isPlaceholderFrom && filter_var($smtp_user ?? '', FILTER_VALIDATE_EMAIL)) {
                $fromAddress = $smtp_user;
            } elseif (filter_var($smtp_from ?? '', FILTER_VALIDATE_EMAIL)) {
                $fromAddress = $smtp_from;
            } elseif (filter_var($smtp_user ?? '', FILTER_VALIDATE_EMAIL)) {
                $fromAddress = $smtp_user;
            } else {
                $fromAddress = !empty($smtp_from) ? $smtp_from : 'noreply@yourdomain.com';
            }
            $fromName = !empty($smtp_name) ? $smtp_name : 'Sahtout Account';
            $mail->setFrom($fromAddress, $fromName);
        } catch (\Exception $e) {
            $mail->ErrorInfo = $e->getMessage();
        }

        return $mail;
    }
}

define('SAHTOUT_CONFIG_LOADED', true);
