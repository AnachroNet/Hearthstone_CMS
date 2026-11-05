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
