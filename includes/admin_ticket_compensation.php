<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

require_once __DIR__ . '/account_gm_tickets.php';
require_once __DIR__ . '/soap_client.php';

const SAHTOUT_TICKET_COMP_MAX_ITEMS = 5;

function sahtout_compensation_sanitize_mail_text(string $text, int $maxLen = 800): string
{
    $text = trim(preg_replace("/[\r\n\t]+/", ' ', $text) ?? '');
    $text = str_replace('"', "'", $text);
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
    }

    return $text;
}

function sahtout_compensation_valid_character_name(string $name): bool
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 12) {
        return false;
    }

    return (bool) preg_match("/^[A-Za-z]{2,12}$/", $name);
}

function sahtout_compensation_soap_quote(string $text): string
{
    return '"' . str_replace('"', "'", $text) . '"';
}

/**
 * @return array{ok: bool, message: string, soap_output: string}
 */
function sahtout_compensation_soap_command(string $command): array
{
    $fail = static function (string $code, string $soapOutput = ''): array {
        return ['ok' => false, 'message' => $code, 'soap_output' => $soapOutput];
    };

    $soap = sahtout_soap_execute_command($command);
    if (!$soap['ok']) {
        return $fail('soap_failed', (string) ($soap['error'] ?? $soap['output']));
    }

    return ['ok' => true, 'message' => 'ok', 'soap_output' => $soap['output']];
}

/**
 * @param list<mixed> $entries
 * @param list<mixed> $quantities
 * @return array{ok: bool, message: string, items: list<array{entry: int, count: int}>}
 */
function sahtout_compensation_normalize_item_rows(array $entries, array $quantities): array
{
    $items = [];

    for ($i = 0; $i < SAHTOUT_TICKET_COMP_MAX_ITEMS; $i++) {
        $entry = isset($entries[$i]) ? (int) $entries[$i] : 0;
        $count = isset($quantities[$i]) ? (int) $quantities[$i] : 0;

        if ($entry <= 0 && $count <= 0) {
            continue;
        }
        if ($entry <= 0 || $count <= 0 || $count > 200) {
            return ['ok' => false, 'message' => 'invalid_item', 'items' => []];
        }
        $items[] = ['entry' => $entry, 'count' => $count];
    }

    if (count($items) > SAHTOUT_TICKET_COMP_MAX_ITEMS) {
        return ['ok' => false, 'message' => 'invalid_item', 'items' => []];
    }

    return ['ok' => true, 'message' => 'ok', 'items' => $items];
}

/**
 * @return array{ok: bool, message: string, soap_output: string, character_name?: string, subject?: string, body?: string}
 */
function sahtout_compensation_resolve_ticket_mail_context(mysqli $charDb, int $ticketId, string $subject, string $body): array
{
    $fail = static function (string $code): array {
        return ['ok' => false, 'message' => $code, 'soap_output' => ''];
    };

    if (!sahtout_soap_is_configured()) {
        return $fail('soap_not_configured');
    }

    $ticket = sahtout_admin_gm_ticket_by_id($charDb, $ticketId);
    if ($ticket === null) {
        return $fail('not_found');
    }

    $charName = (string) ($ticket['character_name'] ?? '');
    if (!sahtout_compensation_valid_character_name($charName)) {
        return $fail('invalid_character');
    }

    $subject = sahtout_compensation_sanitize_mail_text($subject, 128);
    $body = sahtout_compensation_sanitize_mail_text($body, 800);
    if ($subject === '' || $body === '') {
        return $fail('invalid_mail_text');
    }

    return [
        'ok' => true,
        'message' => 'ok',
        'soap_output' => '',
        'character_name' => $charName,
        'subject' => $subject,
        'body' => $body,
    ];
}

/**
 * @param list<array{entry: int, count: int}> $items
 * @return array{ok: bool, message: string, soap_output: string}
 */
