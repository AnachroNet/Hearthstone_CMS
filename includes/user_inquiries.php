<?php
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access to this file is not allowed.');
}

function sahtout_user_inquiries_table_exists(?mysqli $siteDb): bool
{
    if (!$siteDb instanceof mysqli || $siteDb->connect_error) {
        return false;
    }
    $result = $siteDb->query("SHOW TABLES LIKE 'user_inquiries'");
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
}

function sahtout_user_inquiries_tokens_column_exists(mysqli $siteDb): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    if (!sahtout_user_inquiries_table_exists($siteDb)) {
        $exists = false;

        return false;
    }
    $res = $siteDb->query("SHOW COLUMNS FROM user_inquiries LIKE 'tokens_awarded'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_user_inquiry_select_sql(mysqli $siteDb): string
{
    $tokens = sahtout_user_inquiries_tokens_column_exists($siteDb) ? 'tokens_awarded,' : '';

    return 'id, account_id, account_username, subject, message, status,
                responder_username, response_text, ' . $tokens . ' created_at, responded_at, updated_at';
}

function sahtout_format_inquiry_time($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
    if ($ts <= 0) {
        return '—';
    }

    return date('Y-m-d H:i:s', $ts);
}

function sahtout_user_inquiry_create(
    mysqli $siteDb,
    int $accountId,
    string $accountUsername,
    string $subject,
    string $message
): array {
    if (!sahtout_user_inquiries_table_exists($siteDb)) {
        return ['ok' => false, 'message' => 'table_missing'];
    }

    $subject = trim($subject);
    $message = trim($message);
    $accountUsername = trim($accountUsername);

    if ($accountId <= 0 || $accountUsername === '') {
        return ['ok' => false, 'message' => 'invalid_account'];
    }
    if ($subject === '' || mb_strlen($subject) > 255) {
        return ['ok' => false, 'message' => 'invalid_subject'];
    }
    if ($message === '' || mb_strlen($message) > 20000) {
        return ['ok' => false, 'message' => 'invalid_message'];
    }

    $stmt = $siteDb->prepare(
        'INSERT INTO user_inquiries (account_id, account_username, subject, message, status)
         VALUES (?, ?, ?, ?, \'pending\')'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'db_error'];
    }
    $stmt->bind_param('isss', $accountId, $accountUsername, $subject, $message);
    $ok = $stmt->execute();
    $insertId = $ok ? (int) $stmt->insert_id : 0;
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'db_error'];
    }

    return ['ok' => true, 'id' => $insertId];
}

function sahtout_account_user_inquiries(mysqli $siteDb, int $accountId): array
{
    $empty = ['available' => false, 'inquiries' => [], 'pending_count' => 0];

    if ($accountId <= 0 || !sahtout_user_inquiries_table_exists($siteDb)) {
        return $empty;
    }

    $select = sahtout_user_inquiry_select_sql($siteDb);
    $stmt = $siteDb->prepare(
        "SELECT {$select}
         FROM user_inquiries
         WHERE account_id = ?
         ORDER BY created_at DESC
         LIMIT 100"
    );
    if (!$stmt) {
        return $empty;
    }
    $stmt->bind_param('i', $accountId);
    if (!$stmt->execute()) {
        $stmt->close();

        return $empty;
    }
    $result = $stmt->get_result();
    $rows = [];
    $pending = 0;
    while ($row = $result->fetch_assoc()) {
        if (($row['status'] ?? '') === 'pending') {
            $pending++;
        }
        if (!isset($row['tokens_awarded'])) {
            $row['tokens_awarded'] = 0;
        }
        $rows[] = $row;
    }
    $stmt->close();

    return [
        'available' => true,
        'inquiries' => $rows,
        'pending_count' => $pending,
    ];
}

function sahtout_admin_user_inquiries(
    mysqli $siteDb,
    string $statusFilter,
    string $search,
    int $page,
    int $perPage
): array {
    $empty = ['available' => false, 'inquiries' => [], 'total' => 0];

    if (!sahtout_user_inquiries_table_exists($siteDb)) {
        return $empty;
    }

    $page = max(1, $page);
    $perPage = max(1, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $where = [];
    $types = '';
    $params = [];

    if ($statusFilter === 'pending') {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = 'pending';
    } elseif ($statusFilter === 'answered') {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = 'answered';
    } elseif ($statusFilter === 'closed') {
        $where[] = 'status = ?';
        $types .= 's';
        $params[] = 'closed';
    }

    $search = trim($search);
    if ($search !== '') {
        if (ctype_digit($search)) {
            $where[] = 'id = ?';
            $types .= 'i';
            $params[] = (int) $search;
        } else {
            $like = '%' . $search . '%';
            $where[] = '(account_username LIKE ? OR subject LIKE ?)';
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }
    }

    $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) AS c FROM user_inquiries {$whereSql}";
    $stmt = $siteDb->prepare($countSql);
    if (!$stmt) {
        return $empty;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $stmt->close();

        return $empty;
    }
    $countRow = $stmt->get_result()->fetch_assoc();
    $total = (int) ($countRow['c'] ?? 0);
    $stmt->close();

    $select = sahtout_user_inquiry_select_sql($siteDb);
    $listSql = "SELECT {$select}
                FROM user_inquiries {$whereSql}
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?";
    $stmt = $siteDb->prepare($listSql);
    if (!$stmt) {
        return ['available' => true, 'inquiries' => [], 'total' => $total];
    }

    $listTypes = $types . 'ii';
    $listParams = $params;
    $listParams[] = $perPage;
    $listParams[] = $offset;
    $stmt->bind_param($listTypes, ...$listParams);
    if (!$stmt->execute()) {
        $stmt->close();

        return ['available' => true, 'inquiries' => [], 'total' => $total];
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        if (!isset($row['tokens_awarded'])) {
            $row['tokens_awarded'] = 0;
        }
    }
    unset($row);

    return [
        'available' => true,
        'inquiries' => $rows,
        'total' => $total,
    ];
}

