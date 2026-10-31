<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

/** In-game playerbot accounts use this username prefix (case-insensitive). */
const SAHTOUT_PLAYERBOT_USERNAME_PREFIX = 'RNDBOT';

function sahtout_is_playerbot_username(?string $username): bool
{
    if ($username === null || $username === '') {
        return false;
    }
    $prefix = SAHTOUT_PLAYERBOT_USERNAME_PREFIX;
    return strncasecmp($username, $prefix, strlen($prefix)) === 0;
}

/**
 * SQL fragment: exclude playerbot accounts. Pass a qualified column, e.g. a.username.
 */
function sahtout_sql_exclude_playerbots(string $qualifiedUsernameColumn): string
{
    return ' AND ' . $qualifiedUsernameColumn . " NOT LIKE '" . SAHTOUT_PLAYERBOT_USERNAME_PREFIX . "%'";
}

function sahtout_auth_account_is_manageable(mysqli $auth_db, int $accountId): bool
{
    $stmt = $auth_db->prepare('SELECT username FROM account WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $accountId);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    if (!$row) {
        return false;
    }
    return !sahtout_is_playerbot_username($row['username']);
}
