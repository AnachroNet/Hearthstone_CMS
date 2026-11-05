<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'token_gift_settings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'soap_client.php';

const SAHTOUT_QUEST_FLAG_AUTOCOMPLETE = 65536;
const SAHTOUT_QUEST_FLAG_AUTOCOMPLETE_EVENT = 131072;
const SAHTOUT_TOKEN_GIFT_PAGE_TEXT_MAX_CHARS = 1800;

function sahtout_world_item_startquest_column(mysqli $worldDb): string
{
    static $column = null;
    if ($column !== null) {
        return $column;
    }
    $res = $worldDb->query("SHOW COLUMNS FROM item_template LIKE 'startquest'");
    if ($res && $res->num_rows > 0) {
        $column = 'startquest';
    } else {
        $column = 'StartQuest';
    }
    $res?->free();

    return $column;
}

function sahtout_world_item_pagetext_column(mysqli $worldDb): string
{
    static $column = null;
    if ($column !== null) {
        return $column;
    }
    $res = $worldDb->query("SHOW COLUMNS FROM item_template LIKE 'PageText'");
    if ($res && $res->num_rows > 0) {
        $column = 'PageText';
    } else {
        $column = 'pagetext';
    }
    $res?->free();

    return $column;
}

function sahtout_world_page_text_exists(mysqli $worldDb, int $pageId): bool
{
    if ($pageId <= 0) {
        return false;
    }
    $res = $worldDb->query('SELECT 1 FROM page_text WHERE ID = ' . (int) $pageId . ' LIMIT 1');

    return $res && $res->num_rows > 0;
}

function sahtout_token_gift_default_page_text_first_id(int $giftId): int
{
    return sahtout_token_gift_id_base() + ($giftId * 100);
}

function sahtout_token_gift_letter_to_page_text(string $letter): string
{
    $letter = trim($letter);
    $letter = str_replace(["\r\n", "\r", "\n"], '$B$B', $letter);

    return $letter;
}

function sahtout_token_gift_build_letter_page_body(string $letter, int $tokens): string
{
    $body = sahtout_token_gift_letter_to_page_text($letter);
    if ($tokens > 0) {
        $body .= '$B$B' . translate(
            'token_gift_page_tokens_line',
            'Reward: %d website shop tokens (granted on the site after you finish reading this letter).',
            $tokens
        );
    }

    return $body;
}

/** @return list<string> */
function sahtout_token_gift_split_page_text_chunks(string $formattedText, int $maxChars = SAHTOUT_TOKEN_GIFT_PAGE_TEXT_MAX_CHARS): array
{
    if ($formattedText === '') {
        return [''];
    }
    if (mb_strlen($formattedText) <= $maxChars) {
        return [$formattedText];
    }

    $chunks = [];
    $remaining = $formattedText;
    while ($remaining !== '') {
        if (mb_strlen($remaining) <= $maxChars) {
            $chunks[] = $remaining;
            break;
        }
        $window = mb_substr($remaining, 0, $maxChars);
        $breakAt = mb_strrpos($window, '$B$B');
        if ($breakAt === false || $breakAt < (int) ($maxChars * 0.4)) {
            $chunks[] = $window;
            $remaining = mb_substr($remaining, $maxChars);
            continue;
        }
        $chunks[] = mb_substr($remaining, 0, $breakAt);
        $remaining = mb_substr($remaining, $breakAt + 4);
    }

    return $chunks;
}

function sahtout_token_gift_page_text_block_is_free(mysqli $worldDb, int $firstPageId, int $pageCount): bool
{
    for ($i = 0; $i < $pageCount; $i++) {
        if (sahtout_world_page_text_exists($worldDb, $firstPageId + $i)) {
            return false;
        }
    }

    return true;
}

function sahtout_token_gift_allocate_page_text_first_id(
    mysqli $worldDb,
    int $giftId,
    int $pageCount,
    int $storedFirstId
): int {
    if ($storedFirstId > 0) {
        return $storedFirstId;
    }

    $pageCount = max(1, $pageCount);
    $first = sahtout_token_gift_default_page_text_first_id($giftId);
    for ($attempt = 0; $attempt < 500; $attempt++) {
        if (sahtout_token_gift_page_text_block_is_free($worldDb, $first, $pageCount)) {
            return $first;
        }
        $first += 100;
    }

    return 0;
}

