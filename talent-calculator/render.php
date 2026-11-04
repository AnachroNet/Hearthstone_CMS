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
$tc_integration_css = $tc_web_base . 'tc-integration.css';
$tc_theme_css = $tc_web_base . 'tc-theme.css';
$tc_script_base = $tc_web_base . 'talents/' . $tc_dir . '/';
$tc_public_url = rtrim($site_url, '/') . '/talent-calculator/' . rawurlencode($tc_slug) . '.php';
$page_document_base = $tc_web_base;

ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($tc_css, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($tc_integration_css, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($tc_theme_css, ENT_QUOTES, 'UTF-8'); ?>">
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';
?>

<div class="tc-content min-h-screen py-6 md:py-10">
    <div class="container mx-auto max-w-7xl px-2 sm:px-4">
        <div class="wow-panel tc-tree-panel relative p-4 sm:p-6 md:p-8">
            <h1 class="wow-title text-xl sm:text-2xl md:text-3xl"><?php echo htmlspecialchars(translate('tc_page_title', 'Talent Calculator'), ENT_QUOTES, 'UTF-8'); ?></h1>
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
