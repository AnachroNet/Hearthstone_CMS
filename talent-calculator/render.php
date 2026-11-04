<?php

require_once __DIR__ . '/_init.php';

/** @var string $tc_slug Set by class entry script before this file is loaded. */
if (!isset($tc_slug) || $tc_slug === '') {
    header('HTTP/1.1 404 Not Found');
    exit('Class not found.');
}

$class = sahtout_talent_calculator_class_by_slug($tc_slug);
if ($class === null) {
    header('HTTP/1.1 404 Not Found');
    exit('Class not found.');
}

$tc_dir = $class['dir'];
$tc_pageId = $class['pageId'];
$tc_active_slug = $tc_slug;

$page_class = 'talent_calculator';
$page_body_class = 'page-talent-calculator';
$class_label = translate($class['class_key'], $class['dir']);
$page_title = $site_title_name . ' ' . translate('tc_page_title_class', 'Talent Calculator') . ' — ' . $class_label;
$page_meta_description = translate('tc_meta_description', 'Plan your WotLK 3.3.5 talent build and share the link with others.');
$page_meta_robots = 'index';

$tc_css = $tc_web_base . 'talents/' . $tc_dir . '/talent.css';
$tc_script_base = $tc_web_base . 'talents/' . $tc_dir . '/';
$tc_public_url = rtrim($site_url, '/') . '/talent-calculator/' . rawurlencode($tc_slug) . '.php';
$page_document_base = $tc_web_base;

ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($tc_css, ENT_QUOTES, 'UTF-8'); ?>">
<style>
    body.page-talent-calculator {
        min-height: 100vh;
        padding-top: 112px;
        margin: 0;
        color: #d8dbe2;
        background: #05070b;
        background-image:
            radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.14), transparent 65%),
            radial-gradient(800px 600px at 105% 20%, rgba(201,162,39,.08), transparent 70%),
            linear-gradient(180deg, #0a0e16 0%, #060810 45%, #03040a 100%);
        background-attachment: fixed;
    }
    .tc-content { position: relative; z-index: 1; }
    .tc-glass {
        background: rgba(5, 7, 11, 0.88);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1px solid rgba(201,162,39,.22);
        box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.8), inset 0 0 60px rgba(0,0,0,.25);
        padding: 1.25rem 1rem 2rem;
        position: relative;
    }
    .tc-glass::before {
        content: '';
        position: absolute;
        inset: 5px;
        border: 1px solid rgba(201,162,39,.14);
        pointer-events: none;
    }
    .tc-page-title {
        font-family: 'Cinzel', serif;
        font-weight: 800;
        color: #f2cf5b;
        text-align: center;
        text-shadow: 0 0 12px rgba(201,162,39,.35);
        margin: 0 0 0.35rem;
    }
    .tc-intro { text-align: center; color: #9ca3af; font-size: 0.875rem; margin: 0 0 1rem; }
    .tc-class-nav {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 0.35rem 0.5rem;
        margin-bottom: 1.25rem;
    }
    .tc-class-nav-item {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.5rem;
        font-size: 0.7rem;
        font-weight: 600;
        color: #d1d5db;
        text-decoration: none;
        border: 1px solid rgba(201,162,39,.2);
        background: rgba(0,0,0,.35);
        transition: border-color .2s, color .2s, background .2s;
    }
    .tc-class-nav-item img { border-radius: 4px; display: block; }
    .tc-class-nav-item:hover { color: #fff; border-color: rgba(242,207,82,.45); }
    .tc-class-nav-item.is-active {
        color: #f2cf5b;
        border-color: rgba(242,207,82,.55);
        box-shadow: 0 0 8px rgba(242,207,82,.2);
    }
    .tc-calculator-wrap { overflow-x: auto; text-align: center; }
    /* Legacy calculator: fixed-size tables/icons — undo Tailwind preflight in this subtree */
    body.page-talent-calculator #replaceMeWithTalents {
        display: block;
        width: max-content;
        max-width: none;
        margin-left: auto;
        margin-right: auto;
        text-align: left;
    }
    body.page-talent-calculator #replaceMeWithTalents img {
        display: inline;
        vertical-align: bottom;
        max-width: none;
        height: revert;
    }
    body.page-talent-calculator #replaceMeWithTalents table {
        border-collapse: collapse;
    }
    @media (min-width: 768px) {
        .tc-glass { padding: 1.75rem 1.5rem 2.25rem; }
    }
</style>
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';
?>

<div class="tc-content min-h-screen px-3 sm:px-6 lg:px-8 py-6 md:py-10">
    <div class="max-w-[1600px] mx-auto">
        <div class="tc-glass">
            <h1 class="tc-page-title text-xl sm:text-2xl md:text-3xl"><?php echo htmlspecialchars(translate('tc_page_title', 'Talent Calculator'), ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="tc-intro"><?php echo htmlspecialchars(sprintf(translate('tc_class_intro', 'WotLK 3.3.5a build planner — %s'), $class_label), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php include __DIR__ . '/_class_nav.php'; ?>
            <div class="tc-calculator-wrap">
                <div id="replaceMeWithTalents"></div>
            </div>
        </div>
    </div>
</div>

<script>
var pageId = <?php echo json_encode($tc_pageId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
var variableIsSite = 1;
var theURLtoCopy = <?php echo json_encode($tc_public_url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'detection.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'variables.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'functions.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'data.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'donotlocalize.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'localize.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'arraysFill.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'printOutTop.js', ENT_QUOTES, 'UTF-8'); ?>"></script>

<?php include $project_root . 'includes/footer.php'; ?>
