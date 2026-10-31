<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

const SAHTOUT_ITEM_SEARCH_MAX_RESULTS = 50;
const SAHTOUT_ITEM_SEARCH_MIN_QUERY_LEN = 2;

/**
 * @return array{ok: bool, message: string, items: list<array{entry: int, name: string, description: string, quality: int}>}
 */
function sahtout_admin_search_item_template(mysqli $worldDb, string $query, int $limit = SAHTOUT_ITEM_SEARCH_MAX_RESULTS): array
{
    $fail = static function (string $code): array {
        return ['ok' => false, 'message' => $code, 'items' => []];
    };

    $query = trim($query);
    if ($query === '') {
        return $fail('empty_query');
    }
    if (strlen($query) > 80) {
        return $fail('query_too_long');
    }

    $tableCheck = $worldDb->query("SHOW TABLES LIKE 'item_template'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        if ($tableCheck) {
            $tableCheck->free();
        }

        return $fail('world_unavailable');
    }
    $tableCheck->free();

    $limit = max(1, min($limit, SAHTOUT_ITEM_SEARCH_MAX_RESULTS));
    $like = '%' . $query . '%';
    $entryExact = ctype_digit($query) ? (int) $query : 0;

    if ($entryExact > 0) {
        $sql = 'SELECT entry, name, Quality, description
            FROM item_template
            WHERE entry = ? OR name LIKE ?
            ORDER BY CASE WHEN entry = ? THEN 0 ELSE 1 END, name ASC
            LIMIT ?';
        $stmt = $worldDb->prepare($sql);
        if (!$stmt) {
            return $fail('search_failed');
        }
        $stmt->bind_param('isii', $entryExact, $like, $entryExact, $limit);
    } else {
        if (strlen($query) < SAHTOUT_ITEM_SEARCH_MIN_QUERY_LEN) {
            return $fail('query_too_short');
        }
        $sql = 'SELECT entry, name, Quality, description
            FROM item_template
            WHERE name LIKE ? OR (description IS NOT NULL AND description != "" AND description LIKE ?)
            ORDER BY name ASC
            LIMIT ?';
        $stmt = $worldDb->prepare($sql);
        if (!$stmt) {
            return $fail('search_failed');
        }
        $stmt->bind_param('ssi', $like, $like, $limit);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $desc = trim((string) ($row['description'] ?? ''));
        if ($desc === '') {
            $desc = '—';
        } elseif (strlen($desc) > 160) {
            $desc = substr($desc, 0, 157) . '…';
        }
        $items[] = [
            'entry' => (int) ($row['entry'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'description' => $desc,
            'quality' => (int) ($row['Quality'] ?? 0),
        ];
    }
    if ($res) {
        $res->free();
    }
    $stmt->close();

    return ['ok' => true, 'message' => 'ok', 'items' => $items];
}
