<?php

/**
 * WotLK client DBC (WDBC) helpers — shared by site features and CLI import tools.
 */

function sahtout_find_dbc_file(string $dir, string $fileName): ?string
{
    $path = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (is_readable($path)) {
        return $path;
    }
    if (!is_dir($dir)) {
        return null;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (strcasecmp($entry, $fileName) === 0) {
            $match = $dir . DIRECTORY_SEPARATOR . $entry;
            return is_readable($match) ? $match : null;
        }
    }
    return null;
}

/** Default folder: Web/SahtoutCMS/data/dbc (override with TALENT_DBC_DIR in config.local.php). */
function sahtout_data_dbc_dir(): ?string
{
    $candidates = [];
    if (defined('TALENT_DBC_DIR') && TALENT_DBC_DIR !== '') {
        $candidates[] = rtrim(TALENT_DBC_DIR, '/\\');
    }
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'dbc';

    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
    }

    return null;
}

function sahtout_read_wdbc_records(string $path): array
{
    $blob = @file_get_contents($path);
    if ($blob === false || strlen($blob) < 20) {
        throw new RuntimeException('Unreadable DBC: ' . basename($path));
    }
    $magic = substr($blob, 0, 4);
    if ($magic !== 'WDBC') {
        throw new RuntimeException('Not WDBC: ' . basename($path));
    }
    $header = unpack('Vrec/Vfield/VrecSize/VstrSize', substr($blob, 4, 16));
    $nRec = (int) $header['rec'];
    $nField = (int) $header['field'];
    $recSize = (int) $header['recSize'];
    $strSize = (int) $header['strSize'];
    $body = 20;
    $strings = substr($blob, $body + $nRec * $recSize, $strSize);
    $records = [];
    for ($i = 0; $i < $nRec; $i++) {
        $offset = $body + $i * $recSize;
        $chunk = substr($blob, $offset, $recSize);
        $vals = unpack('V' . $nField, $chunk);
        $records[] = array_values($vals);
    }
    return [$records, $strings];
}

/** WDBC stores int32 fields as little-endian uint32; convert negatives (e.g. -1) correctly. */
function sahtout_dbc_int32(int $raw): int
{
    if ($raw >= 0x80000000) {
        return $raw - 0x100000000;
    }

    return $raw;
}

/** Drop invalid DBC/world map ids before SQL (signed INT columns reject 4294967295). */
function sahtout_sanitize_map_id(?int $mapId): ?int
{
    if ($mapId === null || $mapId <= 0) {
        return null;
    }
    if ($mapId >= 0x80000000) {
        return null;
    }
    if ($mapId > 2000) {
        return null;
    }

    return $mapId;
}

function sahtout_dbc_string(string $strings, int $offset): string
{
    if ($offset < 0 || $offset >= strlen($strings)) {
        return '';
    }
    $end = strpos($strings, "\0", $offset);
    if ($end === false) {
        $end = strlen($strings);
    }
    return substr($strings, $offset, $end - $offset);
}

function sahtout_sql_escape(string $value): string
{
    return str_replace(
        ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
        ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
        $value
    );
}
