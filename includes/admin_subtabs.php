<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/**
 * @param list<string> $allowed
 */
function sahtout_admin_resolve_subtab(array $allowed, string $default): string
{
    $raw = isset($_GET['subtab']) ? (string) $_GET['subtab'] : $default;

    return in_array($raw, $allowed, true) ? $raw : $default;
}

function sahtout_admin_page_url(string $basePath, string $route, array $query = []): string
{
    if ($query === []) {
        return $basePath . $route;
    }

    return $basePath . $route . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/**
 * @param list<array{id: string, label: string, icon?: string}> $tabs
 * @param array<string, scalar|null> $persistQuery
 */
function sahtout_admin_render_subtab_nav(
    array $tabs,
    string $activeSubtab,
    string $basePath,
    string $route,
    array $persistQuery = [],
    ?string $ariaLabel = null,
    string $subtabQueryKey = 'subtab'
): void {
    echo '<div class="border-b border-[rgba(201,162,39,.12)] mb-4 md:mb-6">';
    echo '<nav class="flex gap-0 overflow-x-auto"';
    if ($ariaLabel !== null && $ariaLabel !== '') {
        echo ' aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '"';
    }
    echo '>';
    foreach ($tabs as $tab) {
        $id = (string) ($tab['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $query = $persistQuery;
        $query[$subtabQueryKey] = $id;
        $href = sahtout_admin_page_url($basePath, $route, $query);
        $activeClass = $activeSubtab === $id ? ' active' : '';
        echo '<a class="tab-link sub-tab-link whitespace-nowrap' . $activeClass . '" href="'
            . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
        if (!empty($tab['icon'])) {
            echo '<i class="' . htmlspecialchars((string) $tab['icon'], ENT_QUOTES, 'UTF-8') . ' mr-2"></i>';
        }
        echo htmlspecialchars((string) ($tab['label'] ?? $id), ENT_QUOTES, 'UTF-8');
        echo '</a>';
    }
    echo '</nav></div>';
}

function sahtout_admin_subtab_panel_class(string $subtabId, string $activeSubtab, string $extra = ''): string
{
    $active = $subtabId === $activeSubtab ? ' active' : '';
    $extra = trim($extra);

    return 'admin-subtab' . $active . ($extra !== '' ? ' ' . $extra : '');
}
