<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

require_once __DIR__ . '/playerbot_accounts.php';
require_once __DIR__ . '/soap_client.php';

/**
 * @return array{username: string, email: string, locked: int, gmlevel: int|null, site_role: string|null}|null
 */
function sahtout_admin_fetch_account_meta(mysqli $auth_db, mysqli $site_db, int $accountId, string $authDbName, string $siteDbName): ?array
{
    $stmt = $auth_db->prepare(
        'SELECT a.username, a.email, a.locked, aa.gmlevel
         FROM `' . $authDbName . '`.account a
         LEFT JOIN `' . $authDbName . '`.account_access aa ON aa.id = a.id
         WHERE a.id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $accountId);
    if (!$stmt->execute()) {
        $stmt->close();

        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    $siteRole = null;
    $stmtSite = $site_db->prepare('SELECT role FROM `' . $siteDbName . '`.user_currencies WHERE account_id = ? LIMIT 1');
    if ($stmtSite) {
        $stmtSite->bind_param('i', $accountId);
        if ($stmtSite->execute()) {
            $siteRow = $stmtSite->get_result()->fetch_assoc();
            $siteRole = $siteRow['role'] ?? null;
        }
        $stmtSite->close();
    }

    return [
        'username' => (string) $row['username'],
        'email' => (string) ($row['email'] ?? ''),
        'locked' => (int) ($row['locked'] ?? 0),
        'gmlevel' => isset($row['gmlevel']) ? (int) $row['gmlevel'] : null,
        'site_role' => $siteRole,
    ];
}

function sahtout_admin_account_is_protected(array $meta): bool
{
    if (($meta['site_role'] ?? '') === 'admin') {
        return true;
    }
    if (($meta['gmlevel'] ?? 0) > 0) {
        return true;
    }

    return false;
}

function sahtout_admin_may_suspend_account(string $actorRole, array $targetMeta): bool
{
    if (!in_array($actorRole, ['admin', 'moderator'], true)) {
        return false;
    }
    if ($actorRole !== 'admin' && sahtout_admin_account_is_protected($targetMeta)) {
        return false;
    }

    return true;
}

function sahtout_admin_may_delete_account(string $actorRole, array $targetMeta): bool
{
    if ($actorRole !== 'admin') {
        return false;
    }
    if (sahtout_admin_account_is_protected($targetMeta)) {
        return false;
    }

    return true;
}

/**
 * @return array{ok: bool, message: string}
 */
function sahtout_admin_suspend_account(mysqli $auth_db, int $accountId, string $authDbName): array
{
    $stmt = $auth_db->prepare('UPDATE `' . $authDbName . '`.account SET locked = 1 WHERE id = ?');
    if (!$stmt) {
        return ['ok' => false, 'message' => 'database_error'];
    }
    $stmt->bind_param('i', $accountId);
    $ok = $stmt->execute() && $stmt->affected_rows >= 0;
    $stmt->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'suspend_failed'];
    }

    if (sahtout_soap_is_configured()) {
        $stmtUser = $auth_db->prepare('SELECT username FROM `' . $authDbName . '`.account WHERE id = ? LIMIT 1');
        if ($stmtUser) {
            $stmtUser->bind_param('i', $accountId);
            if ($stmtUser->execute()) {
                $userRow = $stmtUser->get_result()->fetch_assoc();
                if (!empty($userRow['username'])) {
                    sahtout_soap_execute_command('kick ' . sahtout_soap_quote_command_arg((string) $userRow['username']));
                }
            }
            $stmtUser->close();
        }
    }

    return ['ok' => true, 'message' => 'suspended'];
}

/**
 * @return array{ok: bool, message: string}
 */
function sahtout_admin_unsuspend_account(mysqli $auth_db, int $accountId, string $authDbName): array
{
    $stmt = $auth_db->prepare('UPDATE `' . $authDbName . '`.account SET locked = 0 WHERE id = ?');
    if (!$stmt) {
        return ['ok' => false, 'message' => 'database_error'];
    }
    $stmt->bind_param('i', $accountId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? ['ok' => true, 'message' => 'unsuspended'] : ['ok' => false, 'message' => 'unsuspend_failed'];
}

function sahtout_admin_cleanup_site_for_deleted_account(mysqli $site_db, string $siteDbName, string $username, string $email): void
{
    $upper = strtoupper($username);
    $stmt = $site_db->prepare('DELETE FROM `' . $siteDbName . '`.pending_accounts WHERE username = ?');
    if ($stmt) {
        $stmt->bind_param('s', $upper);
        $stmt->execute();
        $stmt->close();
    }
    if ($email !== '') {
        $stmt = $site_db->prepare('DELETE FROM `' . $siteDbName . '`.password_resets WHERE email = ?');
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/**
 * Deletes the auth account and all characters (via worldserver SOAP when configured).
 *
 * @return array{ok: bool, message: string}
 */
function sahtout_admin_delete_account(
    mysqli $auth_db,
    mysqli $site_db,
    int $accountId,
    string $authDbName,
    string $siteDbName
): array {
    if (!sahtout_soap_is_configured()) {
        return ['ok' => false, 'message' => 'soap_required'];
    }

    $meta = sahtout_admin_fetch_account_meta($auth_db, $site_db, $accountId, $authDbName, $siteDbName);
    if (!$meta) {
        return ['ok' => false, 'message' => 'account_not_found'];
    }

    $username = $meta['username'];
    $email = $meta['email'];

    $soap = sahtout_soap_execute_command('account delete ' . sahtout_soap_quote_command_arg($username));
    if (!$soap['ok']) {
        error_log('[account_admin] account delete SOAP failed for ' . $username . ': ' . ($soap['error'] ?? 'unknown'));

        return ['ok' => false, 'message' => 'delete_soap_failed'];
    }

    $check = $auth_db->prepare('SELECT id FROM `' . $authDbName . '`.account WHERE id = ? LIMIT 1');
    if ($check) {
        $check->bind_param('i', $accountId);
        $check->execute();
        $still = $check->get_result()->num_rows > 0;
        $check->close();
        if ($still) {
            return ['ok' => false, 'message' => 'delete_incomplete'];
        }
    }

    sahtout_admin_cleanup_site_for_deleted_account($site_db, $siteDbName, $username, $email);

    return ['ok' => true, 'message' => 'deleted'];
}
