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

function sahtout_admin_auth_account_exists(mysqli $auth_db, string $authDbName, int $accountId): bool
{
    $stmt = $auth_db->prepare('SELECT id FROM `' . $authDbName . '`.account WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

function sahtout_admin_soap_plaintext_detail(string $soapOutput): string
{
    $text = sahtout_soap_extract_command_message($soapOutput, true);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

    if ($text === '') {
        return '';
    }
    if (strlen($text) > 240) {
        return substr($text, 0, 237) . '...';
    }

    return $text;
}

/** @return list<string> */
function sahtout_admin_character_names_for_account(mysqli $char_db, string $charDbName, int $accountId): array
{
    $stmt = $char_db->prepare('SELECT name FROM `' . $charDbName . '`.characters WHERE account = ? ORDER BY name');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $accountId);
    if (!$stmt->execute()) {
        $stmt->close();

        return [];
    }
    $names = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    $stmt->close();

    return $names;
}

function sahtout_admin_kick_online_characters(mysqli $char_db, string $charDbName, int $accountId): void
{
    if (!sahtout_soap_is_configured()) {
        return;
    }

    $stmt = $char_db->prepare(
        'SELECT name FROM `' . $charDbName . '`.characters WHERE account = ? AND online = 1'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $accountId);
    if (!$stmt->execute()) {
        $stmt->close();

        return;
    }
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            sahtout_soap_execute_command('.kick ' . sahtout_soap_character_name_for_command($name));
        }
    }
    $stmt->close();
}

/**
 * @return array{ok: bool, message: string, detail?: string}
 */
function sahtout_admin_erase_characters_via_soap(mysqli $char_db, string $charDbName, int $accountId): array
{
    $names = sahtout_admin_character_names_for_account($char_db, $charDbName, $accountId);
    foreach ($names as $name) {
        $soap = sahtout_soap_execute_command('.character erase ' . sahtout_soap_character_name_for_command($name));
        if (!$soap['ok']) {
            return [
                'ok' => false,
                'message' => 'character_erase_failed',
                'detail' => sahtout_admin_soap_plaintext_detail($soap['output']) ?: ($soap['error'] ?? ''),
            ];
        }
    }

    $stmt = $char_db->prepare('SELECT COUNT(*) AS cnt FROM `' . $charDbName . '`.characters WHERE account = ?');
    if ($stmt) {
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        $cnt = (int) ($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        if ($cnt > 0) {
            return ['ok' => false, 'message' => 'characters_remain', 'detail' => (string) $cnt];
        }
    }

    return ['ok' => true, 'message' => 'characters_erased'];
}

function sahtout_admin_delete_auth_account_rows(
    mysqli $auth_db,
    mysqli $char_db,
    int $accountId,
    string $authDbName,
    string $charDbName
): bool {
    $char_db->query('DELETE FROM `' . $charDbName . '`.account_tutorial WHERE accountId = ' . (int) $accountId);
    $char_db->query('DELETE FROM `' . $charDbName . '`.account_data WHERE accountId = ' . (int) $accountId);
    $char_db->query(
        'DELETE cb FROM `' . $charDbName . '`.character_banned cb
         INNER JOIN `' . $charDbName . '`.characters c ON c.guid = cb.guid
         WHERE c.account = ' . (int) $accountId
    );

    $auth_db->begin_transaction();
    $ok = true;
    $id = (int) $accountId;
    $ok = $ok && $auth_db->query('DELETE FROM `' . $authDbName . '`.account_access WHERE id = ' . $id);
    $ok = $ok && $auth_db->query('DELETE FROM `' . $authDbName . '`.account_banned WHERE id = ' . $id);
    $ok = $ok && $auth_db->query('DELETE FROM `' . $authDbName . '`.realmcharacters WHERE acctid = ' . $id);
    $ok = $ok && $auth_db->query('DELETE FROM `' . $authDbName . '`.account_muted WHERE guid = ' . $id);
    $ok = $ok && $auth_db->query('DELETE FROM `' . $authDbName . '`.account WHERE id = ' . $id);

    if ($ok) {
        $auth_db->commit();
    } else {
        $auth_db->rollback();
    }

    return $ok && !sahtout_admin_auth_account_exists($auth_db, $authDbName, $accountId);
}

/**
 * @return array{ok: bool, message: string}
 */
function sahtout_admin_suspend_account(
    mysqli $auth_db,
    mysqli $char_db,
    int $accountId,
    string $authDbName,
    string $charDbName
): array {
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

    sahtout_admin_kick_online_characters($char_db, $charDbName, $accountId);

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
 * Deletes the auth account and all characters (worldserver SOAP + auth DB cleanup).
 *
 * @return array{ok: bool, message: string, detail?: string}
 */
function sahtout_admin_delete_account(
    mysqli $auth_db,
    mysqli $char_db,
    mysqli $site_db,
    int $accountId,
    string $authDbName,
    string $charDbName,
    string $siteDbName
): array {
    if (!sahtout_soap_is_configured()) {
        return ['ok' => false, 'message' => 'soap_required'];
    }

    $meta = sahtout_admin_fetch_account_meta($auth_db, $site_db, $accountId, $authDbName, $siteDbName);
    if (!$meta) {
        return ['ok' => false, 'message' => 'account_not_found'];
    }

    $username = strtoupper(trim($meta['username']));
    $email = $meta['email'];
    $lastDetail = '';

    sahtout_admin_kick_online_characters($char_db, $charDbName, $accountId);

    $accountLiteral = sahtout_soap_account_name_literal($username);
    if ($accountLiteral === null) {
        return ['ok' => false, 'message' => 'invalid_username', 'detail' => $username];
    }

    $soap = sahtout_soap_execute_command('.account delete ' . $accountLiteral);
    if (!$soap['ok']) {
        $lastDetail = sahtout_admin_soap_plaintext_detail($soap['output']) ?: (string) ($soap['error'] ?? '');
        error_log('[account_admin] .account delete SOAP failed for ' . $accountLiteral . ': ' . $lastDetail);
    }

    if (sahtout_admin_auth_account_exists($auth_db, $authDbName, $accountId)) {
        $erase = sahtout_admin_erase_characters_via_soap($char_db, $charDbName, $accountId);
        if (!$erase['ok']) {
            return [
                'ok' => false,
                'message' => 'delete_soap_failed',
                'detail' => $erase['detail'] ?? $lastDetail,
            ];
        }

        if (!sahtout_admin_delete_auth_account_rows($auth_db, $char_db, $accountId, $authDbName, $charDbName)) {
            return [
                'ok' => false,
                'message' => 'delete_incomplete',
                'detail' => $lastDetail,
            ];
        }
    }

    if (sahtout_admin_auth_account_exists($auth_db, $authDbName, $accountId)) {
        return [
            'ok' => false,
            'message' => 'delete_incomplete',
            'detail' => $lastDetail,
        ];
    }

    sahtout_admin_cleanup_site_for_deleted_account($site_db, $siteDbName, $username, $email);

    return ['ok' => true, 'message' => 'deleted'];
}
