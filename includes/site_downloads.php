<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

require_once __DIR__ . '/site_settings.php';

function sahtout_download_categories(): array
{
    return [
        'client' => translate('download_cat_client', 'Game client'),
        'launcher' => translate('download_cat_launcher', 'Launcher'),
        'patch' => translate('download_cat_patch', 'Patch / update'),
        'addon' => translate('download_cat_addon', 'Addon'),
        'tool' => translate('download_cat_tool', 'Tool'),
        'other' => translate('download_cat_other', 'Other'),
    ];
}

function sahtout_download_category_order(): array
{
    return ['client', 'launcher', 'patch', 'addon', 'tool', 'other'];
}

function sahtout_normalize_download_category(string $category): string
{
    $categories = sahtout_download_categories();

    return array_key_exists($category, $categories) ? $category : 'other';
}

function sahtout_downloads_table_exists(mysqli $site_db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $site_db->query("SHOW TABLES LIKE 'site_downloads'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

function sahtout_normalize_download_url(string $url): string
{
    return sahtout_normalize_external_url($url);
}

/**
 * @return list<array<string, mixed>>
 */
function sahtout_fetch_downloads(mysqli $site_db, bool $activeOnly = true): array
{
    if (!sahtout_downloads_table_exists($site_db)) {
        return [];
    }

    $sql = 'SELECT id, title, description, url, category, size_label, sort_order, is_active, created_at, updated_at
            FROM site_downloads';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $res = $site_db->query($sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_active'] = (int) $row['is_active'] === 1;
        $row['category'] = sahtout_normalize_download_category((string) $row['category']);
        $rows[] = $row;
    }
    $res->free();

    return $rows;
}

/**
 * @return array<string, list<array<string, mixed>>>
 */
function sahtout_group_downloads_by_category(array $downloads): array
{
    $grouped = [];
    foreach (sahtout_download_category_order() as $key) {
        $grouped[$key] = [];
    }
    foreach ($downloads as $row) {
        $cat = $row['category'] ?? 'other';
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = [];
        }
        $grouped[$cat][] = $row;
    }

    return $grouped;
}

function sahtout_download_category_icon(string $category): string
{
    return match ($category) {
        'client' => 'fa-compact-disc',
        'launcher' => 'fa-rocket',
        'patch' => 'fa-wrench',
        'addon' => 'fa-puzzle-piece',
        'tool' => 'fa-toolbox',
        default => 'fa-link',
    };
}
