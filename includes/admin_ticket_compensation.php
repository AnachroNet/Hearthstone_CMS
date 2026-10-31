<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

require_once __DIR__ . '/account_gm_tickets.php';
require_once __DIR__ . '/soap_client.php';

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
 * @return array{ok: bool, message: string, soap_output: string}
 */
function sahtout_admin_compensation_send_gold(
    mysqli $charDb,
    int $ticketId,
    string $subject,
    string $body,
    int $goldAmount
): array {
    $fail = static function (string $code, string $soapOutput = ''): array {
        return ['ok' => false, 'message' => $code, 'soap_output' => $soapOutput];
    };

    if (!sahtout_soap_is_configured()) {
        return $fail('soap_not_configured');
    }
    if ($goldAmount <= 0 || $goldAmount > 50000) {
        return $fail('invalid_gold');
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

    $command = sprintf(
        '.send money %s %s %s %dg',
        $charName,
        sahtout_compensation_soap_quote($subject),
        sahtout_compensation_soap_quote($body),
        $goldAmount
    );

    $soap = sahtout_soap_execute_command($command);
    if (!$soap['ok']) {
        return $fail('soap_failed', (string) ($soap['error'] ?? $soap['output']));
    }

    return ['ok' => true, 'message' => 'gold_sent', 'soap_output' => $soap['output']];
}

/**
 * @return array{ok: bool, message: string, soap_output: string}
 */
function sahtout_admin_compensation_send_item(
    mysqli $charDb,
    int $ticketId,
    string $subject,
    string $body,
    int $itemEntry,
    int $itemCount
): array {
    $fail = static function (string $code, string $soapOutput = ''): array {
        return ['ok' => false, 'message' => $code, 'soap_output' => $soapOutput];
    };

    if (!sahtout_soap_is_configured()) {
        return $fail('soap_not_configured');
    }
    if ($itemEntry <= 0 || $itemCount <= 0 || $itemCount > 200) {
        return $fail('invalid_item');
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

    $command = sprintf(
        '.send items %s %s %s %d:%d',
        $charName,
        sahtout_compensation_soap_quote($subject),
        sahtout_compensation_soap_quote($body),
        $itemEntry,
        $itemCount
    );

    $soap = sahtout_soap_execute_command($command);
    if (!$soap['ok']) {
        return $fail('soap_failed', (string) ($soap['error'] ?? $soap['output']));
    }

    return ['ok' => true, 'message' => 'item_sent', 'soap_output' => $soap['output']];
}

/**
 * @return array{ok: bool, message: string, new_balance: ?int}
 */
function sahtout_admin_compensation_award_tokens(mysqli $siteDb, int $ticketId, mysqli $charDb, int $amount): array
{
    $fail = static function (string $code): array {
        return ['ok' => false, 'message' => $code, 'new_balance' => null];
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

    return [
        'ok' => true,
        'message' => 'tokens_awarded',
        'new_balance' => $balances[$accountId] ?? null,
    ];
}