function sahtout_token_gift_delete_page_text_chain(mysqli $worldDb, int $firstPageId, int $pageCount): void
{
    if ($firstPageId <= 0 || $pageCount <= 0) {
        return;
    }
    $stmt = $worldDb->prepare('DELETE FROM page_text WHERE ID = ?');
    if (!$stmt) {
        return;
    }
    for ($i = 0; $i < $pageCount; $i++) {
        $pageId = $firstPageId + $i;
        $stmt->bind_param('i', $pageId);
        $stmt->execute();
    }
    $stmt->close();
}

/** @param list<string> $chunks */
function sahtout_token_gift_write_page_text_chain(mysqli $worldDb, int $firstPageId, array $chunks): bool
{
    if ($firstPageId <= 0 || $chunks === []) {
        return false;
    }

    $stmt = $worldDb->prepare(
        'INSERT INTO page_text (ID, Text, NextPageID, VerifiedBuild) VALUES (?, ?, ?, 12340)
         ON DUPLICATE KEY UPDATE Text = VALUES(Text), NextPageID = VALUES(NextPageID), VerifiedBuild = VALUES(VerifiedBuild)'
    );
    if (!$stmt) {
        return false;
    }

    $pageCount = count($chunks);
    for ($i = 0; $i < $pageCount; $i++) {
        $pageId = $firstPageId + $i;
        $nextPageId = ($i < $pageCount - 1) ? ($firstPageId + $i + 1) : 0;
        $text = (string) $chunks[$i];
        $stmt->bind_param('isi', $pageId, $text, $nextPageId);
        if (!$stmt->execute()) {
            $stmt->close();

            return false;
        }
    }
    $stmt->close();

    return true;
}

/**
 * @return array{ok:bool, first_page_id:int, page_count:int, message:string}
 */
function sahtout_token_gift_sync_letter_page_text(
    mysqli $siteDb,
    mysqli $worldDb,
    int $giftId,
    string $letter,
    int $tokens
): array {
    $fail = static fn (string $message): array => [
        'ok' => false,
        'first_page_id' => 0,
        'page_count' => 0,
        'message' => $message,
    ];

    if ($giftId <= 0 || $worldDb->connect_error) {
        return $fail('invalid');
    }

    $tableCheck = $worldDb->query("SHOW TABLES LIKE 'page_text'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        $tableCheck?->free();

        return $fail('page_text_table_missing');
    }
    $tableCheck->free();

    $storedFirst = 0;
    $storedCount = 0;
    $load = $siteDb->prepare('SELECT page_text_id, page_text_page_count FROM token_gifts WHERE id = ? LIMIT 1');
    if ($load) {
        $load->bind_param('i', $giftId);
        $load->execute();
        $row = $load->get_result()->fetch_assoc();
        $storedFirst = (int) ($row['page_text_id'] ?? 0);
        $storedCount = (int) ($row['page_text_page_count'] ?? 0);
        $load->close();
    }

    $body = sahtout_token_gift_build_letter_page_body($letter, $tokens);
    $chunks = sahtout_token_gift_split_page_text_chunks($body);
    $newCount = count($chunks);

    $firstPageId = sahtout_token_gift_allocate_page_text_first_id($worldDb, $giftId, $newCount, $storedFirst);
    if ($firstPageId <= 0) {
        return $fail('page_text_id_allocate_failed');
    }

    if ($storedFirst > 0 && $storedCount > $newCount) {
        sahtout_token_gift_delete_page_text_chain(
            $worldDb,
            $storedFirst + $newCount,
            $storedCount - $newCount
        );
    }

    if (!sahtout_token_gift_write_page_text_chain($worldDb, $firstPageId, $chunks)) {
        return $fail('page_text_write_failed');
    }

    $save = $siteDb->prepare(
        'UPDATE token_gifts SET page_text_id = ?, page_text_page_count = ? WHERE id = ?'
    );
    if (!$save) {
        return $fail('page_text_site_save_failed');
    }
    $save->bind_param('iii', $firstPageId, $newCount, $giftId);
    if (!$save->execute()) {
        $save->close();

        return $fail('page_text_site_save_failed');
    }
    $save->close();

    return [
        'ok' => true,
        'first_page_id' => $firstPageId,
        'page_count' => $newCount,
        'message' => 'page_text_synced',
    ];
}

