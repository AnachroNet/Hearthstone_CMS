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
    return SAHTOUT_TOKEN_GIFT_WORLD_ID_FIRST;
}

function sahtout_token_gift_world_id_last(): int
{
    return SAHTOUT_TOKEN_GIFT_WORLD_ID_LAST;
}

function sahtout_token_gift_max_promos(): int
{
    return SAHTOUT_TOKEN_GIFT_MAX_PROMOS;
}

function sahtout_token_gift_item_display_id(): int
{
    return SAHTOUT_TOKEN_GIFT_DISPLAY_ID;
}

function sahtout_token_gift_is_reserved_world_id(int $worldId): bool
{
    return $worldId >= SAHTOUT_TOKEN_GIFT_WORLD_ID_FIRST && $worldId <= SAHTOUT_TOKEN_GIFT_WORLD_ID_LAST;
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
