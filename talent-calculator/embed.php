<?php

require_once __DIR__ . '/_init.php';

$slug = isset($_GET['class']) ? strtolower(trim((string) $_GET['class'])) : '';
$class = sahtout_talent_calculator_class_by_slug($slug);
if ($class === null) {
    header('HTTP/1.1 404 Not Found');
    exit('Class not found.');
}

$tal = isset($_GET['tal']) ? (string) $_GET['tal'] : '';
if (strlen($tal) > 4096) {
    $tal = substr($tal, 0, 4096);
}
if ($tal !== '' && !preg_match('/^[0-9]+$/', $tal)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid talent string.');
}

$tc_dir = $class['dir'];
$tc_pageId = $class['pageId'];
$page_document_base = $tc_web_base;

$tc_css = $tc_web_base . 'talents/' . $tc_dir . '/talent.css';
$tc_integration_css = $tc_web_base . 'tc-integration.css';
$tc_theme_css = $tc_web_base . 'tc-theme.css';
$tc_script_base = $tc_web_base . 'talents/' . $tc_dir . '/';

header('X-Frame-Options: SAMEORIGIN');
header('Content-Type: text/html; charset=UTF-8');

$esc_tal = json_encode($tal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$esc_page_id = json_encode($tc_pageId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo htmlspecialchars($tc_web_base, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Talent build</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($tc_css, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($tc_integration_css, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($tc_theme_css, ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        html, body.page-talent-calculator-embed {
            margin: 0;
            padding: 0 !important;
            min-height: 0 !important;
            background: transparent !important;
            background-image: none !important;
            overflow: visible;
        }
        body.page-talent-calculator-embed .tc-embed-wrap {
            display: flex;
            justify-content: center;
            padding: 0.25rem 0 0.5rem;
        }
        body.page-talent-calculator-embed #replaceMeWithTalents {
            pointer-events: none;
        }
    </style>
</head>
<body class="page-talent-calculator page-talent-calculator-embed">
<div class="tc-embed-wrap">
    <div id="replaceMeWithTalents"></div>
</div>
<script>
var pageId = <?php echo $esc_page_id; ?>;
var variableIsSite = 1;
var theURLtoCopy = '';
window.__tcPresetTal = <?php echo $esc_tal; ?>;
window.__tcEmbedReadOnly = true;
window.__tcMountId = 'replaceMeWithTalents';
</script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'detection.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'variables.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'functions.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'data.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'donotlocalize.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'localize.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'arraysFill.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($tc_script_base . 'printOutTop.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    function reportEmbedHeight() {
        var root = document.getElementById('replaceMeWithTalents');
        var h = 0;
        if (root) {
            h = Math.ceil(root.getBoundingClientRect().height);
        }
        if (!h) {
            h = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
        }
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'sahtout-tc-embed-height', height: h + 8 }, '*');
        }
    }
    window.addEventListener('load', function () {
        reportEmbedHeight();
        setTimeout(reportEmbedHeight, 150);
        setTimeout(reportEmbedHeight, 600);
    });
    if (document.readyState === 'complete') {
        reportEmbedHeight();
    }
})();
</script>
</body>
</html>
