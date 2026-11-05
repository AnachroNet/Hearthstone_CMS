<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

const SAHTOUT_TOKEN_GIFT_DEFAULT_LOOT_IDS = '2276,2277,2278,2279,2280,2284';
const SAHTOUT_TOKEN_GIFT_DEFAULT_ITEM_REF = 2223;
const SAHTOUT_TOKEN_GIFT_DEFAULT_QUEST_REF = 123;
/** Unused vanilla item_template / quest_template IDs (10 promos max). */
const SAHTOUT_TOKEN_GIFT_WORLD_ID_FIRST = 18270;
const SAHTOUT_TOKEN_GIFT_WORLD_ID_LAST = 18279;
const SAHTOUT_TOKEN_GIFT_MAX_PROMOS = 10;
/** Sealed letter icon (item 2223 display). */
const SAHTOUT_TOKEN_GIFT_DISPLAY_ID = 1102;

function sahtout_token_gift_default_world_ids_csv(): string
{
    $ids = [];
    for ($id = SAHTOUT_TOKEN_GIFT_WORLD_ID_FIRST; $id <= SAHTOUT_TOKEN_GIFT_WORLD_ID_LAST; $id++) {
        $ids[] = $id;
    }

    return implode(',', $ids);
}

/** @return list<int> */
function sahtout_token_gift_configured_world_ids(): array
{
    global $sahtout_site_settings;

    $raw = sahtout_token_gift_default_world_ids_csv();
    if (isset($sahtout_site_settings) && is_array($sahtout_site_settings)) {
        $stored = trim((string) ($sahtout_site_settings['shop.token_gift_world_ids'] ?? ''));
        if ($stored !== '') {
            $raw = $stored;
        }
    }

    $ids = sahtout_parse_comma_int_list($raw);
    sort($ids, SORT_NUMERIC);

    return $ids;
}

/** @return list<int> */
function sahtout_token_gift_world_chest_loot_ids(): array
{
    global $sahtout_site_settings;

    $raw = SAHTOUT_TOKEN_GIFT_DEFAULT_LOOT_IDS;
    if (isset($sahtout_site_settings) && is_array($sahtout_site_settings)) {
        $stored = trim((string) ($sahtout_site_settings['shop.token_gift_loot_ids'] ?? ''));
        if ($stored !== '') {
            $raw = $stored;
        }
    }

    $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return [];
    }

    $ids = [];
    foreach ($parts as $part) {
        $id = (int) $part;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    return array_map('intval', array_keys($ids));
}

function sahtout_token_gift_item_template_ref(): int
{
    global $sahtout_site_settings;
    $ref = SAHTOUT_TOKEN_GIFT_DEFAULT_ITEM_REF;
    if (isset($sahtout_site_settings) && is_array($sahtout_site_settings)) {
        $ref = (int) ($sahtout_site_settings['shop.token_gift_item_template_ref'] ?? $ref);
    }

    return max(1, $ref);
}

/** Stored site setting (0 = auto: try quest 123, then any auto-complete quest). */
function sahtout_token_gift_quest_template_ref_setting(): int
{
    global $sahtout_site_settings;
    if (!isset($sahtout_site_settings) || !is_array($sahtout_site_settings)) {
        return SAHTOUT_TOKEN_GIFT_DEFAULT_QUEST_REF;
    }

    return max(0, (int) ($sahtout_site_settings['shop.token_gift_quest_template_ref'] ?? SAHTOUT_TOKEN_GIFT_DEFAULT_QUEST_REF));
}

function sahtout_token_gift_quest_template_ref(): int
{
    $stored = sahtout_token_gift_quest_template_ref_setting();

    return $stored > 0 ? $stored : SAHTOUT_TOKEN_GIFT_DEFAULT_QUEST_REF;
}

function sahtout_token_gift_world_id_first(): int
{
    $ids = sahtout_token_gift_configured_world_ids();

    return $ids !== [] ? $ids[0] : SAHTOUT_TOKEN_GIFT_WORLD_ID_FIRST;
}

function sahtout_token_gift_world_id_last(): int
{
    $ids = sahtout_token_gift_configured_world_ids();
    if ($ids === []) {
        return SAHTOUT_TOKEN_GIFT_WORLD_ID_LAST;
    }

    return $ids[count($ids) - 1];
}

function sahtout_token_gift_max_promos(): int
{
    $ids = sahtout_token_gift_configured_world_ids();

    return $ids !== [] ? count($ids) : SAHTOUT_TOKEN_GIFT_MAX_PROMOS;
}

function sahtout_token_gift_item_display_id(): int
{
    return SAHTOUT_TOKEN_GIFT_DISPLAY_ID;
}

function sahtout_token_gift_is_reserved_world_id(int $worldId): bool
{
    return sahtout_token_gift_is_configured_world_id($worldId);
}

function sahtout_token_gift_is_configured_world_id(int $worldId): bool
{
    if ($worldId <= 0) {
        return false;
    }

    return in_array($worldId, sahtout_token_gift_configured_world_ids(), true);
}

/** @deprecated Legacy setting; reserved IDs are fixed at 18270–18279. */
function sahtout_token_gift_id_base(): int
{
    return SAHTOUT_TOKEN_GIFT_WORLD_ID_FIRST;
}

/** @return list<int> */
function sahtout_parse_comma_int_list(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return [];
    }
    $out = [];
    foreach ($parts as $part) {
        $id = (int) $part;
        if ($id > 0) {
            $out[$id] = true;
        }
    }

    return array_map('intval', array_keys($out));
}