function sahtout_token_gift_autocomplete_quest_flags(): int
{
    return SAHTOUT_QUEST_FLAG_AUTOCOMPLETE | SAHTOUT_QUEST_FLAG_AUTOCOMPLETE_EVENT;
}

/** @return array<string, scalar|null> */
function sahtout_token_gift_item_short_description(): string
{
    return translate(
        'token_gift_item_description',
        'A sealed letter. Right-click to read.'
    );
}

function sahtout_token_gift_reference_item_display_id(mysqli $worldDb, int $itemRef): int
{
    if ($itemRef <= 0) {
        return 1102;
    }
    $res = $worldDb->query(
        'SELECT displayid FROM item_template WHERE entry = ' . (int) $itemRef . ' LIMIT 1'
    );
    if ($res && ($row = $res->fetch_assoc())) {
        $displayId = (int) ($row['displayid'] ?? 0);
        $res->free();
        if ($displayId > 0) {
            return $displayId;
        }
    }
    $res?->free();

    return 1102;
}

function sahtout_token_gift_item_publish_overrides(
    mysqli $worldDb,
    int $itemRef,
    int $questId,
    int $pageTextId,
    string $name
): array {
    $startQuestKey = sahtout_world_item_startquest_column($worldDb);
    $pageTextKey = sahtout_world_item_pagetext_column($worldDb);

    return [
        'name' => $name,
        'displayid' => sahtout_token_gift_reference_item_display_id($worldDb, $itemRef),
        'description' => sahtout_token_gift_item_short_description(),
        'class' => 12,
        'subclass' => 0,
        'bonding' => 4,
        'maxcount' => 1,
        'stackable' => 1,
        'Flags' => 1,
        $startQuestKey => $questId,
        $pageTextKey => $pageTextId,
    ];
}

/** @return array<string, scalar|null> */
function sahtout_token_gift_quest_publish_overrides(int $itemEntry, string $name, string $formattedQuestDescription): array
{
    $logDescription = translate(
        'token_gift_quest_log_description',
        'Read the letter to complete this promo and claim shop tokens on the website.'
    );

    $overrides = [
        'LogTitle' => $name,
        'LogDescription' => $logDescription,
        'QuestDescription' => $formattedQuestDescription,
        'QuestCompletionLog' => $name,
        'StartItem' => $itemEntry,
        'Flags' => sahtout_token_gift_autocomplete_quest_flags(),
        'MinLevel' => 0,
        'QuestLevel' => 1,
        'RewardMoney' => 0,
        'RewardMoneyDifficulty' => 0,
        'RewardHonor' => 0,
        'RewardTitle' => 0,
        'RewardTalents' => 0,
        'RewardArenaPoints' => 0,
        'AllowableRaces' => 0,
        'Unknown0' => 1,
    ];

    for ($slot = 1; $slot <= 4; $slot++) {
        $overrides['RequiredNpcOrGo' . $slot] = 0;
        $overrides['RequiredNpcOrGoCount' . $slot] = 0;
    }
    for ($slot = 1; $slot <= 6; $slot++) {
        $overrides['RequiredItemId' . $slot] = 0;
        $overrides['RequiredItemCount' . $slot] = 0;
    }
    for ($slot = 1; $slot <= 5; $slot++) {
        $overrides['RewardFactionID' . $slot] = 0;
        $overrides['RewardFactionValue' . $slot] = 0;
        $overrides['RewardFactionOverride' . $slot] = 0;
    }

    return $overrides;
}

