<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
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

    if ($httpCode >= 400) {
        return ['ok' => false, 'output' => (string) $response, 'error' => 'http_' . $httpCode];
    }

    return ['ok' => true, 'output' => (string) $response, 'error' => null];
}

function sahtout_try_reload_gm_tickets(): bool
{
    $result = sahtout_soap_execute_command('reload gm_tickets');
    return $result['ok'];
}
