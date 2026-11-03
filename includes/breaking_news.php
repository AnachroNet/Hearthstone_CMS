<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** @return array<string, string> label => |cAARRGGBB prefix (without |r) */
function sahtout_breaking_news_color_presets(): array
{
    return [
        'gold'   => '|cFFFFCC00',
        'red'    => '|cFFFF0000',
        'green'  => '|cFF00FF00',
        'blue'   => '|cFF0088FF',
        'yellow' => '|cFFFFFF00',
        'gray'   => '|cFF888888',
        'orange' => '|cFFFF8800',
    ];
}

function sahtout_breaking_news_table_exists(?mysqli $worldDb): bool
{
    if (!$worldDb instanceof mysqli || $worldDb->connect_error) {
        return false;
    }
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $worldDb->query("SHOW TABLES LIKE 'mod_breaking_news'");
    $exists = $res && $res->num_rows > 0;
    $res?->free();

    return $exists;
}

/**
 * @return array<string, mixed>|null
 */
function sahtout_breaking_news_get(?mysqli $worldDb): ?array
{
    if (!sahtout_breaking_news_table_exists($worldDb)) {
        return null;
    }
    $res = $worldDb->query(
        'SELECT id, title, body_source, body_html, enabled, updated_at, updated_by
         FROM mod_breaking_news WHERE id = 1 LIMIT 1'
    );
    if (!$res) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();

    return $row ?: null;
}

function sahtout_breaking_news_escape_preserve_color_codes(string $text): string
{
    if ($text === '') {
        return '';
    }
    $parts = preg_split('/(\|c[0-9A-Fa-f]{8}|\|r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    $out = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match('/^\|c[0-9A-Fa-f]{8}$/', $part) || $part === '|r') {
            $out .= $part;
        } else {
            $out .= htmlspecialchars($part, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }
    }

    return $out;
}

function sahtout_breaking_news_format_inline(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text);
    if ($lines === false) {
        return sahtout_breaking_news_escape_preserve_color_codes($text);
    }
    $chunks = [];
    foreach ($lines as $i => $line) {
        if ($i > 0) {
            $chunks[] = '<br/>';
        }
        $chunks[] = sahtout_breaking_news_escape_preserve_color_codes($line);
    }

    return implode('', $chunks);
}

function sahtout_breaking_news_compile_html(string $bodySource): string
{
    $bodySource = str_replace("\r\n", "\n", trim($bodySource));
    if ($bodySource === '') {
        return '<html><body></body></html>';
    }

    $paragraphs = preg_split("/\n\s*\n/", $bodySource);
    if ($paragraphs === false) {
        $paragraphs = [$bodySource];
    }

    $blocks = ['<html><body>'];
    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') {
            continue;
        }
        if (preg_match('/^(#{1,3})\s+([\s\S]*)$/', $para, $m)) {
            $level = strlen($m[1]);
            $tag = 'h' . (string) min(3, max(1, $level));
            $blocks[] = '<' . $tag . '>' . sahtout_breaking_news_format_inline(trim($m[2])) . '</' . $tag . '>';
            continue;
        }
        $blocks[] = '<p>' . sahtout_breaking_news_format_inline($para) . '</p>';
    }
    $blocks[] = '</body></html>';

    return implode('', $blocks);
}

/**
 * @return array{ok: bool, error?: string, body_html?: string}
 */
function sahtout_breaking_news_validate_and_compile(string $title, string $bodySource): array
{
    $title = trim($title);
    if ($title === '' || strlen($title) > 128) {
        return ['ok' => false, 'error' => 'invalid_title'];
    }

    $bodySource = str_replace("\r\n", "\n", $bodySource);
    if (strlen($bodySource) > 12000) {
        return ['ok' => false, 'error' => 'body_too_long'];
    }

    if (preg_match('/<html|<body|<script/i', $bodySource)) {
        return ['ok' => false, 'error' => 'raw_html_not_allowed'];
    }

    if (preg_match_all('/\|c[0-9A-Fa-f]{8}/', $bodySource, $opens) !== preg_match_all('/\|r/', $bodySource, $closes)) {
        // Allow imbalance only if no color codes at all
        if (str_contains($bodySource, '|c')) {
            return ['ok' => false, 'error' => 'unbalanced_color_codes'];
        }
    }

    $bodyHtml = sahtout_breaking_news_compile_html($bodySource);
    if (strlen($bodyHtml) > 65000) {
        return ['ok' => false, 'error' => 'compiled_too_long'];
    }

    return ['ok' => true, 'body_html' => $bodyHtml];
}

