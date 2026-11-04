<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

const SAHTOUT_ITEMSET_DBC_ITEM_COLUMNS = 17;

/** @var array<string, list<string>> */
$GLOBALS['sahtout_itemset_dbc_column_cache'] = [];

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
 * @return list<string>
 */
function sahtout_itemset_dbc_table_columns(mysqli $dbcDb): array
{
    $dbName = '';
    $dbRes = $dbcDb->query('SELECT DATABASE() AS db');
    if ($dbRes instanceof mysqli_result) {
        $row = $dbRes->fetch_assoc();
        $dbName = trim((string) ($row['db'] ?? ''));
        $dbRes->free();
    }
    $cacheKey = $dbName . ':itemset_dbc';
    if (isset($GLOBALS['sahtout_itemset_dbc_column_cache'][$cacheKey])) {
        return $GLOBALS['sahtout_itemset_dbc_column_cache'][$cacheKey];
    }

    $columns = [];
    $res = $dbcDb->query('SHOW COLUMNS FROM itemset_dbc');
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $field = trim((string) ($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        $res->free();
    }

    $GLOBALS['sahtout_itemset_dbc_column_cache'][$cacheKey] = $columns;

    return $columns;
}

function sahtout_itemset_dbc_resolve_column(array $columns, string $wanted): ?string
{
    $want = strtolower($wanted);
    foreach ($columns as $column) {
        if (strtolower($column) === $want) {
            return $column;
        }
    }

    return null;
}

/** @param array<string, mixed> $row */
function sahtout_itemset_dbc_row_value(array $row, string $field): mixed
{
    if (array_key_exists($field, $row)) {
        return $row[$field];
    }
    $want = strtolower($field);
    foreach ($row as $key => $value) {
        if (strtolower((string) $key) === $want) {
            return $value;
        }
    }

    return null;
}

/** @param array<string, mixed> $row */
function sahtout_itemset_dbc_row_int(array $row, string $field): int
{
    return (int) sahtout_itemset_dbc_row_value($row, $field);
}

/**
 * @return list<string> SQL column identifiers (backtick-quoted)
 */
function sahtout_itemset_dbc_select_columns(mysqli $dbcDb): array
{
    $tableCols = sahtout_itemset_dbc_table_columns($dbcDb);
    $select = [];

    $idCol = sahtout_itemset_dbc_resolve_column($tableCols, 'ID');
    if ($idCol === null) {
        return [];
    }
    $select[] = '`' . str_replace('`', '``', $idCol) . '`';

    foreach (['Name_Lang_enUS', 'Name_Lang_enGB', 'Name'] as $nameCandidate) {
        $resolved = sahtout_itemset_dbc_resolve_column($tableCols, $nameCandidate);
        if ($resolved !== null) {
            $select[] = '`' . str_replace('`', '``', $resolved) . '`';
        }
    }

    for ($i = 1; $i <= SAHTOUT_ITEMSET_DBC_ITEM_COLUMNS; $i++) {
        $resolved = sahtout_itemset_dbc_resolve_column($tableCols, 'ItemID_' . $i);
        if ($resolved !== null) {
            $select[] = '`' . str_replace('`', '``', $resolved) . '`';
        }
    }

    return $select;
}

function sahtout_itemset_dbc_order_by_name_column(mysqli $dbcDb): ?string
{
    $tableCols = sahtout_itemset_dbc_table_columns($dbcDb);
    foreach (['Name_Lang_enUS', 'Name_Lang_enGB', 'Name'] as $nameCandidate) {
        $resolved = sahtout_itemset_dbc_resolve_column($tableCols, $nameCandidate);
        if ($resolved !== null) {
            return '`' . str_replace('`', '``', $resolved) . '`';
        }
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
        $entry = sahtout_itemset_dbc_row_int($row, 'ItemID_' . $i);
        if ($entry > 0) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

/** @param array<string, mixed> $row */
function sahtout_itemset_dbc_display_name(array $row): string
{
    foreach (['Name_Lang_enUS', 'Name_Lang_enGB', 'Name'] as $field) {
        $name = trim((string) (sahtout_itemset_dbc_row_value($row, $field) ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    return '';
}

/**
 * @return array<string, mixed>|null
 */
function sahtout_itemset_dbc_fetch_row(mysqli $dbcDb, int $setId): ?array
{
    if ($setId <= 0) {
        return null;
    }

    $selectCols = sahtout_itemset_dbc_select_columns($dbcDb);
    if ($selectCols === []) {
        return null;
    }

    $idCol = sahtout_itemset_dbc_resolve_column(sahtout_itemset_dbc_table_columns($dbcDb), 'ID');
    if ($idCol === null) {
        return null;
    }

    $sql = 'SELECT ' . implode(', ', $selectCols)
        . ' FROM itemset_dbc WHERE `' . str_replace('`', '``', $idCol) . '` = ? LIMIT 1';
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
    $tableCols = sahtout_itemset_dbc_table_columns($dbcDb);
    $selectCols = sahtout_itemset_dbc_select_columns($dbcDb);
    if ($selectCols === []) {
        return [];
    }

    $itemFilters = [];
    for ($i = 1; $i <= SAHTOUT_ITEMSET_DBC_ITEM_COLUMNS; $i++) {
        $resolved = sahtout_itemset_dbc_resolve_column($tableCols, 'ItemID_' . $i);
        if ($resolved !== null) {
            $itemFilters[] = '`' . str_replace('`', '``', $resolved) . '` > 0';
        }
    }
    if ($itemFilters === []) {
        return [];
    }

    $orderCol = sahtout_itemset_dbc_order_by_name_column($dbcDb);
    $idCol = sahtout_itemset_dbc_resolve_column($tableCols, 'ID');
    $orderSql = $orderCol !== null
        ? "{$orderCol} ASC, `" . str_replace('`', '``', (string) $idCol) . '` ASC'
        : '`' . str_replace('`', '``', (string) $idCol) . '` ASC';

    $sql = 'SELECT ' . implode(', ', $selectCols)
        . ' FROM itemset_dbc WHERE (' . implode(' OR ', $itemFilters) . ') ORDER BY ' . $orderSql;
    $result = $dbcDb->query($sql);
    if (!$result) {
        error_log('itemset_dbc admin_options query failed: ' . $dbcDb->error);

        return [];
    }

    $options = [];
    while ($row = $result->fetch_assoc()) {
        $id = sahtout_itemset_dbc_row_int($row, 'ID');
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
        'id' => sahtout_itemset_dbc_row_int($row, 'ID') ?: $setId,
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
