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
