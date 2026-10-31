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
                   t.response, t.completed, t.needMoreHelp, t.viewed, t.assignedTo, t.closedBy
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
        $closedBy = (int) ($row['closedBy'] ?? 0);
        if ($assignedTo > 0) {
            $assignGuids[$assignedTo] = true;
        }
        if ($closedBy > 0) {
            $assignGuids[$closedBy] = true;
        }
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
            'assigned_to' => $assignedTo,
            'assigned_to_name' => '',
            'closed_by' => $closedBy,
            'closed_by_name' => '',
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
            $assignedGuid = (int) ($ticket['assigned_to'] ?? 0);
            if ($assignedGuid > 0 && isset($gmNames[$assignedGuid])) {
                $ticket['assigned_to_name'] = $gmNames[$assignedGuid];
            }
            $closedGuid = (int) ($ticket['closed_by'] ?? 0);
            if ($closedGuid > 0 && isset($gmNames[$closedGuid])) {
                $ticket['closed_by_name'] = $gmNames[$closedGuid];
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

function sahtout_account_ticket_gm_display_name(array $ticket, string $field = 'assigned_to_name'): string
{
    $name = trim((string) ($ticket[$field] ?? ''));
    if ($name === '') {
        return translate('ticket_assigned_gm_generic', 'A game master');
    }

    return $name;
}

function sahtout_account_ticket_summary_badge_key(array $ticket): string
{
    $status = (string) ($ticket['status'] ?? '');
    if ($status === 'closed' || $status === 'completed') {
        return 'closed';
    }
    if ($status === 'open' && (int) ($ticket['assigned_to'] ?? 0) > 0) {
        return 'assigned';
    }

    return 'open';
}

function sahtout_account_ticket_response_placeholder(array $ticket): string
{
    if (($ticket['status'] ?? '') !== 'open') {
        return '';
    }
    $assigned = (int) ($ticket['assigned_to'] ?? 0);
    if ($assigned <= 0) {
        return translate('ticket_waiting_gm_short', 'Waiting for a GM.');
    }

    return sprintf(
        translate('ticket_waiting_response_from', 'Waiting for response from <GM> %s.'),
        sahtout_account_ticket_gm_display_name($ticket)
    );
}

function sahtout_account_ticket_closed_notice(array $ticket): ?string
{
    $status = (string) ($ticket['status'] ?? '');
    if ($status !== 'closed' && $status !== 'completed') {
        return null;
    }

    $closedBy = (int) ($ticket['closed_by'] ?? 0);
    if ($closedBy === -1) {
        return translate('ticket_closed_by_console', 'Your ticket has been closed by staff.');
    }

    $gmName = '';
    if ($closedBy > 0) {
        $gmName = trim((string) ($ticket['closed_by_name'] ?? ''));
    }
    if ($gmName === '') {
        $gmName = sahtout_account_ticket_gm_display_name($ticket);
    }

    return sprintf(
        translate('ticket_closed_by_gm', 'Your ticket has been closed by <GM> %s.'),
        $gmName
    );
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
                       t.assignedTo, t.closedBy, t.escalated,
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
    $assignGuids = [];
    while ($row = $res->fetch_assoc()) {
        $type = (int) ($row['type'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $assignedTo = (int) ($row['assignedTo'] ?? 0);
        if ($assignedTo > 0) {
            $assignGuids[$assignedTo] = true;
        }
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
            'assigned_to' => $assignedTo,
            'assigned_to_name' => '',
            'closed_by' => (int) ($row['closedBy'] ?? 0),
            'escalated' => (int) ($row['escalated'] ?? 0),
        ];
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

/**
 * Characters on accounts with GM level 3 (for ticket assignment).
 *
 * @return list<array{guid: int, name: string, account_id: int}>
 */
function sahtout_gm_level3_characters(mysqli $authDb, mysqli $charDb, string $authDbName): array
{
    $accountIds = [];
    $authTable = '`' . str_replace('`', '', $authDbName) . '`.account_access';
    $sql = "SELECT DISTINCT id FROM {$authTable} WHERE gmlevel = 3";
    $res = $authDb->query($sql);
    if (!$res) {
        error_log('[account_gm_tickets] gm level 3 accounts query failed: ' . $authDb->error);
        return [];
    }
    while ($row = $res->fetch_assoc()) {
        $accountIds[] = (int) $row['id'];
    }
    $res->free();

    $accountIds = array_values(array_unique(array_filter($accountIds)));
    if ($accountIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = $charDb->prepare("SELECT guid, name, account FROM characters WHERE account IN ($placeholders) ORDER BY name ASC");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param(str_repeat('i', count($accountIds)), ...$accountIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'guid' => (int) $row['guid'],
            'name' => (string) $row['name'],
            'account_id' => (int) $row['account'],
        ];
    }
    if ($res) {
        $res->free();
    }
    $stmt->close();

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function sahtout_admin_gm_ticket_by_id(mysqli $charDb, int $ticketId): ?array
{
    if ($ticketId <= 0 || !sahtout_gm_ticket_table_exists($charDb)) {
        return null;
    }

    $sql = 'SELECT t.id, t.type, t.playerGuid, t.name, t.description, t.comment, t.response,
                   t.createTime, t.lastModifiedTime, t.completed, t.needMoreHelp, t.viewed,
                   t.assignedTo, t.closedBy, t.escalated, t.resolvedBy,
                   c.account AS account_id, c.online AS character_online
            FROM gm_ticket t
            LEFT JOIN characters c ON c.guid = t.playerGuid
            WHERE t.id = ? AND t.type <> 2
            LIMIT 1';

    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    if ($res) {
        $res->free();
    }
    $stmt->close();

    if (!$row) {
        return null;
    }

    $type = (int) ($row['type'] ?? 0);
    $completed = (int) ($row['completed'] ?? 0);
    $assignedTo = (int) ($row['assignedTo'] ?? 0);
    $ticket = [
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
        'assigned_to' => $assignedTo,
        'assigned_to_name' => '',
        'closed_by' => (int) ($row['closedBy'] ?? 0),
        'escalated' => (int) ($row['escalated'] ?? 0),
        'resolved_by' => (int) ($row['resolvedBy'] ?? 0),
    ];

    if ($assignedTo > 0) {
        $names = sahtout_gm_character_names($charDb, [$assignedTo]);
        if (isset($names[$assignedTo])) {
            $ticket['assigned_to_name'] = $names[$assignedTo];
        }
    }

    return $ticket;
}

function sahtout_gm_ticket_is_open(array $ticket): bool
{
    return (int) ($ticket['type'] ?? 1) === 0 && empty($ticket['completed']);
}

function sahtout_gm_ticket_is_closed_state(array $ticket): bool
{
    $status = (string) ($ticket['status'] ?? '');
    return $status === 'closed' || $status === 'completed';
}

/**
 * @param list<array{guid: int, name: string, account_id: int}> $gmCharacters
 * @return array{ok: bool, message: string, reload_ok: ?bool}
 */
function sahtout_admin_save_gm_ticket(
    mysqli $charDb,
    int $ticketId,
    string $comment,
    string $response,
    int $assignedTo,
    string $stateAction,
    array $gmCharacters
): array {
    $fail = static function (string $message): array {
        return ['ok' => false, 'message' => $message, 'reload_ok' => null];
    };

    $ticket = sahtout_admin_gm_ticket_by_id($charDb, $ticketId);
    if ($ticket === null) {
        return $fail('not_found');
    }

    $allowedGuids = [];
    foreach ($gmCharacters as $gm) {
        $allowedGuids[(int) $gm['guid']] = true;
    }

    if ($assignedTo < 0) {
        $assignedTo = 0;
    }
    if ($assignedTo > 0 && !isset($allowedGuids[$assignedTo])) {
        return $fail('invalid_assignee');
    }

    $stateAction = strtolower(trim($stateAction));
    if (!in_array($stateAction, ['save', 'close', 'reopen'], true)) {
        $stateAction = 'save';
    }

    $type = (int) $ticket['type'];
    $completed = !empty($ticket['completed']) ? 1 : 0;
    $closedBy = (int) $ticket['closed_by'];
    $resolvedBy = (int) $ticket['resolved_by'];
    $escalated = (int) $ticket['escalated'];

    if ($stateAction === 'close') {
        if (!sahtout_gm_ticket_is_open($ticket)) {
            return $fail('already_closed');
        }
        $type = 1;
        $completed = 0;
        if ($assignedTo > 0) {
            $closedBy = $assignedTo;
        } elseif ((int) $ticket['assigned_to'] > 0) {
            $closedBy = (int) $ticket['assigned_to'];
        } else {
            $closedBy = -1;
        }
        $resolvedBy = $closedBy > 0 ? $closedBy : -1;
    } elseif ($stateAction === 'reopen') {
        if (!sahtout_gm_ticket_is_closed_state($ticket)) {
            return $fail('already_open');
        }
        $type = 0;
        $completed = 0;
        $closedBy = 0;
        $resolvedBy = 0;
    }

    $prevAssigned = (int) $ticket['assigned_to'];
    if ($assignedTo !== $prevAssigned) {
        if ($assignedTo === 0) {
            if ($escalated === 1) {
                $escalated = 0;
            } elseif ($escalated === 3) {
                $escalated = 2;
            }
        } elseif ($escalated === 0) {
            $escalated = 1;
        } elseif ($escalated === 2) {
            $escalated = 3;
        }
    }

    $now = time();
    $viewed = 1;

    $sql = 'UPDATE gm_ticket SET type = ?, completed = ?, closedBy = ?, resolvedBy = ?,
            assignedTo = ?, comment = ?, response = ?, escalated = ?, viewed = ?, lastModifiedTime = ?
            WHERE id = ? AND type <> 2';

    $stmt = $charDb->prepare($sql);
    if (!$stmt) {
        error_log('[account_gm_tickets] admin update prepare failed: ' . $charDb->error);
        return $fail('update_failed');
    }

    $stmt->bind_param(
        'iiiiissiiii',
        $type,
        $completed,
        $closedBy,
        $resolvedBy,
        $assignedTo,
        $comment,
        $response,
        $escalated,
        $viewed,
        $now,
        $ticketId
    );

    if (!$stmt->execute()) {
        error_log('[account_gm_tickets] admin update execute failed: ' . $stmt->error);
        $stmt->close();
        return $fail('update_failed');
    }
    $stmt->close();

    require_once __DIR__ . '/soap_client.php';
    $reloadOk = null;
    if (sahtout_soap_is_configured()) {
        $reloadOk = sahtout_try_reload_gm_tickets();
    }

    return [
        'ok' => true,
        'message' => 'saved',
        'reload_ok' => $reloadOk,
    ];
}

function sahtout_render_ticket_status_badge_html(array $ticket): string
{
    $badgeKey = sahtout_account_ticket_summary_badge_key($ticket);
    $map = [
        'open' => ['ticket_status_open', 'Open', 'ticket-status-open'],
        'assigned' => ['ticket_status_assigned', 'Assigned', 'ticket-status-assigned'],
        'closed' => ['ticket_status_closed', 'Closed', 'ticket-status-closed'],
    ];
    $entry = $map[$badgeKey] ?? ['ticket_status_closed', 'Closed', 'ticket-status-closed'];
    $label = translate($entry[0], $entry[1]);
    $cls = $entry[2];

    return sprintf(
        '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide border %s">%s</span>',
        htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
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
