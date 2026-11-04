<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_token_gifts.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_shop_rewards_post.php';

/** @param array{configured?:bool, ok?:bool} $reload */
function sahtout_admin_token_gift_reload_query_params(array $reload): array
{
    if ($reload === [] || !array_key_exists('configured', $reload)) {
        return [];
    }
    if (empty($reload['configured'])) {
        return ['tg_reload' => 'none'];
    }

    return ['tg_reload' => !empty($reload['ok']) ? 'ok' : 'fail'];
}

function sahtout_admin_shop_is_token_gifts_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    $action = (string) ($_POST['action'] ?? '');

    return str_starts_with($action, 'tg_');
}

/**
 * @return array{redirect: ?string, flash: string}
 */
function sahtout_admin_shop_token_gifts_handle_post(mysqli $siteDb, ?mysqli $worldDb, string $basePath): array
{
    if (!sahtout_token_gifts_table_exists($siteDb)) {
        return [
            'redirect' => null,
            'flash' => 'error:' . translate(
                'admin_token_gifts_table_missing',
                'Run token_gifts.sql on the website database first.'
            ),
        ];
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        return ['redirect' => null, 'flash' => 'error:' . translate('error_csrf_invalid', 'Invalid CSRF token.')];
    }

    $action = (string) ($_POST['action'] ?? '');
    $subtabUrl = static fn (array $extra = []): string => sahtout_admin_shop_rewards_tab_url(
        $basePath,
        array_merge(['subtab' => 'token_gifts'], $extra)
    );

    if ($action === 'tg_add') {
        $result = sahtout_admin_add_token_gift(
            $siteDb,
            (string) ($_POST['tg_name'] ?? ''),
            (string) ($_POST['tg_letter'] ?? ''),
            (int) ($_POST['tg_tokens'] ?? 0),
            isset($_POST['tg_deploy_chests']),
            (float) ($_POST['tg_loot_chance'] ?? 2),
            trim((string) ($_POST['tg_creature_entries'] ?? '')),
            trim((string) ($_POST['tg_go_loot_ids'] ?? ''))
        );
        if (!$result['ok']) {
            $msg = match ($result['message'] ?? '') {
                'invalid_tokens' => translate('admin_token_gifts_invalid_tokens', 'Enter a token amount between 1 and 9,999,999.'),
                default => translate('admin_token_gifts_add_failed', 'Could not add token gift.'),
            };

            return ['redirect' => null, 'flash' => 'error:' . $msg];
        }

        return ['redirect' => $subtabUrl(['tg_status' => 'added']), 'flash' => ''];
    }

    if ($action === 'tg_update') {
        $giftId = (int) ($_POST['gift_id'] ?? 0);
        $result = sahtout_admin_update_token_gift(
            $siteDb,
            $worldDb,
            $giftId,
            (string) ($_POST['tg_name'] ?? ''),
            (string) ($_POST['tg_letter'] ?? ''),
            (int) ($_POST['tg_tokens'] ?? 0),
            isset($_POST['tg_enabled']),
            isset($_POST['tg_deploy_chests']),
            (float) ($_POST['tg_loot_chance'] ?? 2),
            trim((string) ($_POST['tg_creature_entries'] ?? '')),
            trim((string) ($_POST['tg_go_loot_ids'] ?? ''))
        );
        if (!$result['ok']) {
            $msg = match ($result['message'] ?? '') {
                'page_text_table_missing' => translate(
                    'admin_token_gifts_page_text_missing',
                    'World database is missing the page_text table.'
                ),
                'page_text_write_failed', 'page_text_sync_failed', 'page_text_id_allocate_failed', 'page_text_site_save_failed', 'world_update_failed' => translate(
                    'admin_token_gifts_page_text_failed',
                    'Could not write page_text rows for the letter body.'
                ),
                default => translate('admin_token_gifts_save_failed', 'Could not save token gift.'),
            };

            return ['redirect' => null, 'flash' => 'error:' . $msg];
        }

        $query = array_merge(['tg_status' => 'updated'], sahtout_admin_token_gift_reload_query_params($result['reload'] ?? []));

        return ['redirect' => $subtabUrl($query), 'flash' => ''];
    }

    if ($action === 'tg_delete') {
        if (!sahtout_admin_can_publish_token_gifts()) {
            return [
                'redirect' => null,
                'flash' => 'error:' . translate('admin_token_gifts_admin_only', 'Only administrators can publish or remove world content.'),
            ];
        }
        $giftId = (int) ($_POST['gift_id'] ?? 0);
        $result = sahtout_admin_delete_token_gift($siteDb, $worldDb, $giftId);
        if (!$result['ok']) {
            return [
                'redirect' => null,
                'flash' => 'error:' . translate('admin_token_gifts_delete_failed', 'Could not delete token gift.'),
            ];
        }

        $query = array_merge(['tg_status' => 'deleted'], sahtout_admin_token_gift_reload_query_params($result['reload'] ?? []));

        return ['redirect' => $subtabUrl($query), 'flash' => ''];
    }

    if ($action === 'tg_publish' || $action === 'tg_redeploy_loot') {
        if (!sahtout_admin_can_publish_token_gifts()) {
            return [
                'redirect' => null,
                'flash' => 'error:' . translate('admin_token_gifts_admin_only', 'Only administrators can publish or remove world content.'),
            ];
        }
        if (!($worldDb instanceof mysqli) || $worldDb->connect_error) {
            return [
                'redirect' => null,
                'flash' => 'error:' . translate('admin_token_gifts_world_db', 'World database connection is not available.'),
            ];
        }
        $giftId = (int) ($_POST['gift_id'] ?? 0);
        if ($action === 'tg_publish') {
            $result = sahtout_token_gift_publish_to_world($siteDb, $worldDb, $giftId);
        } else {
            $result = sahtout_token_gift_redeploy_loot($siteDb, $worldDb, $giftId);
        }
        if (!$result['ok']) {
            $msg = match ($result['message'] ?? '') {
                'item_ref_missing' => translate(
                    'admin_token_gifts_item_ref_missing',
                    'Reference item is missing in the world database. Set shop.token_gift_item_template_ref in site settings.'
                ),
                'quest_ref_missing' => translate(
                    'admin_token_gifts_quest_ref_missing',
                    'Reference quest is missing. Set shop.token_gift_quest_template_ref in site settings.'
                ),
                'not_published' => translate('admin_token_gifts_not_published', 'Publish the gift to the world before redeploying loot.'),
                'page_text_table_missing' => translate(
                    'admin_token_gifts_page_text_missing',
                    'World database is missing the page_text table.'
                ),
                'page_text_write_failed', 'page_text_sync_failed', 'page_text_id_allocate_failed', 'page_text_site_save_failed' => translate(
                    'admin_token_gifts_page_text_failed',
                    'Could not write page_text rows for the letter body.'
                ),
                default => translate('admin_token_gifts_publish_failed', 'World publish or loot deploy failed.'),
            };

            return ['redirect' => null, 'flash' => 'error:' . $msg];
        }

        $status = $action === 'tg_publish' ? 'published' : 'loot_redeployed';
        $query = array_merge(['tg_status' => $status], sahtout_admin_token_gift_reload_query_params($result['reload'] ?? []));

        return ['redirect' => $subtabUrl($query), 'flash' => ''];
    }

    return ['redirect' => null, 'flash' => ''];
}

