<?php

/**
 * Shop token costs from in-game vendor sell price (1 token ≈ 1 gold).
 */

const SAHTOUT_COPPER_PER_GOLD = 10000;

function sahtout_vendor_sell_copper_to_tokens(int $sellPriceCopper): int
{
    if ($sellPriceCopper <= 0) {
        return 0;
    }

    return max(1, (int) ceil($sellPriceCopper / SAHTOUT_COPPER_PER_GOLD));
}

function sahtout_fetch_item_sell_price_copper(mysqli $siteDb, ?mysqli $worldDb, int $entry): int
{
    if ($entry <= 0) {
        return 0;
    }

    $stmt = $siteDb->prepare('SELECT SellPrice FROM site_items WHERE entry = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $entry);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $copper = (int) ($row['SellPrice'] ?? 0);
        if ($copper > 0) {
            return $copper;
        }
    }

    if ($worldDb === null || $worldDb->connect_error) {
        return 0;
    }

    $tableCheck = $worldDb->query("SHOW TABLES LIKE 'item_template'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        $tableCheck?->free();

        return 0;
    }
    $tableCheck->free();

    $stmt = $worldDb->prepare('SELECT SellPrice FROM item_template WHERE entry = ? LIMIT 1');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $entry);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return (int) ($row['SellPrice'] ?? 0);
}

function sahtout_fetch_itemset_sell_price_copper(mysqli $siteDb, ?mysqli $worldDb, int $itemsetId): int
{
    if ($itemsetId <= 0) {
        return 0;
    }

    $stmt = $siteDb->prepare('SELECT entry FROM site_items WHERE itemset = ?');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $itemsetId);
    $stmt->execute();
    $res = $stmt->get_result();
    $total = 0;
    while ($row = $res->fetch_assoc()) {
        $entry = (int) ($row['entry'] ?? 0);
        $total += sahtout_fetch_item_sell_price_copper($siteDb, $worldDb, $entry);
    }
    $stmt->close();

    return $total;
}

/**
 * @param array<string, mixed> $shopRow shop_items row (entry, is_item, is_set, itemset_id, category)
 */
function sahtout_suggested_tokens_for_shop_item(mysqli $siteDb, ?mysqli $worldDb, array $shopRow): ?int
{
    $category = (string) ($shopRow['category'] ?? '');
    if (in_array($category, ['Gold', 'Service'], true)) {
        return null;
    }

    $isSet = (int) ($shopRow['is_set'] ?? 0) === 1;
    if ($isSet) {
        $itemsetId = (int) ($shopRow['itemset_id'] ?? 0);
        if ($itemsetId <= 0) {
            return null;
        }
        $copper = sahtout_fetch_itemset_sell_price_copper($siteDb, $worldDb, $itemsetId);

        return $copper > 0 ? sahtout_vendor_sell_copper_to_tokens($copper) : null;
    }

    if ((int) ($shopRow['is_item'] ?? 0) !== 1) {
        return null;
    }

    $entry = (int) ($shopRow['entry'] ?? 0);
    if ($entry <= 0) {
        return null;
    }

    $copper = sahtout_fetch_item_sell_price_copper($siteDb, $worldDb, $entry);

    return $copper > 0 ? sahtout_vendor_sell_copper_to_tokens($copper) : null;
}

/**
 * @return array{updated:int, skipped:int, unchanged:int}
 */
function sahtout_sync_shop_item_token_prices(mysqli $siteDb, ?mysqli $worldDb): array
{
    $stats = ['updated' => 0, 'skipped' => 0, 'unchanged' => 0];
    $res = $siteDb->query(
        'SELECT item_id, category, entry, is_item, is_set, itemset_id, token_cost FROM shop_items'
    );
    if (!$res) {
        return $stats;
    }

    $update = $siteDb->prepare('UPDATE shop_items SET token_cost = ? WHERE item_id = ?');
    if (!$update) {
        $res->free();

        return $stats;
    }

    while ($row = $res->fetch_assoc()) {
        $suggested = sahtout_suggested_tokens_for_shop_item($siteDb, $worldDb, $row);
        if ($suggested === null) {
            $stats['skipped']++;
            continue;
        }
        $itemId = (int) ($row['item_id'] ?? 0);
        $current = (int) ($row['token_cost'] ?? 0);
        if ($current === $suggested) {
            $stats['unchanged']++;
            continue;
        }
        $update->bind_param('ii', $suggested, $itemId);
        if ($update->execute()) {
            $stats['updated']++;
        } else {
            $stats['skipped']++;
        }
    }

    $update->close();
    $res->free();

    return $stats;
}
