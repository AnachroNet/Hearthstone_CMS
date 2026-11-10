<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** Log an account dashboard activity row (best-effort; never throws). */
function sahtout_log_website_activity(
    mysqli $siteDb,
    int $accountId,
    string $action,
    ?string $details = null,
    ?string $characterName = null
): void {
    if ($accountId <= 0 || trim($action) === '') {
        return;
    }

    $action = mb_substr(trim($action), 0, 120);
    if ($details !== null) {
        $details = mb_substr(trim($details), 0, 500);
        if ($details === '') {
            $details = null;
        }
    }
    if ($characterName !== null) {
        $characterName = mb_substr(trim($characterName), 0, 12);
        if ($characterName === '') {
            $characterName = null;
        }
    }

    if ($details !== null && $characterName !== null) {
        $stmt = $siteDb->prepare(
            'INSERT INTO website_activity_log (account_id, character_name, action, timestamp, details)
             VALUES (?, ?, ?, UNIX_TIMESTAMP(), ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isss', $accountId, $characterName, $action, $details);
    } elseif ($details !== null) {
        $stmt = $siteDb->prepare(
            'INSERT INTO website_activity_log (account_id, character_name, action, timestamp, details)
             VALUES (?, NULL, ?, UNIX_TIMESTAMP(), ?)'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iss', $accountId, $action, $details);
    } elseif ($characterName !== null) {
        $stmt = $siteDb->prepare(
            'INSERT INTO website_activity_log (account_id, character_name, action, timestamp)
             VALUES (?, ?, ?, UNIX_TIMESTAMP())'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('iss', $accountId, $characterName, $action);
    } else {
        $stmt = $siteDb->prepare(
            'INSERT INTO website_activity_log (account_id, character_name, action, timestamp)
             VALUES (?, NULL, ?, UNIX_TIMESTAMP())'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('is', $accountId, $action);
    }

    $stmt->execute();
    $stmt->close();
}

/**
 * @return list<array{action:string,timestamp:int,details:?string,character_name:?string}>
 */
function sahtout_fetch_website_activity_log(mysqli $siteDb, int $accountId, int $limit = 25): array
{
    if ($accountId <= 0) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $stmt = $siteDb->prepare(
        'SELECT action, timestamp, details, character_name
         FROM website_activity_log
         WHERE account_id = ?
         ORDER BY timestamp DESC
         LIMIT ?'
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $accountId, $limit);
    if (!$stmt->execute()) {
        $stmt->close();

        return [];
    }

    $result = $stmt->get_result();
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'action' => (string) ($row['action'] ?? ''),
                'timestamp' => (int) ($row['timestamp'] ?? 0),
                'details' => isset($row['details']) ? (string) $row['details'] : null,
                'character_name' => isset($row['character_name']) ? (string) $row['character_name'] : null,
            ];
        }
        $result->free();
    }
    $stmt->close();

    return $rows;
}