function sahtout_admin_shop_token_gifts_status_flash_from_get(): string
{
    $status = (string) ($_GET['tg_status'] ?? '');
    if ($status === '') {
        return '';
    }

    $reload = (string) ($_GET['tg_reload'] ?? '');

    return match ($status) {
        'added' => 'success:' . translate('admin_token_gifts_added', 'Token gift created.'),
        'updated' => 'success:' . sahtout_admin_token_gift_flash_with_reload(
            translate('admin_token_gifts_updated', 'Token gift saved.'),
            $reload
        ),
        'deleted' => 'success:' . sahtout_admin_token_gift_flash_with_reload(
            translate('admin_token_gifts_deleted', 'Token gift removed.'),
            $reload
        ),
        'published' => 'success:' . sahtout_admin_token_gift_flash_with_reload(
            translate('admin_token_gifts_published', 'Published to world and enabled for website grants.'),
            $reload
        ),
        'loot_redeployed' => 'success:' . sahtout_admin_token_gift_flash_with_reload(
            translate('admin_token_gifts_loot_redeployed', 'Chest and creature loot rows updated.'),
            $reload
        ),
        default => '',
    };
}

function sahtout_admin_token_gift_flash_with_reload(string $baseMessage, string $reloadFlag): string
{
    return match ($reloadFlag) {
        'ok' => $baseMessage . ' ' . translate(
            'admin_token_gifts_reload_ok',
            'Worldserver templates reloaded via SOAP.'
        ),
        'fail' => $baseMessage . ' ' . translate(
            'admin_token_gifts_reload_failed',
            'Database changes saved, but automatic reload via SOAP failed. Check Admin → Settings → SOAP and worldserver logs.'
        ),
        'none' => $baseMessage . ' ' . translate(
            'admin_token_gifts_reload_none',
            'Configure SOAP under Admin → Settings → SOAP to reload the worldserver without using the command line.'
        ),
        default => $baseMessage,
    };
}