/**
 * @return array{ok: bool, error?: string}
 */
function sahtout_breaking_news_save(
    ?mysqli $worldDb,
    string $title,
    string $bodySource,
    bool $enabled,
    string $updatedBy
): array {
    if (!sahtout_breaking_news_table_exists($worldDb)) {
        return ['ok' => false, 'error' => 'table_missing'];
    }

    $compiled = sahtout_breaking_news_validate_and_compile($title, $bodySource);
    if (!$compiled['ok']) {
        return ['ok' => false, 'error' => $compiled['error'] ?? 'invalid'];
    }

    $bodyHtml = $compiled['body_html'];
    $enabledInt = $enabled ? 1 : 0;
    $updatedBy = substr(trim($updatedBy), 0, 64);

    $stmt = $worldDb->prepare(
        'INSERT INTO mod_breaking_news (id, title, body_source, body_html, enabled, updated_by)
         VALUES (1, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           title = VALUES(title),
           body_source = VALUES(body_source),
           body_html = VALUES(body_html),
           enabled = VALUES(enabled),
           updated_by = VALUES(updated_by)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'db_prepare'];
    }
    $stmt->bind_param('sssis', $title, $bodySource, $bodyHtml, $enabledInt, $updatedBy);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'db_execute'];
}

/** Approximate web preview HTML from body source + title. */
function sahtout_breaking_news_preview_html(string $title, string $bodySource): string
{
    $title = trim($title);
    $inner = '';
    if ($title !== '') {
        $inner .= '<h1 class="bn-preview-h1">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    }
    $doc = sahtout_breaking_news_compile_html($bodySource);
    if (preg_match('/<body>([\s\S]*)<\/body>/i', $doc, $m)) {
        $inner .= sahtout_breaking_news_color_codes_to_spans($m[1]);
    }

    return $inner;
}

function sahtout_breaking_news_color_codes_to_spans(string $html): string
{
    return preg_replace_callback(
        '/\|c([0-9A-Fa-f]{8})(.*?)\|r/s',
        static function (array $m): string {
            $hex = substr($m[1], 2);
            if (strlen($hex) === 6) {
                $color = '#' . $hex;
            } else {
                $color = '#ffffff';
            }
            $text = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');

            return '<span style="color:' . $color . '">' . $text . '</span>';
        },
        $html
    ) ?? htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
}

/** Body markup for web (no document wrapper, no duplicate title heading). */
function sahtout_breaking_news_render_body_web(string $bodySource): string
{
    $doc = sahtout_breaking_news_compile_html($bodySource);
    if (!preg_match('/<body>([\s\S]*)<\/body>/i', $doc, $m)) {
        return '';
    }
    $inner = sahtout_breaking_news_color_codes_to_spans($m[1]);
    // Compiled from admin source only; spans carry inline colors (strip_tags would drop style).
    $inner = preg_replace('/<(script|iframe|object|embed|form|input|link|meta)\b[^>]*>/i', '', $inner) ?? $inner;

    return $inner;
}

/**
 * Active in-game breaking news for public display (home sidebar, etc.).
 *
 * @return array{title: string, body_html: string}|null
 */
function sahtout_breaking_news_fetch_for_display(?mysqli $worldDb): ?array
{
    $row = sahtout_breaking_news_get($worldDb);
    if ($row === null || empty($row['enabled'])) {
        return null;
    }

    $title = trim((string) ($row['title'] ?? ''));
    $bodySource = (string) ($row['body_source'] ?? '');
    $bodyWeb = sahtout_breaking_news_render_body_web($bodySource);

    if ($bodyWeb === '' && $title === '') {
        return null;
    }

    if ($title === '') {
        $title = 'Breaking News';
    }

    return [
        'title' => $title,
        'body_html' => $bodyWeb,
    ];
}
