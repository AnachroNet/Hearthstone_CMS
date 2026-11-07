<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

function sahtout_soap_is_configured(): bool
{
    if (!defined('SAHTOUT_CONFIG_LOADED')) {
        require_once __DIR__ . '/config.php';
    }
    require_once __DIR__ . '/soap.conf.php';
    global $soap_url, $soap_user, $soap_pass;

    return !empty($soap_url) && !empty($soap_user) && !empty($soap_pass);
}

function sahtout_soap_response_indicates_failure(string $response): bool
{
    if ($response === '') {
        return false;
    }

    $needles = [
        'SOAP-ENV:Fault',
        ':Fault>',
        'There is no such command',
        'Unknown command',
        'Command can not be empty',
        'There is no such subcommand',
        'Account name can not be empty',
        'Account not exist',
        'not deleted',
        'can\'t be deleted',
        'cannot be deleted',
        'Insufficient permissions',
        'Permission denied',
        'gmlevel is too low',
    ];
    foreach ($needles as $needle) {
        if (stripos($response, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Execute a worldserver SOAP GM command when SOAP is configured.
 *
 * @return array{ok: bool, output: string, error: ?string}
 */
function sahtout_soap_execute_command(string $command): array
{
    $command = trim($command);
    if ($command === '') {
        return ['ok' => false, 'output' => '', 'error' => 'empty_command'];
    }

    if (!defined('SAHTOUT_CONFIG_LOADED')) {
        require_once __DIR__ . '/config.php';
    }
    require_once __DIR__ . '/soap.conf.php';

    global $soap_url, $soap_user, $soap_pass;
    if (empty($soap_url) || empty($soap_user) || empty($soap_pass)) {
        return ['ok' => false, 'output' => '', 'error' => 'soap_not_configured'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'output' => '', 'error' => 'curl_missing'];
    }

    $xml = '<?xml version="1.0" encoding="utf-8"?>'
        . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<SOAP-ENV:Body>'
        . '<ns1:executeCommand xmlns:ns1="urn:AC">'
        . '<command>' . htmlspecialchars($command, ENT_QUOTES) . '</command>'
        . '</ns1:executeCommand>'
        . '</SOAP-ENV:Body>'
        . '</SOAP-ENV:Envelope>';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $soap_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $soap_user . ':' . $soap_pass);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/xml',
        'Content-Length: ' . strlen($xml),
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'output' => '', 'error' => $err !== '' ? $err : 'curl_failed'];
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = (string) $response;

    if ($httpCode === 0) {
        return ['ok' => false, 'output' => $body, 'error' => 'http_0'];
    }

    if ($httpCode >= 400) {
        return ['ok' => false, 'output' => $body, 'error' => 'http_' . $httpCode];
    }

    if (sahtout_soap_response_indicates_failure($body)) {
        return ['ok' => false, 'output' => $body, 'error' => 'soap_fault'];
    }

    return ['ok' => true, 'output' => $body, 'error' => null];
}

function sahtout_soap_quote_command_arg(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\n"], ' ', trim($text));
    $text = str_replace('"', "'", $text);

    return '"' . $text . '"';
}

/**
 * Auth account name for SOAP (e.g. .account delete). Must not be quoted — quoted names fail lookup.
 */
function sahtout_soap_account_name_literal(string $username): ?string
{
    $username = strtoupper(trim($username));
    if ($username === '' || !preg_match('/^[A-Z0-9]{1,32}$/', $username)) {
        return null;
    }

    return $username;
}

/** Character name for SOAP erase/kick — quote only when needed. */
function sahtout_soap_character_name_for_command(string $name): string
{
    $name = trim($name);
    if ($name !== '' && preg_match('/^[A-Za-z]{2,12}$/', $name)) {
        return $name;
    }

    return sahtout_soap_quote_command_arg($name);
}

function sahtout_soap_message_indicates_player_not_in_world(string $text): bool
{
    $text = strtolower(trim($text));
    if ($text === '') {
        return false;
    }
    $phrases = [
        'is not online',
        'not online',
        'no player named',
        'player not found',
        'couldn\'t find',
        'could not find',
        'doesn\'t exist',
        'does not exist',
        'not found',
        'not currently online',
        'is offline',
        'not in world',
        'no such player',
        'there is no such',
    ];
    foreach ($phrases as $phrase) {
        if (str_contains($text, $phrase)) {
            return true;
        }
    }

    return false;
}

/**
 * @return null|string Null when no onlinelist command succeeded (permissions / SOAP error).
 */
function sahtout_soap_account_onlinelist_text(): ?string
{
    if (!sahtout_soap_is_configured()) {
        return null;
    }
    foreach (['.account onlinelist', '.account online'] as $command) {
        $result = sahtout_soap_execute_command($command);
        if (!$result['ok']) {
            $body = strtolower(sahtout_soap_extract_command_message($result['output'] . ' ' . (string) ($result['error'] ?? ''), false));
            if (str_contains($body, 'insufficient permissions') || str_contains($body, 'permission denied')) {
                continue;
            }
            if (str_contains($body, 'there is no such command') || str_contains($body, 'unknown command')) {
                continue;
            }
            continue;
        }
        $text = sahtout_soap_extract_command_message($result['output'], true);
        if ($text !== '') {
            return $text;
        }
    }

    return null;
}

function sahtout_soap_onlinelist_contains_name(string $listText, string $name): bool
{
    $name = trim($name);
    if ($name === '' || trim($listText) === '') {
        return false;
    }
    $pattern = '/\b' . preg_quote($name, '/') . '\b/i';

    return (bool) preg_match($pattern, $listText);
}

function sahtout_soap_message_indicates_player_in_world(string $text): bool
{
    $text = strtolower(trim($text));
    if ($text === '') {
        return false;
    }
    if (preg_match('/online\s+for:/i', $text)) {
        return true;
    }
    if (preg_match('/latency:\s*\d+\s*ms/i', $text) && preg_match('/\b(map|zone|area)\s*:/i', $text)) {
        return true;
    }

    return false;
}

/**
 * Whether the character is connected to the worldserver right now.
 * Uses .account onlinelist when the SOAP user can run it; falls back to .pinfo parse.
 * Null = could not determine (no SOAP or ambiguous pinfo).
 */
function sahtout_soap_character_in_world(string $characterName, ?string $accountLogin = null): ?bool
{
    if (!sahtout_soap_is_configured()) {
        return null;
    }
    $characterName = trim($characterName);
    if ($characterName === '') {
        return null;
    }

    $listText = sahtout_soap_account_onlinelist_text();
    if ($listText !== null) {
        if (sahtout_soap_onlinelist_contains_name($listText, $characterName)) {
            return true;
        }
        if ($accountLogin !== null && $accountLogin !== '' && sahtout_soap_onlinelist_contains_name($listText, $accountLogin)) {
            return true;
        }

        return false;
    }

    $literal = sahtout_soap_character_name_for_command($characterName);
    $result = sahtout_soap_execute_command('.pinfo ' . $literal);
    if (!$result['ok']) {
        $body = strtolower(sahtout_soap_extract_command_message($result['output'] . ' ' . (string) ($result['error'] ?? ''), false));
        if (sahtout_soap_message_indicates_player_not_in_world($body)) {
            return false;
        }

        return null;
    }

    $text = sahtout_soap_extract_command_message($result['output'], true);
    $lower = strtolower($text);
    if (sahtout_soap_message_indicates_player_not_in_world($lower)) {
        return false;
    }
    if (sahtout_soap_message_indicates_player_in_world($text)) {
        return true;
    }

    return null;
}

const SAHTOUT_MOTD_LOCALE = 'enUS';

function sahtout_motd_build_set_command(string $motd): string
{
    return '.server set motd ' . SAHTOUT_MOTD_LOCALE . ' ' . sahtout_soap_quote_command_arg($motd);
}

function sahtout_motd_enus_from_command_output(string $soapResponse): string
{
    $text = sahtout_soap_extract_command_message($soapResponse, true);
    $text = preg_replace('/^Message of the day:\s*/im', '', $text) ?? $text;
    $text = preg_replace('/^Server motd is:\s*/im', '', $text) ?? $text;

    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^enUS\s*:\s*(.*)$/iu', $line, $matches)) {
            return trim($matches[1]);
        }
    }

    $trimmed = trim($text);
    if ($trimmed !== '' && !preg_match('/^[a-z]{2}[A-Z]{2}\s*:/', $trimmed)) {
        return $trimmed;
    }

    return '';
}

