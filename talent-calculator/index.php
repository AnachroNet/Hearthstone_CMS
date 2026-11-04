<?php

require_once __DIR__ . '/_init.php';

$page_class = 'talent_calculator';
$page_body_class = 'page-talent-calculator';
$page_title = $site_title_name . ' ' . translate('tc_page_title', 'Talent Calculator');
$page_meta_description = translate('tc_meta_description', 'Plan your WotLK 3.3.5 talent build and share the link with others.');
$page_meta_robots = 'index';

$tc_active_slug = '';
$tc_theme_css = $tc_web_base . 'tc-theme.css';

ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($tc_theme_css, ENT_QUOTES, 'UTF-8'); ?>">
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';
?>

<div class="tc-content min-h-screen px-3 sm:px-6 lg:px-8 py-8 md:py-12">
    <div class="max-w-4xl mx-auto">
        <div class="wow-panel relative overflow-hidden p-4 sm:p-6 md:p-8">
            <h1 class="wow-title text-2xl sm:text-3xl md:text-4xl"><?php echo htmlspecialchars(translate('tc_page_title', 'Talent Calculator'), ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="tc-intro"><?php echo htmlspecialchars(translate('tc_index_intro', 'Choose a class to plan a Wrath of the Lich King (3.3.5a) talent build. Share your build by copying the link at the bottom of the calculator.'), ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="tc-class-grid">
                <?php foreach (sahtout_talent_calculator_classes() as $class):
                    $label = translate($class['class_key'], $class['dir']);
                    $href = htmlspecialchars($tc_web_base . $class['slug'] . '.php', ENT_QUOTES, 'UTF-8');
                    $icon = htmlspecialchars($tc_web_base . 'class-images/' . $class['slug'] . '.png', ENT_QUOTES, 'UTF-8');
                    ?>
                    <a class="tc-class-card" href="<?php echo $href; ?>">
                        <img src="<?php echo $icon; ?>" alt="" loading="lazy" decoding="async">
                        <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php include $project_root . 'includes/footer.php'; ?>
