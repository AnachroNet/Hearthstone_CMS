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

    $stmt = $siteDb->prepare(
        'SELECT id, account_id, account_username, subject, message, status,
                responder_username, response_text, created_at, responded_at, updated_at
         FROM user_inquiries
         WHERE account_id = ?
         ORDER BY created_at DESC
         LIMIT 100'
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

    $listSql = "SELECT id, account_id, account_username, subject, message, status,
                       responder_username, response_text, created_at, responded_at, updated_at
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
    $stmt = $siteDb->prepare(
        'SELECT id, account_id, account_username, subject, message, status,
                responder_username, response_text, created_at, responded_at, updated_at
         FROM user_inquiries WHERE id = ? LIMIT 1'
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

    return $row ?: null;
}

function sahtout_admin_save_user_inquiry(
    mysqli $siteDb,
    int $inquiryId,
    string $responseText,
    string $responderUsername,
    string $stateAction
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

    return $ok ? ['ok' => true] : ['ok' => false, 'message' => 'update_failed'];
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