function sahtout_soap_extract_command_message(string $soapResponse, bool $preserveLineBreaks = false): string
{
    $text = $soapResponse;
    if (preg_match('/<(?:[\w]+:)?result[^>]*>(.*)<\/(?:[\w]+:)?result>/si', $soapResponse, $matches)) {
        $text = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    } elseif (preg_match('/<(?:[\w]+:)?executeCommandResult[^>]*>(.*)<\/(?:[\w]+:)?executeCommandResult>/si', $soapResponse, $matches)) {
        $text = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    $text = strip_tags($text);
    if ($preserveLineBreaks) {
        return trim($text);
    }

    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

function sahtout_try_reload_gm_tickets(): bool
{
    $commands = ['.reload gm_tickets', 'reload gm_tickets'];
    foreach ($commands as $command) {
        $result = sahtout_soap_execute_command($command);
        if ($result['ok']) {
            return true;
        }
        error_log('[soap_client] ' . $command . ' failed: ' . ($result['error'] ?? 'unknown')
            . ' ' . substr(preg_replace('/\s+/', ' ', $result['output']) ?? '', 0, 400));
    }

    return false;
}

/**
 * Run one reload command, trying with and without a leading dot.
 *
 * @return array{ok: bool, configured: bool, error: ?string}
 */
function sahtout_soap_try_single_reload(string $command): array
{
    if (!sahtout_soap_is_configured()) {
        return ['ok' => false, 'configured' => false, 'error' => 'soap_not_configured'];
    }

    $command = trim($command);
    $variants = [$command];
    if (str_starts_with($command, '.')) {
        $variants[] = substr($command, 1);
    } else {
        $variants[] = '.' . $command;
    }

    foreach (array_unique($variants) as $variant) {
        $result = sahtout_soap_execute_command($variant);
        if ($result['ok']) {
            return ['ok' => true, 'configured' => true, 'error' => null];
        }
        error_log('[soap_client] ' . $variant . ' failed: ' . ($result['error'] ?? 'unknown')
            . ' ' . substr(preg_replace('/\s+/', ' ', $result['output']) ?? '', 0, 400));
    }

    return ['ok' => false, 'configured' => true, 'error' => 'reload_failed'];
}

/**
 * @param list<string> $commands
 * @return array{ok: bool, configured: bool, failed: list<string>}
 */
function sahtout_soap_try_reload_commands(array $commands): array
{
    if (!sahtout_soap_is_configured()) {
        return ['ok' => false, 'configured' => false, 'failed' => $commands];
    }

    $failed = [];
    foreach ($commands as $command) {
        $one = sahtout_soap_try_single_reload($command);
        if (!$one['ok']) {
            $failed[] = $command;
        }
    }

    return ['ok' => $failed === [], 'configured' => true, 'failed' => $failed];
}

/** @return array{ok: bool, configured: bool, failed: list<string>} */
function sahtout_soap_reload_loot_tables(): array
{
    $bulk = sahtout_soap_try_reload_commands(['.reload all loot']);
    if ($bulk['ok']) {
        return $bulk;
    }

    return sahtout_soap_try_reload_commands([
        '.reload gameobject_loot_template',
        '.reload creature_loot_template',
    ]);
}

/**
 * AzerothCore has no `.reload item_template`; item rows are picked up on world restart.
 * Quest, page_text, and loot tables can be hot-reloaded via SOAP.
 *
 * @return array{ok: bool, configured: bool, failed: list<string>}
 */
function sahtout_soap_merge_reload_results(array $first, array $second): array
{
    $configured = !empty($first['configured']) || !empty($second['configured']);
    $failed = array_values(array_unique(array_merge($first['failed'] ?? [], $second['failed'] ?? [])));

    return [
        'ok' => !empty($first['ok']) && !empty($second['ok']),
        'configured' => $configured,
        'failed' => $failed,
    ];
}

/**
 * Reload item templates before quests so StartItem validation succeeds when SOAP can load the item.
 *
 * @return array{ok: bool, configured: bool, failed: list<string>, pending_restart?: bool}
 */
function sahtout_soap_reload_token_gift_world_content(int $itemEntry = 0, string $itemName = ''): array
{
    $itemReload = sahtout_soap_try_reload_commands([
        '.reload all item',
        '.reload item_template_locale',
    ]);

    if ($itemEntry > 0) {
        $lookup = sahtout_soap_world_has_item_template($itemEntry, $itemName);
        if (!$lookup['ok']) {
            return [
                'ok' => !empty($itemReload['ok']),
                'configured' => !empty($itemReload['configured']),
                'failed' => array_values(array_unique(array_merge(
                    $itemReload['failed'] ?? [],
                    ['quest_reload_skipped_item_not_in_memory']
                ))),
                'pending_restart' => true,
            ];
        }
    }

    $questReload = sahtout_soap_try_reload_commands([
        '.reload quest_template_locale',
        '.reload quest_template',
    ]);

    return [
        'ok' => !empty($itemReload['ok']) && !empty($questReload['ok']),
        'configured' => !empty($itemReload['configured']) || !empty($questReload['configured']),
        'failed' => array_values(array_unique(array_merge(
            $itemReload['failed'] ?? [],
            $questReload['failed'] ?? []
        ))),
    ];
}

function sahtout_soap_reload_token_gift_publish(int $itemEntry = 0, string $itemName = ''): array
{
    $core = sahtout_soap_reload_token_gift_world_content($itemEntry, $itemName);
    if (!empty($core['pending_restart'])) {
        return $core;
    }

    $loot = sahtout_soap_reload_loot_tables();

    return sahtout_soap_merge_reload_results($core, $loot);
}

/** @return array{ok: bool, configured: bool, failed: list<string>} */
function sahtout_soap_reload_token_gift_loot(): array
{
    return sahtout_soap_reload_loot_tables();
}

/** @return array{ok: bool, configured: bool, failed: list<string>, pending_restart?: bool} */
function sahtout_soap_reload_token_gift_content(int $itemEntry = 0, string $itemName = ''): array
{
    return sahtout_soap_reload_token_gift_world_content($itemEntry, $itemName);
}

/**
 * Check whether the running worldserver has an item template loaded (post-reload).
 *
 * @return array{ok: bool, command: string, output: string, error: ?string}
 */
function sahtout_soap_world_has_item_template(int $itemEntry, string $itemName): array
{
    if ($itemEntry <= 0) {
        return ['ok' => false, 'command' => '', 'output' => '', 'error' => 'invalid_entry'];
    }

    $namePart = trim($itemName);
    if ($namePart === '') {
        $namePart = (string) $itemEntry;
    }
    if (mb_strlen($namePart) > 24) {
        $namePart = mb_substr($namePart, 0, 24);
    }

    $commands = [
        '.lookup item ' . sahtout_soap_quote_command_arg($namePart),
        '.lookup item ' . $itemEntry,
    ];

    $last = ['ok' => false, 'command' => $commands[0], 'output' => '', 'error' => 'not_found'];
    foreach ($commands as $command) {
        $result = sahtout_soap_execute_command($command);
        if (!$result['ok']) {
            $last = [
                'ok' => false,
                'command' => $command,
                'output' => sahtout_soap_extract_command_message($result['output'], true),
                'error' => $result['error'] ?? 'soap_failed',
            ];
            continue;
        }

        $message = sahtout_soap_extract_command_message($result['output'], true);
        $lower = strtolower($message);
        if (str_contains($lower, 'itemidinvalid')
            || str_contains($lower, 'no item found')
            || str_contains($lower, 'could not find')
            || str_contains($lower, 'no such command')) {
            $last = ['ok' => false, 'command' => $command, 'output' => $message, 'error' => 'not_in_memory'];
            continue;
        }

        if (preg_match('/\b' . preg_quote((string) $itemEntry, '/') . '\b/', $message)) {
            return ['ok' => true, 'command' => $command, 'output' => $message, 'error' => null];
        }

        $last = ['ok' => false, 'command' => $command, 'output' => $message, 'error' => 'entry_not_listed'];
    }

    return $last;
}

/**
 * @return array{ok: bool, command: string, output: string, error: ?string}
 */
function sahtout_soap_world_has_quest_template(int $questId, string $questTitle): array
{
    if ($questId <= 0) {
        return ['ok' => false, 'command' => '', 'output' => '', 'error' => 'invalid_quest'];
    }

    $titlePart = trim($questTitle);
    if ($titlePart === '') {
        $titlePart = (string) $questId;
    }
    if (mb_strlen($titlePart) > 24) {
        $titlePart = mb_substr($titlePart, 0, 24);
    }

    $command = '.lookup quest ' . sahtout_soap_quote_command_arg($titlePart);
    $result = sahtout_soap_execute_command($command);
    if (!$result['ok']) {
        return [
            'ok' => false,
            'command' => $command,
            'output' => sahtout_soap_extract_command_message($result['output'], true),
            'error' => $result['error'] ?? 'soap_failed',
        ];
    }

    $message = sahtout_soap_extract_command_message($result['output'], true);
    $lower = strtolower($message);
    if (str_contains($lower, 'could not find') || str_contains($lower, 'no quest found')) {
        return ['ok' => false, 'command' => $command, 'output' => $message, 'error' => 'not_in_memory'];
    }

    $ok = preg_match('/\b' . preg_quote((string) $questId, '/') . '\b/', $message) === 1;

    return [
        'ok' => $ok,
        'command' => $command,
        'output' => $message,
        'error' => $ok ? null : 'quest_id_not_listed',
    ];
}
