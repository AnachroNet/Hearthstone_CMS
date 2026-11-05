<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/**
 * Create or refresh the site-side row for a game account (tokens, avatar, role live here).
 * Does not require triggers or foreign keys on acore_auth.
 */
function sahtout_site_account_ensure(
    mysqli $siteDb,
    int $accountId,
    string $username,
    string $email
): bool {
    if ($accountId <= 0) {
        return false;
    }

    $username = trim($username);
    $email = trim($email);

    $stmt = $siteDb->prepare(
        'INSERT INTO user_currencies (account_id, username, email, tokens, avatar, role)
         VALUES (?, ?, ?, 0, NULL, \'player\')
         ON DUPLICATE KEY UPDATE username = VALUES(username), email = VALUES(email)'
    );
    if (!$stmt) {
        error_log('site_account ensure: prepare failed: ' . $siteDb->error);

        return false;
    }
    $stmt->bind_param('iss', $accountId, $username, $email);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('site_account ensure: execute failed for account ' . $accountId . ': ' . $stmt->error);
    }
    $stmt->close();

    return $ok;
}

/** Load username/email from auth and ensure the site row exists. */
function sahtout_site_account_ensure_from_auth(
    mysqli $siteDb,
    mysqli $authDb,
    int $accountId
): bool {
    if ($accountId <= 0 || $authDb->connect_error) {
        return false;
    }

    $stmt = $authDb->prepare('SELECT username, email FROM account WHERE id = ? LIMIT 1');
    if (!$stmt) {
        error_log('site_account ensure_from_auth: prepare failed: ' . $authDb->error);

        return false;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return false;
    }

    return sahtout_site_account_ensure(
        $siteDb,
        $accountId,
        (string) ($row['username'] ?? ''),
        (string) ($row['email'] ?? '')
    );
}

/** Update cached username/email on the site row (row must exist or use ensure). */
function sahtout_site_account_sync_profile(
    mysqli $siteDb,
    int $accountId,
    string $username,
    string $email
): bool {
    if ($accountId <= 0) {
        return false;
    }

    $stmt = $siteDb->prepare(
        'UPDATE user_currencies SET username = ?, email = ? WHERE account_id = ?'
    );
    if (!$stmt) {
        return false;
    }
    $username = trim($username);
    $email = trim($email);
    $stmt->bind_param('ssi', $username, $email, $accountId);
    $ok = $stmt->execute();
    if ($ok && $stmt->affected_rows === 0) {
        $stmt->close();

        return sahtout_site_account_ensure($siteDb, $accountId, $username, $email);
    }
    $stmt->close();

    return $ok;
}
