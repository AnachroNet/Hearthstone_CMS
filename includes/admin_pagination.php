<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/**
 * Page numbers to show in the compact admin pager (default: current ± 1).
 *
 * @return list<int>
 */
function sahtout_admin_pagination_page_range(int $currentPage, int $totalPages, int $linkCount = 3): array
{
    if ($totalPages <= 1) {
        return [];
    }

    $currentPage = max(1, min($currentPage, $totalPages));
    $linkCount = max(1, $linkCount);
    $start = max(1, $currentPage - (int) floor(($linkCount - 1) / 2));
    $end = min($totalPages, $start + $linkCount - 1);
    $start = max(1, $end - $linkCount + 1);

    return range($start, $end);
}

/**
 * @param array<string, scalar|null> $query
 */
function sahtout_admin_pagination_build_url(string $basePath, string $route, array $query, int $page, string $pageParam = 'page'): string
{
    $q = [];
    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $q[$key] = $value;
    }
    if ($page > 1) {
        $q[$pageParam] = $page;
    }

    $path = rtrim($basePath, '/') . '/' . ltrim($route, '/');
    if ($q === []) {
        return $path;
    }

    return $path . '?' . http_build_query($q);
}

/**
 * @param array{
 *   current_page: int,
 *   total_pages: int,
 *   base_path: string,
 *   route: string,
 *   query?: array<string, scalar|null>,
 *   page_param?: string,
 *   theme?: 'iron'|'game',
 *   aria_label?: string,
 *   nav_class?: string,
 *   prev_label?: string,
 *   next_label?: string,
 *   link_count?: int,
 * } $options
 */
function sahtout_admin_render_pagination_nav(array $options): void
{
    $currentPage = max(1, (int) ($options['current_page'] ?? 1));
    $totalPages = max(1, (int) ($options['total_pages'] ?? 1));
    if ($totalPages <= 1) {
        return;
    }

    $basePath = (string) ($options['base_path'] ?? '');
    $route = (string) ($options['route'] ?? '');
    $query = $options['query'] ?? [];
    $pageParam = (string) ($options['page_param'] ?? 'page');
    $theme = ($options['theme'] ?? 'iron') === 'game' ? 'game' : 'iron';
    $ariaLabel = (string) ($options['aria_label'] ?? translate('admin_pagination_aria', 'Page navigation'));
    $navClass = (string) ($options['nav_class'] ?? 'flex justify-center gap-2 mt-6 md:mt-8 flex-wrap');
    $prevLabel = (string) ($options['prev_label'] ?? translate('admin_pagination_previous', 'Previous'));
    $nextLabel = (string) ($options['next_label'] ?? translate('admin_pagination_next', 'Next'));
    $linkCount = (int) ($options['link_count'] ?? 3);

    $inactiveClass = $theme === 'game'
        ? 'btn-game btn-iron py-2 px-3 md:px-4 text-xs'
        : 'btn-iron btn-iron-sm';
    $activeClass = $theme === 'game'
        ? 'btn-game btn-gold py-2 px-3 md:px-4 text-xs cursor-default'
        : 'btn-gold btn-iron-sm cursor-default';

    $pageNumbers = sahtout_admin_pagination_page_range($currentPage, $totalPages, $linkCount);

    echo '<nav class="' . htmlspecialchars($navClass, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '">';

    if ($currentPage > 1) {
        $prevUrl = sahtout_admin_pagination_build_url($basePath, $route, $query, $currentPage - 1, $pageParam);
        echo '<a href="' . htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($inactiveClass, ENT_QUOTES, 'UTF-8') . '">';
        echo '<i class="fas fa-chevron-left"></i> ' . htmlspecialchars($prevLabel);
        echo '</a>';
    }

    foreach ($pageNumbers as $i) {
        if ($i === $currentPage) {
            echo '<span class="' . htmlspecialchars($activeClass, ENT_QUOTES, 'UTF-8') . '">' . (int) $i . '</span>';
            continue;
        }
        $pageUrl = sahtout_admin_pagination_build_url($basePath, $route, $query, $i, $pageParam);
        echo '<a href="' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($inactiveClass, ENT_QUOTES, 'UTF-8') . '">' . (int) $i . '</a>';
    }

    if ($currentPage < $totalPages) {
        $nextUrl = sahtout_admin_pagination_build_url($basePath, $route, $query, $currentPage + 1, $pageParam);
        echo '<a href="' . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($inactiveClass, ENT_QUOTES, 'UTF-8') . '">';
        echo htmlspecialchars($nextLabel) . ' <i class="fas fa-chevron-right"></i>';
        echo '</a>';
    }

    echo '</nav>';
}
