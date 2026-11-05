<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'token_gift_settings.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'soap_client.php';

/** Auto-reward on complete (no NPC turn-in); required for item-started promos on AzerothCore. */
const SAHTOUT_QUEST_FLAG_TRACKING = 1024;
/**
 * AzerothCore maps quest_template.QuestType to Quest::Method. Method 2 shows quest letter text in the
 * quest dialog (SendQuestGiverQuestDetails). Method 0 skips that and opens the turn-in pane instead.
 * Auto-complete on accept still works with Method 2 when there are no item/NPC objectives.
 */
const SAHTOUT_TOKEN_GIFT_QUEST_METHOD = 2;
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

/** Letter body formatted for quest_template / quest dialog (same $B markers as WoW UI). */
function sahtout_token_gift_build_quest_letter_body(string $letter, int $tokens): string
{
    return sahtout_token_gift_build_letter_page_body($letter, $tokens);
}

function sahtout_token_gift_detach_page_text(mysqli $siteDb, mysqli $worldDb, int $giftId): void
{
    if ($giftId <= 0) {
        return;
    }
    $load = $siteDb->prepare('SELECT page_text_id, page_text_page_count FROM token_gifts WHERE id = ? LIMIT 1');
    if (!$load) {
        return;
    }
    $load->bind_param('i', $giftId);
    $load->execute();
    $row = $load->get_result()->fetch_assoc();
    $load->close();
    if (!$row) {
        return;
    }
    $first = (int) ($row['page_text_id'] ?? 0);
    $count = (int) ($row['page_text_page_count'] ?? 0);
    if ($first > 0 && $count > 0 && !$worldDb->connect_error) {
        sahtout_token_gift_delete_page_text_chain($worldDb, $first, $count);
    }
    $siteDb->query(
        'UPDATE token_gifts SET page_text_id = 0, page_text_page_count = 0 WHERE id = ' . (int) $giftId
    );
}

function sahtout_token_gift_clear_world_locale_rows(mysqli $worldDb, string $table, int $id): void
{
    if ($id <= 0 || $worldDb->connect_error) {
        return;
    }
    $allowed = ['item_template_locale' => 'ID', 'quest_template_locale' => 'ID'];
    if (!isset($allowed[$table])) {
        return;
    }
    $check = $worldDb->query("SHOW TABLES LIKE '" . $worldDb->real_escape_string($table) . "'");
    if (!$check || $check->num_rows === 0) {
        $check?->free();

        return;
    }
    $check->free();
    $pk = $allowed[$table];
    $worldDb->query(
        'DELETE FROM `' . $table . '` WHERE `' . $pk . '` = ' . (int) $id
    );
}

/**
 * Empty locale rows override quest_template text in the client and show blank dialogs.
 *
 * @return list<string> AC locale codes synced (e.g. enUS)
 */
function sahtout_token_gift_sync_quest_locale(
    mysqli $worldDb,
    int $questId,
    string $title,
    string $details,
    string $objectives,
    string $completionLog
): array {
    if ($questId <= 0 || $worldDb->connect_error) {
        return [];
    }
    sahtout_token_gift_clear_world_locale_rows($worldDb, 'quest_template_locale', $questId);

    $check = $worldDb->query("SHOW TABLES LIKE 'quest_template_locale'");
    if (!$check || $check->num_rows === 0) {
        $check?->free();

        return [];
    }
    $check->free();

    $locales = ['enUS'];
    $stmt = $worldDb->prepare(
        'INSERT INTO quest_template_locale (ID, locale, Title, Details, Objectives, EndText, CompletedText, VerifiedBuild)
         VALUES (?, ?, ?, ?, ?, ?, ?, 12340)
         ON DUPLICATE KEY UPDATE
           Title = VALUES(Title), Details = VALUES(Details), Objectives = VALUES(Objectives),
           EndText = VALUES(EndText), CompletedText = VALUES(CompletedText), VerifiedBuild = VALUES(VerifiedBuild)'
    );
    if (!$stmt) {
        return [];
    }
    $endText = '';
    foreach ($locales as $locale) {
        $stmt->bind_param('issssss', $questId, $locale, $title, $details, $objectives, $endText, $completionLog);
        $stmt->execute();
    }
    $stmt->close();

    return $locales;
}

