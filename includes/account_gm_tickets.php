<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

/**
 * Read-only GM tickets for a website account (AzerothCore gm_ticket + characters join).
 * Tickets for deleted characters (type 2 / no matching character row) are excluded.
 */

function sahtout_gm_ticket_table_exists(mysqli $charDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $exists = false;
    $res = $charDb->query("SHOW TABLES LIKE 'gm_ticket'");
    if ($res && $res->num_rows > 0) {
        $exists = true;
    }
    if ($res) {
        $res->free();
    }
    return $exists;
}

/**
 * @return array{available: bool, tickets: list<array<string, mixed>>, open_count: int, message: ?string}
 */
function sahtout_account_gm_tickets(mysqli $charDb, int $accountId): array
{
    $out = [
        'available' => false,
        'tickets' => [],
        'open_count' => 0,
        'message' => null,
    ];

    if ($accountId <= 0) {
        $out['message'] = 'invalid_account';
        return $out;
    }

    if (!sahtout_gm_ticket_table_exists($charDb)) {
        $out['message'] = 'missing_table';
        return $out;
    }

    $sql = 'SELECT t.id, t.type, t.playerGuid, t.name, t.description, t.createTime, t.lastModifiedTime,
                   t.response, t.completed, t.needMoreHelp, t.viewed
            FROM gm_ticket t
            INNER JOIN characters c ON c.guid = t.playerGuid AND c.account = ?
            WHERE t.type <> 2
            ORDER BY t.lastModifiedTime DESC, t.id DESC';

    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        error_log('[account_gm_tickets] prepare failed: ' . $charDb->error);
        $out['message'] = 'query_failed';
        return $out;
    }

    $stmt->bind_param('i', $accountId);
    if (!$stmt->execute()) {
        error_log('[account_gm_tickets] execute failed: ' . $stmt->error);
        $stmt->close();
        $out['message'] = 'query_failed';
        return $out;
    }

    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $type = (int) ($row['type'] ?? 0);
        $status = sahtout_gm_ticket_status_key($type, (int) ($row['completed'] ?? 0));
        $ticket = [
            'id' => (int) $row['id'],
            'type' => $type,
            'status' => $status,
            'player_guid' => (int) $row['playerGuid'],
            'character_name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'response' => (string) ($row['response'] ?? ''),
            'create_time' => (int) ($row['createTime'] ?? 0),
            'last_modified_time' => (int) ($row['lastModifiedTime'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0) === 1,
            'need_more_help' => (int) ($row['needMoreHelp'] ?? 0) === 1,
            'viewed' => (int) ($row['viewed'] ?? 0) === 1,
        ];
        $out['tickets'][] = $ticket;
        if ($status === 'open') {
            $out['open_count']++;
        }
    }
    if ($res) {
        $res->free();
    }
    $stmt->close();

    $out['available'] = true;
    return $out;
}

function sahtout_gm_ticket_status_key(int $type, int $completed): string
{
    if ($type === 0 && $completed === 0) {
        return 'open';
    }
    if ($type === 0 && $completed === 1) {
        return 'completed';
    }
    if ($type === 1) {
        return 'closed';
    }
    return 'unknown';
}

function sahtout_format_ticket_time(int $unix): string
{
    if ($unix <= 0) {
        return '—';
    }
    return gmdate('Y-m-d H:i:s', $unix) . ' UTC';
}