function sahtout_token_gift_ensure_quest_template_addon(mysqli $worldDb, int $questId, int $questRef): void
{
    if ($questId <= 0 || sahtout_world_row_exists($worldDb, 'quest_template_addon', $questId)) {
        return;
    }
    if ($questRef > 0 && sahtout_world_row_exists($worldDb, 'quest_template_addon', $questRef)) {
        sahtout_world_clone_row($worldDb, 'quest_template_addon', $questRef, $questId, []);

        return;
    }
    $stmt = $worldDb->prepare(
        'INSERT INTO quest_template_addon (ID, ProvidedItemCount, SpecialFlags) VALUES (?, 1, 0)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $questId);
    $stmt->execute();
    $stmt->close();
}

function sahtout_token_gift_resolve_quest_template_ref(mysqli $worldDb, int $preferredRef): int
{
    if ($preferredRef > 0 && sahtout_world_row_exists($worldDb, 'quest_template', $preferredRef)) {
        return $preferredRef;
    }
    if ($preferredRef !== SAHTOUT_TOKEN_GIFT_DEFAULT_QUEST_REF
        && sahtout_world_row_exists($worldDb, 'quest_template', SAHTOUT_TOKEN_GIFT_DEFAULT_QUEST_REF)) {
        return SAHTOUT_TOKEN_GIFT_DEFAULT_QUEST_REF;
    }
    $fallback = $worldDb->query(
        'SELECT ID FROM quest_template WHERE (Flags & ' . SAHTOUT_QUEST_FLAG_AUTOCOMPLETE . ') > 0 ORDER BY ID ASC LIMIT 1'
    );
    if ($fallback && ($frow = $fallback->fetch_assoc())) {
        $id = (int) ($frow['ID'] ?? 0);
        $fallback->free();
        if ($id > 0) {
            return $id;
        }
    }
    $fallback?->free();

    return 0;
}

function sahtout_token_gift_loot_comment(int $giftId): string
{
    return 'SahtoutCMS:tg:' . $giftId;
}

function sahtout_world_table_pk_column(string $table): ?string
{
    return match ($table) {
        'item_template' => 'entry',
        'quest_template', 'quest_template_addon' => 'ID',
        default => null,
    };
}

function sahtout_world_row_exists(mysqli $worldDb, string $table, int $id): bool
{
    $pk = sahtout_world_table_pk_column($table);
    if ($pk === null || $id <= 0) {
        return false;
    }
    $res = $worldDb->query(
        'SELECT 1 FROM `' . $worldDb->real_escape_string($table) . '` WHERE `' . $pk . '` = ' . (int) $id . ' LIMIT 1'
    );

    return $res && $res->num_rows > 0;
}

/**
 * @param array<string, scalar|null> $overrides
 */
function sahtout_world_clone_row(
    mysqli $worldDb,
    string $table,
    int $fromId,
    int $toId,
    array $overrides
): bool {
    $pk = sahtout_world_table_pk_column($table);
    if ($pk === null || $fromId <= 0 || $toId <= 0) {
        return false;
    }

    $tableEsc = '`' . str_replace('`', '', $table) . '`';
    $res = $worldDb->query("SELECT * FROM {$tableEsc} WHERE `{$pk}` = " . (int) $fromId . ' LIMIT 1');
    if (!$res || !($row = $res->fetch_assoc())) {
        $res?->free();

        return false;
    }
    $res->free();

    $row[$pk] = $toId;
    foreach ($overrides as $key => $value) {
        $row[$key] = $value;
    }

    $worldDb->query("DELETE FROM {$tableEsc} WHERE `{$pk}` = " . (int) $toId);

    $columns = array_keys($row);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO ' . $tableEsc . ' (`' . implode('`,`', $columns) . '`) VALUES (' . $placeholders . ')';
    $stmt = $worldDb->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $types = str_repeat('s', count($columns));
    $values = array_values($row);
    $stmt->bind_param($types, ...$values);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function sahtout_token_gift_next_item_entry(mysqli $siteDb, mysqli $worldDb): int
{
    $base = sahtout_token_gift_id_base();
    $siteMax = 0;
    $res = $siteDb->query('SELECT COALESCE(MAX(item_entry), 0) AS m FROM token_gifts WHERE item_entry >= ' . (int) $base);
    if ($res && ($row = $res->fetch_assoc())) {
        $siteMax = (int) ($row['m'] ?? 0);
    }
    $res?->free();

    $worldMax = 0;
    $wres = $worldDb->query('SELECT COALESCE(MAX(entry), 0) AS m FROM item_template WHERE entry >= ' . (int) $base);
    if ($wres && ($wrow = $wres->fetch_assoc())) {
        $worldMax = (int) ($wrow['m'] ?? 0);
    }
    $wres?->free();

    return max($base, $siteMax, $worldMax) + 1;
}

function sahtout_token_gift_next_quest_id(mysqli $siteDb, mysqli $worldDb): int
{
    $base = sahtout_token_gift_id_base();
    $siteMax = 0;
    $res = $siteDb->query('SELECT COALESCE(MAX(quest_id), 0) AS m FROM token_gifts WHERE quest_id >= ' . (int) $base);
    if ($res && ($row = $res->fetch_assoc())) {
        $siteMax = (int) ($row['m'] ?? 0);
    }
    $res?->free();

    $worldMax = 0;
    $wres = $worldDb->query('SELECT COALESCE(MAX(ID), 0) AS m FROM quest_template WHERE ID >= ' . (int) $base);
    if ($wres && ($wrow = $wres->fetch_assoc())) {
        $worldMax = (int) ($wrow['m'] ?? 0);
    }
    $wres?->free();

    return max($base, $siteMax, $worldMax) + 1;
}

/** @return array{ok:bool, message:string} */
function sahtout_token_gift_publish_to_world(mysqli $siteDb, mysqli $worldDb, int $giftId): array
{
    if ($giftId <= 0) {
        return ['ok' => false, 'message' => 'invalid_gift'];
    }
    if ($worldDb->connect_error) {
        return ['ok' => false, 'message' => 'world_db_unavailable'];
    }

    $stmt = $siteDb->prepare(
        'SELECT id, name, letter_text, tokens, item_entry, quest_id, deploy_world_chests, loot_chance,
                extra_creature_entries, extra_go_loot_ids, published_at
         FROM token_gifts WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'load_failed'];
    }
    $stmt->bind_param('i', $giftId);
    $stmt->execute();
    $gift = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$gift) {
        return ['ok' => false, 'message' => 'not_found'];
    }

    $name = trim((string) ($gift['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'name_required'];
    }

    $itemEntry = (int) ($gift['item_entry'] ?? 0);
    $questId = (int) ($gift['quest_id'] ?? 0);
    if ($itemEntry <= 0) {
        $itemEntry = sahtout_token_gift_next_item_entry($siteDb, $worldDb);
    }
    if ($questId <= 0) {
        $questId = sahtout_token_gift_next_quest_id($siteDb, $worldDb);
    }

    $itemRef = sahtout_token_gift_item_template_ref();
    if (!sahtout_world_row_exists($worldDb, 'item_template', $itemRef)) {
        return ['ok' => false, 'message' => 'item_ref_missing'];
    }

    $questRef = sahtout_token_gift_resolve_quest_template_ref(
        $worldDb,
        sahtout_token_gift_quest_template_ref_setting()
    );
    if ($questRef <= 0) {
        return ['ok' => false, 'message' => 'quest_ref_missing'];
    }

    $letter = trim((string) ($gift['letter_text'] ?? ''));
    if ($letter === '') {
        $letter = translate('token_gift_default_letter', 'Thank you for playing on our server. Claim your shop tokens on the website after reading this letter.');
    }

    $tokens = (int) ($gift['tokens'] ?? 0);
    $formattedLetterBody = sahtout_token_gift_build_letter_page_body($letter, $tokens);
    $pageTextResult = sahtout_token_gift_sync_letter_page_text($siteDb, $worldDb, $giftId, $letter, $tokens);
    if (!$pageTextResult['ok']) {
        return ['ok' => false, 'message' => $pageTextResult['message'] ?? 'page_text_sync_failed'];
    }
    $pageTextId = (int) ($pageTextResult['first_page_id'] ?? 0);

    $itemOk = sahtout_world_clone_row(
        $worldDb,
        'item_template',
        $itemRef,
        $itemEntry,
        sahtout_token_gift_item_publish_overrides($worldDb, $itemRef, $questId, $pageTextId, $name)
    );
    if (!$itemOk) {
        return ['ok' => false, 'message' => 'item_publish_failed'];
    }

    $questOk = sahtout_world_clone_row(
        $worldDb,
        'quest_template',
        $questRef,
        $questId,
        sahtout_token_gift_quest_publish_overrides($itemEntry, $name, $formattedLetterBody)
    );
    if (!$questOk) {
        return ['ok' => false, 'message' => 'quest_publish_failed'];
    }

    sahtout_token_gift_ensure_quest_template_addon($worldDb, $questId, $questRef);

    $upd = $siteDb->prepare(
        'UPDATE token_gifts SET item_entry = ?, quest_id = ?, is_enabled = 1,
         published_at = COALESCE(published_at, NOW()) WHERE id = ?'
    );
    if (!$upd) {
        return ['ok' => false, 'message' => 'site_update_failed'];
    }
    $upd->bind_param('iii', $itemEntry, $questId, $giftId);
    if (!$upd->execute()) {
        $upd->close();

        return ['ok' => false, 'message' => 'site_update_failed'];
    }
    $upd->close();

    $deployResult = sahtout_token_gift_redeploy_loot($siteDb, $worldDb, $giftId);
    if (!$deployResult['ok']) {
        return ['ok' => false, 'message' => $deployResult['message'] ?? 'loot_deploy_failed'];
    }

    return [
        'ok' => true,
        'message' => 'published',
        'reload' => sahtout_soap_reload_token_gift_publish(),
    ];
}

/** @return array{ok:bool, message:string} */
function sahtout_token_gift_redeploy_loot(mysqli $siteDb, mysqli $worldDb, int $giftId): array
{
    sahtout_token_gift_undeploy_loot($siteDb, $worldDb, $giftId);

    $stmt = $siteDb->prepare(
        'SELECT id, item_entry, deploy_world_chests, loot_chance, extra_creature_entries, extra_go_loot_ids, published_at
         FROM token_gifts WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'message' => 'load_failed'];
    }
    $stmt->bind_param('i', $giftId);
    $stmt->execute();
    $gift = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$gift || empty($gift['published_at'])) {
        return ['ok' => false, 'message' => 'not_published'];
    }

    $itemEntry = (int) ($gift['item_entry'] ?? 0);
    if ($itemEntry <= 0) {
        return ['ok' => false, 'message' => 'no_item'];
    }

    $chance = (float) ($gift['loot_chance'] ?? 2.0);
    $chance = max(0.01, min(100.0, $chance));
    $comment = sahtout_token_gift_loot_comment($giftId);

    $targets = [];
    if ((int) ($gift['deploy_world_chests'] ?? 0) === 1) {
        foreach (sahtout_token_gift_world_chest_loot_ids() as $lootId) {
            $targets[] = ['kind' => 'gameobject', 'entry' => $lootId];
        }
    }
    foreach (sahtout_parse_comma_int_list((string) ($gift['extra_creature_entries'] ?? '')) as $creatureEntry) {
        $targets[] = ['kind' => 'creature', 'entry' => $creatureEntry];
    }
    foreach (sahtout_parse_comma_int_list((string) ($gift['extra_go_loot_ids'] ?? '')) as $goLootId) {
        $targets[] = ['kind' => 'gameobject', 'entry' => $goLootId];
    }

    foreach ($targets as $target) {
        $kind = (string) $target['kind'];
        $lootEntry = (int) $target['entry'];
        if ($lootEntry <= 0) {
            continue;
        }
        $table = $kind === 'creature' ? 'creature_loot_template' : 'gameobject_loot_template';
        if (!sahtout_token_gift_insert_loot_row($worldDb, $table, $lootEntry, $itemEntry, $chance, $comment)) {
            return ['ok' => false, 'message' => 'loot_insert_failed'];
        }
        sahtout_token_gift_record_loot_deployment($siteDb, $giftId, $kind, $lootEntry, $itemEntry, $chance);
    }

    return [
        'ok' => true,
        'message' => 'loot_deployed',
        'reload' => sahtout_soap_reload_token_gift_loot(),
    ];
}