function sahtout_admin_soap_send_to_character(
    string $charName,
    string $subject,
    string $body,
    array $items
): array {
    $fail = static function (string $code, string $soapOutput = ''): array {
        return ['ok' => false, 'message' => $code, 'soap_output' => $soapOutput];
    };

    if (!sahtout_soap_is_configured()) {
        return $fail('soap_not_configured');
    }

    $charName = trim($charName);
    if (!sahtout_compensation_valid_character_name($charName)) {
        return $fail('invalid_character');
    }

    $subject = sahtout_compensation_sanitize_mail_text($subject, 128);
    $body = sahtout_compensation_sanitize_mail_text($body, 800);
    if ($subject === '' || $body === '') {
        return $fail('invalid_mail_text');
    }

    $quotedSubject = sahtout_compensation_soap_quote($subject);
    $quotedBody = sahtout_compensation_soap_quote($body);

    if ($items === []) {
        $command = sprintf('.send mail %s %s %s', $charName, $quotedSubject, $quotedBody);
        $result = sahtout_compensation_soap_command($command);
        if (!$result['ok']) {
            return $fail($result['message'], $result['soap_output']);
        }

        return ['ok' => true, 'message' => 'mail_sent', 'soap_output' => $result['soap_output']];
    }

    $parts = [];
    foreach ($items as $item) {
        $parts[] = (int) $item['entry'] . ':' . (int) $item['count'];
    }
    $itemTail = implode(' ', $parts);

    $command = sprintf(
        '.send items %s %s %s %s',
        $charName,
        $quotedSubject,
        $quotedBody,
        $itemTail
    );

    $result = sahtout_compensation_soap_command($command);
    if (!$result['ok']) {
        return $fail($result['message'], $result['soap_output']);
    }

    return ['ok' => true, 'message' => 'mail_sent', 'soap_output' => $result['soap_output']];
}

/**
 * @param list<array{entry: int, count: int}> $items
 * @return array{ok: bool, message: string, soap_output: string}
 */
function sahtout_admin_compensation_send_ingame_mail(
    mysqli $charDb,
    int $ticketId,
    string $subject,
    string $body,
    array $items
): array {
    $fail = static function (string $code, string $soapOutput = ''): array {
        return ['ok' => false, 'message' => $code, 'soap_output' => $soapOutput];
    };

    $ctx = sahtout_compensation_resolve_ticket_mail_context($charDb, $ticketId, $subject, $body);
    if (!$ctx['ok']) {
        return $fail($ctx['message']);
    }

    return sahtout_admin_soap_send_to_character(
        $ctx['character_name'],
        $ctx['subject'],
        $ctx['body'],
        $items
    );
}

/**
 * @param list<int> $accountIds
 * @return array<int, int>
 */
function sahtout_account_token_balances(mysqli $siteDb, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
    if ($accountIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = $siteDb->prepare("SELECT account_id, tokens FROM user_currencies WHERE account_id IN ($placeholders)");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[(int) $row['account_id']] = (int) ($row['tokens'] ?? 0);
    }
    if ($res) {
        $res->free();
    }
    $stmt->close();

    return $map;
}

/**
 * @return array{ok: bool, message: string, new_balance: ?int}
 */
function sahtout_admin_compensation_award_tokens(mysqli $siteDb, int $ticketId, mysqli $charDb, int $amount): array
{
    $fail = static function (string $code, ?int $balance = null): array {
        return ['ok' => false, 'message' => $code, 'new_balance' => $balance];
    };

    if ($amount <= 0 || $amount > 9999999) {
        return $fail('invalid_tokens');
    }

    $ticket = sahtout_admin_gm_ticket_by_id($charDb, $ticketId);
    if ($ticket === null) {
        return $fail('not_found');
    }

    $accountId = (int) ($ticket['account_id'] ?? 0);
    if ($accountId <= 0) {
        return $fail('no_account');
    }

    $stmt = $siteDb->prepare(
        'UPDATE user_currencies SET tokens = LEAST(tokens + ?, 9999999) WHERE account_id = ?'
    );
    if (!$stmt) {
        return $fail('update_failed');
    }
    $stmt->bind_param('ii', $amount, $accountId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        return $fail('no_account');
    }

    $balances = sahtout_account_token_balances($siteDb, [$accountId]);
    $newBalance = $balances[$accountId] ?? null;

    $tokenSubject = translate('admin_tickets_comp_token_mail_subject', 'Your Account Tokens');
    $tokenBody = sprintf(
        translate(
            'admin_tickets_comp_token_mail_body',
            'We have added %s tokens to your account. Please visit the web store to redeem them. Thank you!'
        ),
        number_format($amount)
    );

    $mailResult = sahtout_admin_compensation_send_ingame_mail($charDb, $ticketId, $tokenSubject, $tokenBody, []);
    if (!$mailResult['ok']) {
        if ($mailResult['message'] === 'soap_not_configured') {
            return [
                'ok' => true,
                'message' => 'tokens_awarded_no_mail',
                'new_balance' => $newBalance,
            ];
        }

        return [
            'ok' => true,
            'message' => 'tokens_awarded_mail_failed',
            'new_balance' => $newBalance,
        ];
    }

    return [
        'ok' => true,
        'message' => 'tokens_awarded',
        'new_balance' => $newBalance,
    ];
}
