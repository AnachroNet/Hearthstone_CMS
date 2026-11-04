<?php

if (!defined('ALLOWED_ACCESS')) {
    define('ALLOWED_ACCESS', true);
}

require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';

$tc_lang_file = __DIR__ . '/../languages/en/talent-calculator_index.php';
if (is_readable($tc_lang_file)) {
    $tcLang = include $tc_lang_file;
    if (is_array($tcLang)) {
        $lang = array_merge($lang, $tcLang);
    }
}

$tc_web_base = rtrim($base_path, '/') . '/talent-calculator/';
if ($tc_web_base === '/talent-calculator/') {
    $tc_web_base = '/talent-calculator/';
}

/**
 * @return list<array{slug: string, dir: string, pageId: string, class_key: string}>
 */
function sahtout_talent_calculator_classes(): array
{
    return [
        ['slug' => 'deathknight', 'dir' => 'Deathknight', 'pageId' => 'deathknight', 'class_key' => 'class_dk'],
        ['slug' => 'druid', 'dir' => 'Druid', 'pageId' => 'Druid', 'class_key' => 'class_druid'],
        ['slug' => 'hunter', 'dir' => 'Hunter', 'pageId' => 'Hunter', 'class_key' => 'class_hunter'],
        ['slug' => 'mage', 'dir' => 'Mage', 'pageId' => 'Mage', 'class_key' => 'class_mage'],
        ['slug' => 'paladin', 'dir' => 'Paladin', 'pageId' => 'Paladin', 'class_key' => 'class_paladin'],
        ['slug' => 'priest', 'dir' => 'Priest', 'pageId' => 'Priest', 'class_key' => 'class_priest'],
        ['slug' => 'rogue', 'dir' => 'Rogue', 'pageId' => 'Rogue', 'class_key' => 'class_rogue'],
        ['slug' => 'shaman', 'dir' => 'Shaman', 'pageId' => 'Shaman', 'class_key' => 'class_shaman'],
        ['slug' => 'warlock', 'dir' => 'Warlock', 'pageId' => 'Warlock', 'class_key' => 'class_warlock'],
        ['slug' => 'warrior', 'dir' => 'Warrior', 'pageId' => 'Warrior', 'class_key' => 'class_warrior'],
    ];
}

function sahtout_talent_calculator_class_by_slug(string $slug): ?array
{
    foreach (sahtout_talent_calculator_classes() as $class) {
        if ($class['slug'] === $slug) {
            return $class;
        }
    }

    return null;
}