function sahtout_token_gift_sync_item_locale(mysqli $worldDb, int $itemEntry, string $name): void
{
    if ($itemEntry <= 0 || $worldDb->connect_error) {
        return;
    }
    sahtout_token_gift_clear_world_locale_rows($worldDb, 'item_template_locale', $itemEntry);

    $check = $worldDb->query("SHOW TABLES LIKE 'item_template_locale'");
    if (!$check || $check->num_rows === 0) {
        $check?->free();

        return;
    }
    $check->free();

    $locale = 'enUS';
    $description = sahtout_token_gift_item_short_description();
    $stmt = $worldDb->prepare(
        'INSERT INTO item_template_locale (ID, locale, Name, Description, VerifiedBuild)
         VALUES (?, ?, ?, ?, 12340)
         ON DUPLICATE KEY UPDATE Name = VALUES(Name), Description = VALUES(Description), VerifiedBuild = VALUES(VerifiedBuild)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('isss', $itemEntry, $locale, $name, $description);
    $stmt->execute();
    $stmt->close();
}

/** @return array<string, int> */
function sahtout_token_gift_reference_item_shell(mysqli $worldDb, int $itemRef): array
{
    $defaults = [
        'InventoryType' => 0,
        'Material' => 0,
        'sheath' => 0,
        'PageMaterial' => 0,
        'LanguageID' => 0,
    ];
    if ($itemRef <= 0) {
        return $defaults;
    }
    $res = $worldDb->query(
        'SELECT InventoryType, Material, sheath, PageMaterial, LanguageID FROM item_template WHERE entry = '
        . (int) $itemRef . ' LIMIT 1'
    );
    if (!$res || !($row = $res->fetch_assoc())) {
        $res?->free();

        return $defaults;
    }
    $res->free();

    return [
        'InventoryType' => (int) ($row['InventoryType'] ?? 0),
        'Material' => (int) ($row['Material'] ?? 0),
        'sheath' => (int) ($row['sheath'] ?? 0),
        'PageMaterial' => (int) ($row['PageMaterial'] ?? 0),
        'LanguageID' => (int) ($row['LanguageID'] ?? 0),
    ];
}

function sahtout_token_gift_finalize_item_template(
    mysqli $worldDb,
    int $itemEntry,
    int $itemRef,
    int $questId,
    string $name
): bool {
    if ($itemEntry <= 0 || $worldDb->connect_error) {
        return false;
    }
    $startQuestKey = sahtout_world_item_startquest_column($worldDb);
    $pageTextKey = sahtout_world_item_pagetext_column($worldDb);
    $shell = sahtout_token_gift_reference_item_shell($worldDb, $itemRef);
    $displayId = sahtout_token_gift_reference_item_display_id($worldDb, $itemRef);
    $description = sahtout_token_gift_item_short_description();

    $sql = 'UPDATE item_template SET name = ?, description = ?, displayid = ?, class = 12, subclass = 0,
            Quality = 1, Flags = 0, bonding = 4, maxcount = 1, stackable = 1,
            InventoryType = ?, Material = ?, sheath = ?, PageMaterial = ?, LanguageID = ?,
            `' . $startQuestKey . '` = ?, `' . $pageTextKey . '` = 0 WHERE entry = ?';
    $stmt = $worldDb->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'ssiiiiiiii',
        $name,
        $description,
        $displayId,
        $shell['InventoryType'],
        $shell['Material'],
        $shell['sheath'],
        $shell['PageMaterial'],
        $shell['LanguageID'],
        $questId,
        $itemEntry
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
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

function sahtout_token_gift_quest_flags(): int
{
    return SAHTOUT_QUEST_FLAG_TRACKING;
}

function sahtout_token_gift_quest_reward_text(): string
{
    return translate(
        'token_gift_quest_reward_text',
        'Thank you for reading. Log in on the website to receive your shop tokens.'
    );
}

/**
 * @return array{
 *   db_ok: bool,
 *   soap_ok: ?bool,
 *   soap_configured: bool,
 *   issues: list<string>,
 *   item_entry: int,
 *   quest_id: int,
 *   page_text_id: int
 * }
 */
function sahtout_token_gift_verify_world_publish(
    mysqli $worldDb,
    int $giftId,
    int $itemEntry,
    int $questId,
    int $pageTextId,
    string $itemName,
    bool $expectLootRows
): array {
    $dbIssues = [];
    $soapIssues = [];
    if ($worldDb->connect_error || $itemEntry <= 0 || $questId <= 0) {
        return [
            'db_ok' => false,
            'soap_ok' => null,
            'soap_configured' => sahtout_soap_is_configured(),
            'issues' => ['invalid_args'],
            'item_entry' => $itemEntry,
            'quest_id' => $questId,
            'page_text_id' => $pageTextId,
        ];
    }

    $startQuestKey = sahtout_world_item_startquest_column($worldDb);
    $pageTextKey = sahtout_world_item_pagetext_column($worldDb);

    $itemSql = 'SELECT name, class, displayid, `' . $startQuestKey . '`, `' . $pageTextKey
        . '` FROM item_template WHERE entry = ' . (int) $itemEntry . ' LIMIT 1';
    $itemRes = $worldDb->query($itemSql);
    if (!$itemRes || !($itemRow = $itemRes->fetch_assoc())) {
        $dbIssues[] = 'item_row';
    } else {
        if ((int) ($itemRow['class'] ?? 0) !== 12) {
            $dbIssues[] = 'item_class';
        }
        if ((int) ($itemRow['displayid'] ?? 0) !== sahtout_token_gift_item_display_id()) {
            $dbIssues[] = 'item_display';
        }
        if ((int) ($itemRow[$startQuestKey] ?? 0) !== $questId) {
            $dbIssues[] = 'item_startquest';
        }
        if ((int) ($itemRow[$pageTextKey] ?? 0) !== 0) {
            $dbIssues[] = 'item_pagetext';
        }
        $dbName = trim((string) ($itemRow['name'] ?? ''));
        if ($dbName !== '' && trim($itemName) !== '' && $dbName !== trim($itemName)) {
            $dbIssues[] = 'item_name';
        }
    }
    $itemRes?->free();

    $questRes = $worldDb->query(
        'SELECT StartItem, Flags, QuestType, RequiredItemId1, RequiredItemCount1, RewardXPDifficulty,
                CHAR_LENGTH(QuestDescription) AS details_len
         FROM quest_template WHERE ID = ' . (int) $questId . ' LIMIT 1'
    );
    if (!$questRes || !($questRow = $questRes->fetch_assoc())) {
        $dbIssues[] = 'quest_row';
    } else {
        if ((int) ($questRow['StartItem'] ?? 0) !== $itemEntry) {
            $dbIssues[] = 'quest_startitem';
        }
        if (((int) ($questRow['Flags'] ?? 0) & SAHTOUT_QUEST_FLAG_TRACKING) === 0) {
            $dbIssues[] = 'quest_flags';
        }
        if ((int) ($questRow['QuestType'] ?? -1) !== SAHTOUT_TOKEN_GIFT_QUEST_METHOD) {
            $dbIssues[] = 'quest_method';
        }
        if ((int) ($questRow['RequiredItemId1'] ?? 0) !== 0 || (int) ($questRow['RequiredItemCount1'] ?? 0) !== 0) {
            $dbIssues[] = 'quest_required_item';
        }
        if ((int) ($questRow['RewardXPDifficulty'] ?? 0) !== 0) {
            $dbIssues[] = 'quest_reward_xp';
        }
        if ((int) ($questRow['details_len'] ?? 0) <= 0) {
            $dbIssues[] = 'quest_description';
        }
    }
    $questRes?->free();

    if (sahtout_world_row_exists($worldDb, 'quest_request_items', $questId)) {
        $req = $worldDb->query(
            'SELECT CHAR_LENGTH(CompletionText) AS text_len FROM quest_request_items WHERE ID = '
            . (int) $questId . ' LIMIT 1'
        );
        if (!$req || !($reqRow = $req->fetch_assoc()) || (int) ($reqRow['text_len'] ?? 0) <= 0) {
            $dbIssues[] = 'quest_request_items';
        }
        $req?->free();
    } else {
        $dbIssues[] = 'quest_request_items';
    }

    if (sahtout_world_row_exists($worldDb, 'quest_offer_reward', $questId)) {
        $rew = $worldDb->query(
            'SELECT CHAR_LENGTH(RewardText) AS text_len FROM quest_offer_reward WHERE ID = '
            . (int) $questId . ' LIMIT 1'
        );
        if (!$rew || !($rewRow = $rew->fetch_assoc()) || (int) ($rewRow['text_len'] ?? 0) <= 0) {
            $dbIssues[] = 'quest_offer_reward';
        }
        $rew?->free();
    } else {
        $dbIssues[] = 'quest_offer_reward';
    }

    if ($expectLootRows && $giftId > 0) {
        $comment = sahtout_token_gift_loot_comment($giftId);
        $commentEsc = $worldDb->real_escape_string($comment);
        $lootFound = false;
        foreach (['gameobject_loot_template', 'creature_loot_template'] as $table) {
            $res = $worldDb->query(
                "SELECT 1 FROM `{$table}` WHERE `Item` = " . (int) $itemEntry
                . " AND `Comment` = '" . $commentEsc . "' LIMIT 1"
            );
            if ($res && $res->num_rows > 0) {
                $lootFound = true;
                $res->free();
                break;
            }
            $res?->free();
        }
        if (!$lootFound) {
            $dbIssues[] = 'loot_rows';
        }
    }

    $dbOk = $dbIssues === [];
    $soapConfigured = sahtout_soap_is_configured();
    $soapOk = null;

    if ($soapConfigured) {
        $soapOk = true;
        $itemSoap = sahtout_soap_world_has_item_template($itemEntry, $itemName);
        if (!$itemSoap['ok']) {
            $soapIssues[] = 'soap_item';
            $soapOk = false;
        }
        $questSoap = sahtout_soap_world_has_quest_template($questId, $itemName);
        if (!$questSoap['ok']) {
            $soapIssues[] = 'soap_quest';
            $soapOk = false;
        }
    }

    return [
        'db_ok' => $dbOk,
        'soap_ok' => $soapOk,
        'soap_configured' => $soapConfigured,
        'issues' => array_values(array_merge($dbIssues, $soapIssues)),
        'item_entry' => $itemEntry,
        'quest_id' => $questId,
        'page_text_id' => $pageTextId,
    ];
}

function sahtout_token_gift_sync_quest_dialog_tables(
    mysqli $worldDb,
    int $questId,
    int $questRef,
    string $completionText,
    string $rewardText
): void {
    if ($questId <= 0 || $worldDb->connect_error) {
        return;
    }

    $reqTable = $worldDb->query("SHOW TABLES LIKE 'quest_request_items'");
    if ($reqTable && $reqTable->num_rows > 0) {
        $reqTable->free();
        $stmt = $worldDb->prepare(
            'INSERT INTO quest_request_items (ID, EmoteOnComplete, EmoteOnIncomplete, CompletionText, VerifiedBuild)
             VALUES (?, 0, 0, ?, 12340)
             ON DUPLICATE KEY UPDATE CompletionText = VALUES(CompletionText), VerifiedBuild = VALUES(VerifiedBuild)'
        );
        if ($stmt) {
            $stmt->bind_param('is', $questId, $completionText);
            $stmt->execute();
            $stmt->close();
        }
    }

    $rewTable = $worldDb->query("SHOW TABLES LIKE 'quest_offer_reward'");
    if ($rewTable && $rewTable->num_rows > 0) {
        $rewTable->free();
        if ($questRef > 0
            && sahtout_world_row_exists($worldDb, 'quest_offer_reward', $questRef)
            && !sahtout_world_row_exists($worldDb, 'quest_offer_reward', $questId)) {
            sahtout_world_clone_row($worldDb, 'quest_offer_reward', $questRef, $questId, []);
        }
        $stmt = $worldDb->prepare(
            'INSERT INTO quest_offer_reward (ID, Emote1, Emote2, Emote3, Emote4, EmoteDelay1, EmoteDelay2, EmoteDelay3, EmoteDelay4, RewardText, VerifiedBuild)
             VALUES (?, 1, 0, 0, 0, 0, 0, 0, 0, ?, 12340)
             ON DUPLICATE KEY UPDATE RewardText = VALUES(RewardText), VerifiedBuild = VALUES(VerifiedBuild)'
        );
        if ($stmt) {
            $stmt->bind_param('is', $questId, $rewardText);
            $stmt->execute();
            $stmt->close();
        }
    }

    if ($questRef > 0
        && sahtout_world_row_exists($worldDb, 'quest_details', $questRef)
        && !sahtout_world_row_exists($worldDb, 'quest_details', $questId)) {
        sahtout_world_clone_row($worldDb, 'quest_details', $questRef, $questId, []);
    }
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
    unset($worldDb, $itemRef);

    return sahtout_token_gift_item_display_id();
}

function sahtout_token_gift_item_publish_overrides(
    mysqli $worldDb,
    int $itemRef,
    int $questId,
    string $name
): array {
    $startQuestKey = sahtout_world_item_startquest_column($worldDb);
    $pageTextKey = sahtout_world_item_pagetext_column($worldDb);
    $shell = sahtout_token_gift_reference_item_shell($worldDb, $itemRef);

    return [
        'name' => $name,
        'displayid' => sahtout_token_gift_reference_item_display_id($worldDb, $itemRef),
        'description' => sahtout_token_gift_item_short_description(),
        'class' => 12,
        'subclass' => 0,
        'bonding' => 4,
        'maxcount' => 1,
        'stackable' => 1,
        'Flags' => 0,
        'Quality' => 1,
        'InventoryType' => $shell['InventoryType'],
        'Material' => $shell['Material'],
        'sheath' => $shell['sheath'],
        'PageMaterial' => $shell['PageMaterial'],
        'LanguageID' => $shell['LanguageID'],
        $startQuestKey => $questId,
        $pageTextKey => 0,
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
        'QuestType' => SAHTOUT_TOKEN_GIFT_QUEST_METHOD,
        'Flags' => sahtout_token_gift_quest_flags(),
        'MinLevel' => 0,
        'QuestLevel' => 1,
        'RewardXPDifficulty' => 0,
        'RewardMoney' => 0,
        'RewardMoneyDifficulty' => 0,
        'RewardHonor' => 0,
        'RewardTitle' => 0,
        'RewardTalents' => 0,
        'RewardArenaPoints' => 0,
        'AllowableRaces' => 0,
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
    if ($questId <= 0) {
        return;
    }
    if (!sahtout_world_row_exists($worldDb, 'quest_template_addon', $questId)) {
        if ($questRef > 0 && sahtout_world_row_exists($worldDb, 'quest_template_addon', $questRef)) {
            sahtout_world_clone_row($worldDb, 'quest_template_addon', $questRef, $questId, [
                'PrevQuestID' => 0,
                'NextQuestID' => 0,
                'ExclusiveGroup' => 0,
                'BreadcrumbForQuestId' => 0,
                'ProvidedItemCount' => 1,
                'SpecialFlags' => 1,
            ]);
        } else {
            $stmt = $worldDb->prepare(
                'INSERT INTO quest_template_addon (ID, ProvidedItemCount, SpecialFlags) VALUES (?, 1, 1)'
            );
            if ($stmt) {
                $stmt->bind_param('i', $questId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    $upd = $worldDb->prepare(
        'UPDATE quest_template_addon SET PrevQuestID = 0, NextQuestID = 0, ExclusiveGroup = 0,
         BreadcrumbForQuestId = 0, ProvidedItemCount = 1, SpecialFlags = 1 WHERE ID = ?'
    );
    if ($upd) {
        $upd->bind_param('i', $questId);
        $upd->execute();
        $upd->close();
    }
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
        'SELECT ID FROM quest_template WHERE (Flags & ' . SAHTOUT_QUEST_FLAG_TRACKING . ') > 0 ORDER BY ID ASC LIMIT 1'
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
        'quest_template', 'quest_template_addon', 'quest_details', 'quest_request_items', 'quest_offer_reward' => 'ID',
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

/** Copy a world row with INSERT…SELECT so numeric columns stay intact (clone_row binds all as strings). */
function sahtout_world_copy_row_insert_select(
    mysqli $worldDb,
    string $table,
    int $fromId,
    int $toId
): bool {
    $pk = sahtout_world_table_pk_column($table);
    if ($pk === null || $fromId <= 0 || $toId <= 0 || !sahtout_world_row_exists($worldDb, $table, $fromId)) {
        return false;
    }

    $tableEsc = '`' . str_replace('`', '', $table) . '`';
    $worldDb->query("DELETE FROM {$tableEsc} WHERE `{$pk}` = " . (int) $toId);

    $colsRes = $worldDb->query("SHOW COLUMNS FROM {$tableEsc}");
    if (!$colsRes) {
        return false;
    }
    $cols = [];
    while ($colRow = $colsRes->fetch_assoc()) {
        $cols[] = (string) ($colRow['Field'] ?? '');
    }
    $colsRes->free();
    if ($cols === []) {
        return false;
    }

    $selectParts = [];
    foreach ($cols as $col) {
        if ($col === $pk) {
            $selectParts[] = (string) (int) $toId;
        } else {
            $selectParts[] = '`' . str_replace('`', '', $col) . '`';
        }
    }
    $colList = '`' . implode('`,`', array_map(static fn (string $c): string => str_replace('`', '', $c), $cols)) . '`';
    $sql = 'INSERT INTO ' . $tableEsc . ' (' . $colList . ') SELECT ' . implode(',', $selectParts)
        . ' FROM ' . $tableEsc . ' WHERE `' . $pk . '` = ' . (int) $fromId . ' LIMIT 1';

    return (bool) $worldDb->query($sql);
}

/**
 * @param array<string, scalar|null> $overrides
 */
function sahtout_world_apply_row_overrides(
    mysqli $worldDb,
    string $table,
    int $id,
    array $overrides
): bool {
    $pk = sahtout_world_table_pk_column($table);
    if ($pk === null || $id <= 0 || $overrides === []) {
        return false;
    }
    $sets = [];
    $types = '';
    $values = [];
    foreach ($overrides as $column => $value) {
        $sets[] = '`' . str_replace('`', '', (string) $column) . '` = ?';
        $types .= is_int($value) ? 'i' : 's';
        $values[] = $value;
    }
    $types .= 'i';
    $values[] = $id;
    $tableEsc = '`' . str_replace('`', '', $table) . '`';
    $sql = 'UPDATE ' . $tableEsc . ' SET ' . implode(', ', $sets) . ' WHERE `' . $pk . '` = ?';
    $stmt = $worldDb->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param($types, ...$values);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/**
 * Apply overrides using case-insensitive column names (avoids PrevQuestId + PrevQuestID duplicates).
 *
 * @param array<string, scalar|null> $row
 * @param array<string, scalar|null> $overrides
 */
function sahtout_world_row_merge_overrides(array $row, array $overrides): array
{
    if ($overrides === []) {
        return $row;
    }
    $columnIndex = [];
    foreach (array_keys($row) as $column) {
        $columnIndex[strtolower((string) $column)] = $column;
    }
    foreach ($overrides as $key => $value) {
        $lookup = strtolower((string) $key);
        if (isset($columnIndex[$lookup])) {
            $row[$columnIndex[$lookup]] = $value;
        } else {
            $row[(string) $key] = $value;
        }
    }

    return $row;
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
    $row = sahtout_world_row_merge_overrides($row, $overrides);

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

function sahtout_token_gift_gift_owns_world_id(mysqli $siteDb, int $giftId, int $worldId): bool
{
    if ($giftId <= 0 || $worldId <= 0) {
        return false;
    }
    $stmt = $siteDb->prepare(
        'SELECT id FROM token_gifts WHERE id = ? AND item_entry = ? AND quest_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $giftId, $worldId, $worldId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $ok;
}

function sahtout_token_gift_world_id_claimed_by_other_gift(mysqli $siteDb, int $giftId, int $worldId): bool
{
    if ($worldId <= 0) {
        return false;
    }
    $stmt = $siteDb->prepare(
        'SELECT id FROM token_gifts WHERE id != ? AND (item_entry = ? OR quest_id = ?) LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iii', $giftId, $worldId, $worldId);
    $stmt->execute();
    $claimed = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $claimed;
}

function sahtout_token_gift_can_assign_world_id(
    mysqli $siteDb,
    mysqli $worldDb,
    int $giftId,
    int $worldId
): bool {
    if (!sahtout_token_gift_is_reserved_world_id($worldId)) {
        return false;
    }
    if (sahtout_token_gift_gift_owns_world_id($siteDb, $giftId, $worldId)) {
        return true;
    }
    if (sahtout_token_gift_world_id_claimed_by_other_gift($siteDb, $giftId, $worldId)) {
        return false;
    }
    if (sahtout_world_row_exists($worldDb, 'item_template', $worldId)) {
        return false;
    }
    if (sahtout_world_row_exists($worldDb, 'quest_template', $worldId)) {
        return false;
    }

    return true;
}

/**
 * @return array{ok:bool, message?:string, item_entry?:int, quest_id?:int}
 */
function sahtout_token_gift_resolve_world_ids(
    mysqli $siteDb,
    mysqli $worldDb,
    int $giftId,
    int $currentItemEntry,
    int $currentQuestId
): array {
    if ($giftId <= 0) {
        return ['ok' => false, 'message' => 'invalid_gift'];
    }

    if (
        sahtout_token_gift_is_reserved_world_id($currentItemEntry)
        && $currentItemEntry === $currentQuestId
        && sahtout_token_gift_gift_owns_world_id($siteDb, $giftId, $currentItemEntry)
    ) {
        return [
            'ok' => true,
            'item_entry' => $currentItemEntry,
            'quest_id' => $currentQuestId,
        ];
    }

    $first = sahtout_token_gift_world_id_first();
    $last = sahtout_token_gift_world_id_last();
    for ($worldId = $first; $worldId <= $last; $worldId++) {
        if (!sahtout_token_gift_can_assign_world_id($siteDb, $worldDb, $giftId, $worldId)) {
            continue;
        }

        return [
            'ok' => true,
            'item_entry' => $worldId,
            'quest_id' => $worldId,
        ];
    }

    return ['ok' => false, 'message' => 'world_id_slots_exhausted'];
}

/**
 * Writes item_template, quest_template, locale, and dialog rows for a published gift.
 *
 * @param array<string, mixed> $giftRow
 * @return array{ok:bool, message:string, item_entry?:int, quest_id?:int, name?:string}
 */
function sahtout_token_gift_write_published_world_templates(
    mysqli $siteDb,
    mysqli $worldDb,
    array $giftRow
): array {
    $giftId = (int) ($giftRow['id'] ?? 0);
    $itemEntry = (int) ($giftRow['item_entry'] ?? 0);
    $questId = (int) ($giftRow['quest_id'] ?? 0);
    $name = trim((string) ($giftRow['name'] ?? ''));
    if ($giftId <= 0 || $itemEntry <= 0 || $questId <= 0 || $name === '') {
        return ['ok' => false, 'message' => 'invalid'];
    }
    if ($worldDb->connect_error) {
        return ['ok' => false, 'message' => 'world_db_unavailable'];
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

    $letter = trim((string) ($giftRow['letter_text'] ?? ''));
    if ($letter === '') {
        $letter = translate('token_gift_default_letter', 'Thank you for playing on our server. Claim your shop tokens on the website after reading this letter.');
    }
    $tokens = (int) ($giftRow['tokens'] ?? 0);
    $formattedLetterBody = sahtout_token_gift_build_quest_letter_body($letter, $tokens);
    sahtout_token_gift_detach_page_text($siteDb, $worldDb, $giftId);

    if (!sahtout_world_copy_row_insert_select($worldDb, 'item_template', $itemRef, $itemEntry)) {
        return ['ok' => false, 'message' => 'item_publish_failed'];
    }
    if (!sahtout_world_apply_row_overrides(
        $worldDb,
        'item_template',
        $itemEntry,
        sahtout_token_gift_item_publish_overrides($worldDb, $itemRef, $questId, $name)
    ) || !sahtout_token_gift_finalize_item_template($worldDb, $itemEntry, $itemRef, $questId, $name)) {
        return ['ok' => false, 'message' => 'item_publish_failed'];
    }
    sahtout_token_gift_sync_item_locale($worldDb, $itemEntry, $name);

    if (!sahtout_world_copy_row_insert_select($worldDb, 'quest_template', $questRef, $questId)) {
        return ['ok' => false, 'message' => 'quest_publish_failed'];
    }
    if (!sahtout_world_apply_row_overrides(
        $worldDb,
        'quest_template',
        $questId,
        sahtout_token_gift_quest_publish_overrides($itemEntry, $name, $formattedLetterBody)
    )) {
        return ['ok' => false, 'message' => 'quest_publish_failed'];
    }

    sahtout_token_gift_ensure_quest_template_addon($worldDb, $questId, $questRef);
    sahtout_token_gift_sync_quest_dialog_tables(
        $worldDb,
        $questId,
        $questRef,
        $formattedLetterBody,
        sahtout_token_gift_quest_reward_text()
    );
    $logDescription = translate(
        'token_gift_quest_log_description',
        'Read the letter to complete this promo and claim shop tokens on the website.'
    );
    sahtout_token_gift_sync_quest_locale(
        $worldDb,
        $questId,
        $name,
        $formattedLetterBody,
        $logDescription,
        $name
    );

    return [
        'ok' => true,
        'message' => 'templates_written',
        'item_entry' => $itemEntry,
        'quest_id' => $questId,
        'name' => $name,
    ];
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
    $resolved = sahtout_token_gift_resolve_world_ids($siteDb, $worldDb, $giftId, $itemEntry, $questId);
    if (!$resolved['ok']) {
        return ['ok' => false, 'message' => $resolved['message'] ?? 'world_id_slots_exhausted'];
    }
    $itemEntry = (int) ($resolved['item_entry'] ?? 0);
    $questId = (int) ($resolved['quest_id'] ?? 0);

    $gift['item_entry'] = $itemEntry;
    $gift['quest_id'] = $questId;
    $gift['name'] = $name;
    $templateWrite = sahtout_token_gift_write_published_world_templates($siteDb, $worldDb, $gift);
    if (!$templateWrite['ok']) {
        return ['ok' => false, 'message' => $templateWrite['message'] ?? 'template_write_failed'];
    }

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

    $reload = sahtout_soap_reload_token_gift_publish();
    $expectLoot = (int) ($gift['deploy_world_chests'] ?? 0) === 1
        || trim((string) ($gift['extra_creature_entries'] ?? '')) !== ''
        || trim((string) ($gift['extra_go_loot_ids'] ?? '')) !== '';
    $verify = sahtout_token_gift_verify_world_publish(
        $worldDb,
        $giftId,
        $itemEntry,
        $questId,
        0,
        $name,
        $expectLoot
    );

    return [
        'ok' => true,
        'message' => 'published',
        'reload' => $reload,
        'verify' => $verify,
    ];
}

/**
 * @return array{ok:bool, message:string, reload?:array, verify?:array}
 */
function sahtout_token_gift_redeploy_loot(mysqli $siteDb, mysqli $worldDb, int $giftId): array
{
    $loadFull = $siteDb->prepare(
        'SELECT id, name, letter_text, tokens, item_entry, quest_id, deploy_world_chests, loot_chance,
                extra_creature_entries, extra_go_loot_ids, published_at
         FROM token_gifts WHERE id = ? LIMIT 1'
    );
    if (!$loadFull) {
        return ['ok' => false, 'message' => 'load_failed'];
    }
    $loadFull->bind_param('i', $giftId);
    $loadFull->execute();
    $giftFull = $loadFull->get_result()->fetch_assoc();
    $loadFull->close();
    if (!$giftFull || empty($giftFull['published_at'])) {
        return ['ok' => false, 'message' => 'not_published'];
    }

    $templateWrite = sahtout_token_gift_write_published_world_templates($siteDb, $worldDb, $giftFull);
    if (!$templateWrite['ok']) {
        return ['ok' => false, 'message' => $templateWrite['message'] ?? 'template_write_failed'];
    }

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

    $itemEntry = (int) ($gift['item_entry'] ?? 0);
    $questId = (int) ($giftFull['quest_id'] ?? 0);
    $name = trim((string) ($giftFull['name'] ?? ''));
    $expectLoot = (int) ($giftFull['deploy_world_chests'] ?? 0) === 1
        || trim((string) ($giftFull['extra_creature_entries'] ?? '')) !== ''
        || trim((string) ($giftFull['extra_go_loot_ids'] ?? '')) !== '';
    $verify = sahtout_token_gift_verify_world_publish(
        $worldDb,
        $giftId,
        $itemEntry,
        $questId,
        0,
        $name,
        $expectLoot
    );

    return [
        'ok' => true,
        'message' => 'loot_deployed',
        'reload' => sahtout_soap_merge_reload_results(
            sahtout_soap_reload_token_gift_content(),
            sahtout_soap_reload_token_gift_loot()
        ),
        'verify' => $verify,
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

    $templateWrite = sahtout_token_gift_write_published_world_templates($siteDb, $worldDb, [
        'id' => $giftId,
        'item_entry' => $itemEntry,
        'quest_id' => $questId,
        'name' => $name,
        'letter_text' => $letter,
        'tokens' => $tokens,
    ]);
    if (!$templateWrite['ok']) {
        return ['ok' => false, 'message' => $templateWrite['message'] ?? 'update_failed'];
    }

    return [
        'ok' => true,
        'message' => 'updated',
        'reload' => sahtout_soap_reload_token_gift_content(),
    ];
}
