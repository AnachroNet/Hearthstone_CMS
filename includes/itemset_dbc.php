<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

const SAHTOUT_ITEMSET_DBC_ITEM_COLUMNS = 17;

function sahtout_itemset_dbc_table_exists(?mysqli $db): bool
{
    if (!$db instanceof mysqli || $db->connect_error) {
        return false;
    }
    $res = $db->query("SHOW TABLES LIKE 'itemset_dbc'");

    return $res instanceof mysqli_result && $res->num_rows > 0;
}

/**
 * Prefer site / shop database (same pattern as holidays_dbc), then acore_world.
 */
function sahtout_itemset_dbc_db(): ?mysqli
{
    global $world_db, $site_db;

    if ($site_db instanceof mysqli && !$site_db->connect_error && sahtout_itemset_dbc_table_exists($site_db)) {
        return $site_db;
    }
    if ($world_db instanceof mysqli && !$world_db->connect_error && sahtout_itemset_dbc_table_exists($world_db)) {
        return $world_db;
    }

    return null;
}

/**
 * @param array<string, mixed> $row
 * @return list<int>
 */
function sahtout_itemset_dbc_entries_from_row(array $row): array
{
    $entries = [];
    for ($i = 1; $i <= SAHTOUT_ITEMSET_DBC_ITEM_COLUMNS; $i++) {
        $key = 'ItemID_' . $i;
        $entry = (int) ($row[$key] ?? 0);
        if ($entry > 0) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

function sahtout_itemset_dbc_display_name(array $row): string
{
    $name = trim((string) ($row['Name_Lang_enUS'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['Name_Lang_enGB'] ?? ''));
    }

    return $name;
}

/**
 * @return array<string, mixed>|null
 */
function sahtout_itemset_dbc_fetch_row(mysqli $dbcDb, int $setId): ?array
{
    if ($setId <= 0) {
        return null;
    }

    $cols = ['ID', 'Name_Lang_enUS', 'Name_Lang_enGB'];
    for ($i = 1; $i <= SAHTOUT_ITEMSET_DBC_ITEM_COLUMNS; $i++) {
        $cols[] = 'ItemID_' . $i;
    }
    $sql = 'SELECT ' . implode(', ', $cols) . ' FROM itemset_dbc WHERE ID = ? LIMIT 1';
    $stmt = $dbcDb->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $setId);
    if (!$stmt->execute()) {
        $stmt->close();

        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * @param list<int> $entries
 * @return array<int, string>
 */
function sahtout_itemset_dbc_resolve_item_names(?mysqli $siteDb, ?mysqli $worldDb, array $entries): array
{
    $entries = array_values(array_unique(array_filter(array_map('intval', $entries), static fn (int $e): bool => $e > 0)));
    if ($entries === []) {
        return [];
    }

    $names = [];
    $missing = $entries;

    if ($siteDb instanceof mysqli && !$siteDb->connect_error) {
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $types = str_repeat('i', count($missing));
        $sql = "SELECT entry, name FROM site_items WHERE entry IN ({$placeholders})";
        $stmt = $siteDb->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$missing);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $entry = (int) ($row['entry'] ?? 0);
                    if ($entry > 0) {
                        $names[$entry] = (string) ($row['name'] ?? '');
                    }
                }
            }
            $stmt->close();
        }
    }

    $missing = array_values(array_filter($missing, static fn (int $e): bool => !isset($names[$e]) || $names[$e] === ''));
    if ($missing !== [] && $worldDb instanceof mysqli && !$worldDb->connect_error) {
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $types = str_repeat('i', count($missing));
        $sql = "SELECT entry, name FROM item_template WHERE entry IN ({$placeholders})";
        $stmt = $worldDb->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$missing);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $entry = (int) ($row['entry'] ?? 0);
                    if ($entry > 0 && (!isset($names[$entry]) || $names[$entry] === '')) {
                        $names[$entry] = (string) ($row['name'] ?? '');
                    }
                }
            }
            $stmt->close();
        }
    }

    foreach ($entries as $entry) {
        if (!isset($names[$entry]) || $names[$entry] === '') {
            $names[$entry] = 'Item #' . $entry;
        }
    }

    return $names;
}

/**
 * @return list<array{id: int, name: string, item_count: int}>
 */
function sahtout_itemset_dbc_admin_options(mysqli $dbcDb): array
{
    $itemCols = [];
    for ($i = 1; $i <= SAHTOUT_ITEMSET_DBC_ITEM_COLUMNS; $i++) {
        $itemCols[] = 'ItemID_' . $i;
    }
    $nonEmpty = implode(' OR ', array_map(static fn (string $c): string => "`{$c}` > 0", $itemCols));
    $sql = 'SELECT ID, Name_Lang_enUS, Name_Lang_enGB, ' . implode(', ', $itemCols)
        . " FROM itemset_dbc WHERE {$nonEmpty} ORDER BY Name_Lang_enUS ASC, ID ASC";
    $result = $dbcDb->query($sql);
    if (!$result) {
        return [];
    }

    $options = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int) ($row['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $entries = sahtout_itemset_dbc_entries_from_row($row);
        if ($entries === []) {
            continue;
        }
        $label = sahtout_itemset_dbc_display_name($row);
        if ($label === '') {
            $label = 'Set #' . $id;
        }
        $options[] = [
            'id' => $id,
            'name' => $label,
            'item_count' => count($entries),
        ];
    }
    $result->free();

    return $options;
}

/**
 * @return array{id: int, name: string, items: list<array{entry: int, name: string}>}|null
 */
function sahtout_itemset_dbc_detail(?mysqli $siteDb, ?mysqli $worldDb, mysqli $dbcDb, int $setId): ?array
{
    $row = sahtout_itemset_dbc_fetch_row($dbcDb, $setId);
    if ($row === null) {
        return null;
    }
    $entries = sahtout_itemset_dbc_entries_from_row($row);
    if ($entries === []) {
        return null;
    }
    $nameMap = sahtout_itemset_dbc_resolve_item_names($siteDb, $worldDb, $entries);
    $items = [];
    foreach ($entries as $entry) {
        $items[] = [
            'entry' => $entry,
            'name' => $nameMap[$entry] ?? ('Item #' . $entry),
        ];
    }
    $displayName = sahtout_itemset_dbc_display_name($row);

    return [
        'id' => (int) ($row['ID'] ?? $setId),
        'name' => $displayName !== '' ? $displayName : ('Set #' . $setId),
        'items' => $items,
    ];
}

function sahtout_itemset_dbc_is_valid(mysqli $dbcDb, int $setId): bool
{
    $row = sahtout_itemset_dbc_fetch_row($dbcDb, $setId);
    if ($row === null) {
        return false;
    }

    return sahtout_itemset_dbc_entries_from_row($row) !== [];
}