function sahtout_token_gift_insert_loot_row(
    mysqli $worldDb,
    string $table,
    int $lootEntry,
    int $itemEntry,
    float $chance,
    string $comment
): bool {
    if (!in_array($table, ['creature_loot_template', 'gameobject_loot_template'], true)) {
        return false;
    }

    $check = $worldDb->prepare(
        "SELECT 1 FROM `{$table}` WHERE `Entry` = ? AND `Item` = ? AND `Comment` = ? LIMIT 1"
    );
    if ($check) {
        $check->bind_param('iis', $lootEntry, $itemEntry, $comment);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if ($exists) {
            return true;
        }
    }

    $stmt = $worldDb->prepare(
        "INSERT INTO `{$table}` (`Entry`, `Item`, `Reference`, `Chance`, `QuestRequired`, `LootMode`, `GroupId`, `MinCount`, `MaxCount`, `Comment`)
         VALUES (?, ?, 0, ?, 0, 1, 0, 1, 1, ?)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iids', $lootEntry, $itemEntry, $chance, $comment);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function sahtout_token_gift_record_loot_deployment(
    mysqli $siteDb,
    int $giftId,
    string $kind,
    int $lootEntry,
    int $itemEntry,
    float $chance
): void {
    $stmt = $siteDb->prepare(
        'INSERT INTO token_gift_loot_deployments (token_gift_id, loot_kind, loot_entry, item_entry, chance)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE chance = VALUES(chance)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isiid', $giftId, $kind, $lootEntry, $itemEntry, $chance);
    $stmt->execute();
    $stmt->close();
}

function sahtout_token_gift_undeploy_loot(mysqli $siteDb, mysqli $worldDb, int $giftId): void
{
    $comment = sahtout_token_gift_loot_comment($giftId);

    foreach (['gameobject_loot_template', 'creature_loot_template'] as $table) {
        $del = $worldDb->prepare("DELETE FROM `{$table}` WHERE `Comment` = ?");
        if ($del) {
            $del->bind_param('s', $comment);
            $del->execute();
            $del->close();
        }
    }

    $siteDb->query('DELETE FROM token_gift_loot_deployments WHERE token_gift_id = ' . (int) $giftId);
}

/** @return array{ok:bool, message:string} */
function sahtout_token_gift_update_world_content(
    mysqli $siteDb,
    mysqli $worldDb,
    int $giftId,
    int $itemEntry,
    int $questId,
    string $name,
    string $letter,
    int $tokens
): array {
    if ($worldDb->connect_error || $itemEntry <= 0 || $questId <= 0 || $giftId <= 0) {
        return ['ok' => false, 'message' => 'invalid'];
    }

    $formattedLetterBody = sahtout_token_gift_build_letter_page_body($letter, $tokens);
    $pageTextResult = sahtout_token_gift_sync_letter_page_text($siteDb, $worldDb, $giftId, $letter, $tokens);
    if (!$pageTextResult['ok']) {
        return ['ok' => false, 'message' => $pageTextResult['message'] ?? 'page_text_sync_failed'];
    }
    $pageTextId = (int) ($pageTextResult['first_page_id'] ?? 0);

    $startQuestKey = sahtout_world_item_startquest_column($worldDb);
    $pageTextKey = sahtout_world_item_pagetext_column($worldDb);
    $itemDescription = sahtout_token_gift_item_short_description();
    $itemSql = "UPDATE item_template SET name = ?, description = ?, `{$startQuestKey}` = ?, `{$pageTextKey}` = ? WHERE entry = ?";
    $itemStmt = $worldDb->prepare($itemSql);
    if (!$itemStmt) {
        return ['ok' => false, 'message' => 'item_update_failed'];
    }
    $itemStmt->bind_param('ssiii', $name, $itemDescription, $questId, $pageTextId, $itemEntry);
    $itemOk = $itemStmt->execute();
    $itemStmt->close();

    $logDescription = translate(
        'token_gift_quest_log_description',
        'Read the letter to complete this promo and claim shop tokens on the website.'
    );
    $flags = sahtout_token_gift_autocomplete_quest_flags();
    $questStmt = $worldDb->prepare(
        'UPDATE quest_template SET LogTitle = ?, LogDescription = ?, QuestDescription = ?, QuestCompletionLog = ?,
         StartItem = ?, Flags = ?, MinLevel = 0, QuestLevel = 1, RewardMoney = 0,
         RequiredItemId1 = 0, RequiredItemCount1 = 0, RequiredNpcOrGo1 = 0, RequiredNpcOrGoCount1 = 0
         WHERE ID = ?'
    );
    if (!$questStmt) {
        return ['ok' => false, 'message' => 'quest_update_failed'];
    }
    $questStmt->bind_param('ssssiii', $name, $logDescription, $formattedLetterBody, $name, $itemEntry, $flags, $questId);
    $questOk = $questStmt->execute();
    $questStmt->close();

    if (!$itemOk || !$questOk) {
        return ['ok' => false, 'message' => 'update_failed'];
    }

    return [
        'ok' => true,
        'message' => 'updated',
        'reload' => sahtout_soap_reload_token_gift_content(),
    ];
}
