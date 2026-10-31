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
                   t.comment, t.response, t.completed, t.needMoreHelp, t.viewed, t.assignedTo
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
    $assignGuids = [];
    while ($row = $res->fetch_assoc()) {
        $type = (int) ($row['type'] ?? 0);
        $status = sahtout_gm_ticket_status_key($type, (int) ($row['completed'] ?? 0));
        $assignedTo = (int) ($row['assignedTo'] ?? 0);
        if ($assignedTo > 0) {
            $assignGuids[$assignedTo] = true;
        }
        $ticket = [
            'id' => (int) $row['id'],
            'type' => $type,
            'status' => $status,
            'player_guid' => (int) $row['playerGuid'],
            'character_name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'comment' => (string) ($row['comment'] ?? ''),
            'response' => (string) ($row['response'] ?? ''),
            'create_time' => (int) ($row['createTime'] ?? 0),
            'last_modified_time' => (int) ($row['lastModifiedTime'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0) === 1,
            'need_more_help' => (int) ($row['needMoreHelp'] ?? 0) === 1,
            'viewed' => (int) ($row['viewed'] ?? 0) === 1,
            'assigned_to' => $assignedTo,
            'assigned_to_name' => '',
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

    if ($assignGuids !== []) {
        $gmNames = sahtout_gm_character_names($charDb, array_keys($assignGuids));
        foreach ($out['tickets'] as &$ticket) {
            $gid = (int) ($ticket['assigned_to'] ?? 0);
            if ($gid > 0 && isset($gmNames[$gid])) {
                $ticket['assigned_to_name'] = $gmNames[$gid];
            }
        }
        unset($ticket);
    }

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

function sahtout_server_timezone(): DateTimeZone
{
    global $site_timezone;
    static $resolved = null;
    if ($resolved instanceof DateTimeZone) {
        return $resolved;
    }
    $id = (isset($site_timezone) && is_string($site_timezone) && $site_timezone !== '')
        ? $site_timezone
        : (date_default_timezone_get() ?: 'UTC');
    try {
        $resolved = new DateTimeZone($id);
    } catch (Exception $e) {
        error_log('[account_gm_tickets] invalid timezone ' . $id . ': ' . $e->getMessage());
        $resolved = new DateTimeZone('UTC');
    }
    return $resolved;
}

function sahtout_format_ticket_time(int $unix): string
{
    if ($unix <= 0) {
        return '—';
    }
    $dt = (new DateTimeImmutable('@' . $unix))->setTimezone(sahtout_server_timezone());
    return $dt->format('M j, Y g:i A T');
}

/**
 * @param list<int> $guids
 * @return array<int, string>
 */
function sahtout_gm_character_names(mysqli $charDb, array $guids): array
{
    $guids = array_values(array_unique(array_filter(array_map('intval', $guids))));
    if ($guids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($guids), '?'));
    $stmt = $charDb->prepare("SELECT guid, name FROM characters WHERE guid IN ($placeholders)");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param(str_repeat('i', count($guids)), ...$guids);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[(int) $row['guid']] = (string) $row['name'];
    }
    if ($res) {
        $res->free();
    }
    $stmt->close();
    return $map;
}

function sahtout_account_ticket_status_summary(array $ticket): string
{
    if (($ticket['status'] ?? '') === 'open') {
        $assigned = (int) ($ticket['assigned_to'] ?? 0);
        if ($assigned <= 0) {
            return translate('ticket_waiting_gm', 'Waiting for a GM...');
        }
        $gmName = trim((string) ($ticket['assigned_to_name'] ?? ''));
        if ($gmName === '') {
            $gmName = translate('ticket_assigned_gm_generic', 'A game master');
        }
        return sprintf(
            translate('ticket_gm_reviewing', '<GM> %s is reviewing your ticket.'),
            $gmName
        );
    }
    if (($ticket['status'] ?? '') === 'completed') {
        return translate('ticket_summary_completed', 'Marked complete by staff.');
    }
    return translate('ticket_summary_closed', 'This ticket is closed.');
}

function sahtout_gm_tickets_open_count(mysqli $charDb): int
{
    if (!sahtout_gm_ticket_table_exists($charDb)) {
        return 0;
    }
    $res = $charDb->query(
        'SELECT COUNT(*) AS c FROM gm_ticket WHERE type = 0 AND completed = 0'
    );
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return (int) ($row['c'] ?? 0);
}

/**
 * @return array{available: bool, tickets: list<array<string, mixed>>, total: int}
 */
function sahtout_admin_gm_tickets(
    mysqli $charDb,
    string $statusFilter = 'open',
    string $search = '',
    int $page = 1,
    int $perPage = 15
): array {
    $out = ['available' => false, 'tickets' => [], 'total' => 0];
    if (!sahtout_gm_ticket_table_exists($charDb)) {
        return $out;
    }

    $page = max(1, $page);
    $perPage = max(5, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $where = ['t.type <> 2'];
    $types = '';
    $params = [];

    if ($statusFilter === 'open') {
        $where[] = 't.type = 0 AND t.completed = 0';
    } elseif ($statusFilter === 'closed') {
        $where[] = '(t.type = 1 OR t.completed = 1)';
    }

    $search = trim($search);
    if ($search !== '') {
        if (ctype_digit($search)) {
            $where[] = 't.id = ?';
            $types .= 'i';
            $params[] = (int) $search;
        } else {
            $like = '%' . $search . '%';
            $where[] = '(t.name LIKE ? OR t.description LIKE ?)';
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) AS c FROM gm_ticket t WHERE {$whereSql}";
    $stmt = $charDb->prepare($countSql);
    if (!$stmt) {
        error_log('[account_gm_tickets] admin count prepare failed: ' . $charDb->error);
        return $out;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $countRes = $stmt->get_result();
    $out['total'] = (int) (($countRes->fetch_assoc()['c'] ?? 0));
    if ($countRes) {
        $countRes->free();
    }
    $stmt->close();

    $listSql = "SELECT t.id, t.type, t.playerGuid, t.name, t.description, t.comment, t.response,
                       t.createTime, t.lastModifiedTime, t.completed, t.needMoreHelp, t.viewed,
                       c.account AS account_id, c.online AS character_online
                FROM gm_ticket t
                LEFT JOIN characters c ON c.guid = t.playerGuid
                WHERE {$whereSql}
                ORDER BY t.lastModifiedTime DESC, t.id DESC
                LIMIT ? OFFSET ?";

    $stmt = $charDb->prepare($listSql);
    if (!$stmt) {
        error_log('[account_gm_tickets] admin list prepare failed: ' . $charDb->error);
        return $out;
    }

    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $type = (int) ($row['type'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $out['tickets'][] = [
            'id' => (int) $row['id'],
            'type' => $type,
            'status' => sahtout_gm_ticket_status_key($type, $completed),
            'player_guid' => (int) ($row['playerGuid'] ?? 0),
            'character_name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'comment' => (string) ($row['comment'] ?? ''),
            'response' => (string) ($row['response'] ?? ''),
            'create_time' => (int) ($row['createTime'] ?? 0),
            'last_modified_time' => (int) ($row['lastModifiedTime'] ?? 0),
            'completed' => $completed === 1,
            'need_more_help' => (int) ($row['needMoreHelp'] ?? 0) === 1,
            'viewed' => (int) ($row['viewed'] ?? 0) === 1,
            'account_id' => isset($row['account_id']) ? (int) $row['account_id'] : 0,
            'character_online' => (int) ($row['character_online'] ?? 0) === 1,
        ];
    }
    if ($res) {
        $res->free();
    }
    $stmt->close();

    $out['available'] = true;
    return $out;
}

/**
 * @param list<int> $accountIds
 * @return array<int, string>
 */
function sahtout_gm_ticket_account_names(mysqli $authDb, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
    if ($accountIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = $authDb->prepare("SELECT id, username FROM account WHERE id IN ($placeholders)");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[(int) $row['id']] = (string) $row['username'];
    }
    if ($res) {
        $res->free();
    }
    $stmt->close();
    return $map;
}