function sahtout_admin_user_inquiry_by_id(mysqli $siteDb, int $id): ?array
{
    if ($id <= 0 || !sahtout_user_inquiries_table_exists($siteDb)) {
        return null;
    }
    $select = sahtout_user_inquiry_select_sql($siteDb);
    $stmt = $siteDb->prepare(
        "SELECT {$select}
         FROM user_inquiries WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();

        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && !isset($row['tokens_awarded'])) {
        $row['tokens_awarded'] = 0;
    }

    return $row ?: null;
}

function sahtout_admin_save_user_inquiry(
    mysqli $siteDb,
    int $inquiryId,
    string $responseText,
    string $responderUsername,
    string $stateAction,
    int $tokensToAdd = 0
): array {
    if (!sahtout_user_inquiries_table_exists($siteDb)) {
        return ['ok' => false, 'message' => 'table_missing'];
    }

    $inquiry = sahtout_admin_user_inquiry_by_id($siteDb, $inquiryId);
    if ($inquiry === null) {
        return ['ok' => false, 'message' => 'not_found'];
    }

    $responseText = trim($responseText);
    $responderUsername = trim($responderUsername);

    if ($stateAction === 'close') {
        $stmt = $siteDb->prepare(
            "UPDATE user_inquiries SET status = 'closed', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'db_error'];
        }
        $stmt->bind_param('i', $inquiryId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? ['ok' => true] : ['ok' => false, 'message' => 'update_failed'];
    }

    if ($stateAction === 'reopen') {
        $stmt = $siteDb->prepare(
            "UPDATE user_inquiries SET status = 'pending', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'db_error'];
        }
        $stmt->bind_param('i', $inquiryId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok ? ['ok' => true] : ['ok' => false, 'message' => 'update_failed'];
    }

    if ($responseText === '') {
        return ['ok' => false, 'message' => 'empty_response'];
    }
    if ($responderUsername === '') {
        return ['ok' => false, 'message' => 'invalid_responder'];
    }

    $stmt = $siteDb->prepare(
        "UPDATE user_inquiries
         SET response_text = ?, responder_username = ?, status = 'answered',
             responded_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'db_error'];
    }
    $stmt->bind_param('ssi', $responseText, $responderUsername, $inquiryId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'update_failed'];
    }

    if ($tokensToAdd > 0) {
        $tokenResult = sahtout_admin_inquiry_award_tokens($siteDb, $inquiryId, $tokensToAdd);
        if (!$tokenResult['ok']) {
            return $tokenResult;
        }

        return [
            'ok' => true,
            'tokens_added' => $tokensToAdd,
            'new_balance' => $tokenResult['new_balance'] ?? null,
        ];
    }

    return ['ok' => true];
}

/**
 * @return array{ok: bool, message?: string, new_balance?: ?int, tokens_added?: int}
 */
function sahtout_admin_inquiry_award_tokens(mysqli $siteDb, int $inquiryId, int $amount): array
{
    if ($amount <= 0 || $amount > 9999999) {
        return ['ok' => false, 'message' => 'invalid_tokens'];
    }
    if (!sahtout_user_inquiries_tokens_column_exists($siteDb)) {
        return ['ok' => false, 'message' => 'tokens_column_missing'];
    }

    $inquiry = sahtout_admin_user_inquiry_by_id($siteDb, $inquiryId);
    if ($inquiry === null) {
        return ['ok' => false, 'message' => 'not_found'];
    }

    $accountId = (int) ($inquiry['account_id'] ?? 0);
    if ($accountId <= 0) {
        return ['ok' => false, 'message' => 'invalid_account'];
    }

    $siteDb->begin_transaction();
    try {
        $stmt = $siteDb->prepare(
            'UPDATE user_currencies SET tokens = LEAST(tokens + ?, 9999999) WHERE account_id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('currency_prepare');
        }
        $stmt->bind_param('ii', $amount, $accountId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            throw new RuntimeException('no_account');
        }

        $stmt = $siteDb->prepare(
            'UPDATE user_inquiries SET tokens_awarded = tokens_awarded + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('inquiry_prepare');
        }
        $stmt->bind_param('ii', $amount, $inquiryId);
        $stmt->execute();
        $stmt->close();

        $siteDb->commit();
    } catch (Throwable $e) {
        $siteDb->rollback();
        $code = $e->getMessage();
        if ($code === 'no_account') {
            return ['ok' => false, 'message' => 'no_account'];
        }

        return ['ok' => false, 'message' => 'token_award_failed'];
    }

    $stmt = $siteDb->prepare('SELECT tokens FROM user_currencies WHERE account_id = ? LIMIT 1');
    $newBalance = null;
    if ($stmt) {
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $newBalance = (int) ($row['tokens'] ?? 0);
        }
    }

    return ['ok' => true, 'tokens_added' => $amount, 'new_balance' => $newBalance];
}

function sahtout_inquiry_status_label_key(string $status): string
{
    return match ($status) {
        'pending' => 'inquiry_status_pending',
        'answered' => 'inquiry_status_answered',
        'closed' => 'inquiry_status_closed',
        default => 'inquiry_status_closed',
    };
}

function sahtout_inquiry_status_css_class(string $status): string
{
    return match ($status) {
        'pending' => 'ticket-status-open',
        'answered' => 'ticket-status-assigned',
        default => 'ticket-status-closed',
    };
}
