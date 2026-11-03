<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** Build a URL-safe slug from a news title (max 80 chars). */
function sahtout_news_slugify(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'news';
    }
    if (strlen($slug) > 80) {
        $slug = rtrim(substr($slug, 0, 80), '-');
    }

    return $slug;
}

function sahtout_news_slug_is_taken(mysqli $site_db, string $slug, ?int $excludeId = null): bool
{
    if ($slug === '') {
        return false;
    }
    if ($excludeId !== null && $excludeId > 0) {
        $stmt = $site_db->prepare('SELECT id FROM server_news WHERE slug = ? AND id <> ? LIMIT 1');
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param('si', $slug, $excludeId);
    } else {
        $stmt = $site_db->prepare('SELECT id FROM server_news WHERE slug = ? LIMIT 1');
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param('s', $slug);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $taken = $res && $res->num_rows > 0;
    $res?->free();
    $stmt->close();

    return $taken;
}

/** Unique slug for insert/update; generates from title when $slug is empty. */
function sahtout_news_resolve_slug(mysqli $site_db, string $title, string $slug, ?int $excludeId = null): string
{
    $slug = trim($slug);
    if ($slug === '') {
        $slug = sahtout_news_slugify($title);
    } else {
        $slug = sahtout_news_slugify($slug);
    }

    $base = $slug;
    $n = 2;
    while (sahtout_news_slug_is_taken($site_db, $slug, $excludeId)) {
        $suffix = '-' . $n;
        $maxBase = max(1, 80 - strlen($suffix));
        $slug = rtrim(substr($base, 0, $maxBase), '-') . $suffix;
        $n++;
    }

    return $slug;
}

/** Public link to a single news row (slug preferred, id fallback). */
function sahtout_news_article_href(array $news, string $base_path): string
{
    $slug = trim((string) ($news['slug'] ?? ''));
    if ($slug !== '') {
        return $base_path . 'news?slug=' . rawurlencode($slug);
    }
    $id = (int) ($news['id'] ?? 0);
    if ($id > 0) {
        return $base_path . 'news?id=' . $id;
    }

    return $base_path . 'news';
}

/** Resolve a server_news image_url for use in img src (handles absolute URLs). */
function sahtout_news_image_src(string $image_url, string $base_path, string $default_relative = 'img/newsimg/news.png'): string
{
    $image_url = trim($image_url);
    if ($image_url === '') {
        return $base_path . ltrim($default_relative, '/');
    }
    if (preg_match('#^(https?:)?//#i', $image_url) || str_starts_with($image_url, 'data:')) {
        return $image_url;
    }

    return $base_path . ltrim($image_url, '/');
}

/** Inline style for home page news thumbnails (works even if stylesheets fail to load). */
function sahtout_news_home_thumbnail_style(): string
{
    return 'display:block;width:100%;max-width:100%;height:10rem;object-fit:cover;object-position:center;background-color:rgba(0,0,0,0.35);';
}

/** Inline style for a full news article hero image. */
function sahtout_news_single_image_style(): string
{
    return 'display:block;width:100%;max-width:100%;max-height:400px;height:auto;object-fit:contain;object-position:center;margin:0 auto;';
}
