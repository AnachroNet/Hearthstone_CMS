<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'soap_client.php';

function sahtout_admin_server_comm_feedback_html(string $type, string $message): string
{
    $classes = [
        'success' => 'bg-green-900/40 border-green-500/50 text-green-300',
        'error' => 'bg-red-900/40 border-red-500/50 text-red-300',
        'warning' => 'bg-yellow-900/40 border-yellow-500/50 text-yellow-300',
    ];
    $class = $classes[$type] ?? $classes['error'];
    $icon = $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';

    return '<div class="' . $class . ' border px-4 py-3 rounded-sm flex items-center gap-3">'
        . '<i class="fas ' . $icon . '"></i>'
        . '<span>' . htmlspecialchars($message) . '</span>'
        . '</div>';
}

function sahtout_admin_server_comm_soap_response_html(string $soapOutput): string
{
    return '<div class="bg-black/30 p-4 rounded-sm border border-[rgba(201,162,39,0.1)] overflow-x-auto">'
        . '<pre class="text-green-400 text-sm font-mono whitespace-pre-wrap break-words m-0">'
        . htmlspecialchars(sahtout_soap_extract_command_message($soapOutput, true))
        . '</pre></div>';
}

/**
 * @return array{feedback: ?string, motd: string, skip_motd_fetch: bool}
 */
function sahtout_admin_server_comm_handle_post(bool $soapConfigured): array
{
    $result = ['feedback' => null, 'motd' => '', 'skip_motd_fetch' => false];
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return $result;
    }
    if (!isset($_POST['gm_action'])) {
        return $result;
    }

    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    $validCsrf = $csrfToken !== '' && hash_equals($csrfToken, (string) ($_SESSION['csrf_token'] ?? ''));
    $action = (string) $_POST['gm_action'];

    if (!$validCsrf) {
        $result['feedback'] = sahtout_admin_server_comm_feedback_html(
            'error',
            translate('error_csrf_token', 'Invalid or missing security token. Please refresh the page and try again.')
        );

        return $result;
    }
    if (!$soapConfigured) {
        $result['feedback'] = sahtout_admin_server_comm_feedback_html(
            'error',
            translate('gm_cmd_soap_missing', 'SOAP is not configured. Set it up under Admin → Settings → SOAP.')
        );

        return $result;
    }

    if ($action === 'set_motd') {
        $motd = trim((string) ($_POST['server_motd'] ?? ''));
        if ($motd === '') {
            $result['feedback'] = sahtout_admin_server_comm_feedback_html(
                'error',
                translate('gm_cmd_motd_empty', 'Enter a message of the day before saving.')
            );

            return $result;
        }
        $command = sahtout_motd_build_set_command($motd);
        $soapResult = sahtout_soap_execute_command($command);
        if ($soapResult['ok']) {
            $result['motd'] = $motd;
            $result['skip_motd_fetch'] = true;
            $result['feedback'] = sahtout_admin_server_comm_feedback_html(
                'success',
                translate('gm_cmd_motd_saved', 'Server MOTD was updated.')
            );
        } else {
            $result['feedback'] = sahtout_admin_server_comm_feedback_html(
                'error',
                translate('gm_cmd_motd_failed', 'Could not update server MOTD. Check SOAP logs.')
            )
                . sahtout_admin_server_comm_soap_response_html($soapResult['output']);
        }

        return $result;
    }

    if ($action === 'broadcast') {
        $message = trim((string) ($_POST['broadcast_message'] ?? ''));
        if ($message === '') {
            $result['feedback'] = sahtout_admin_server_comm_feedback_html(
                'error',
                translate('gm_cmd_broadcast_empty', 'Enter a broadcast message before sending.')
            );

            return $result;
        }
        $command = '.announce ' . sahtout_soap_quote_command_arg($message);
        $soapResult = sahtout_soap_execute_command($command);
        if ($soapResult['ok']) {
            $result['feedback'] = sahtout_admin_server_comm_feedback_html(
                'success',
                translate('gm_cmd_broadcast_sent', 'Broadcast was sent to online players.')
            )
                . sahtout_admin_server_comm_soap_response_html($soapResult['output']);
        } else {
            $result['feedback'] = sahtout_admin_server_comm_feedback_html(
                'error',
                translate('gm_cmd_broadcast_failed', 'Could not send broadcast. Check SOAP logs.')
            )
                . sahtout_admin_server_comm_soap_response_html($soapResult['output']);
        }
    }

    return $result;
}

/**
 * @return array{motd: string, load_notice: ?string}
 */
function sahtout_admin_server_comm_load_motd(bool $soapConfigured, bool $skipFetch): array
{
    if (!$soapConfigured || $skipFetch) {
        return ['motd' => '', 'load_notice' => null];
    }
    $motdResult = sahtout_soap_execute_command('.server motd');
    if ($motdResult['ok']) {
        return [
            'motd' => sahtout_motd_enus_from_command_output($motdResult['output']),
            'load_notice' => null,
        ];
    }

    return [
        'motd' => '',
        'load_notice' => translate('gm_cmd_motd_load_failed', 'Could not load the current MOTD from the worldserver.'),
    ];
}
