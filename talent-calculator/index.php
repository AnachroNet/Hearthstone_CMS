<?php

require_once __DIR__ . '/_init.php';

$page_class = 'talent_calculator';
$page_body_class = 'page-talent-calculator';
$page_title = $site_title_name . ' ' . translate('tc_page_title', 'Talent Calculator');
$page_meta_description = translate('tc_meta_description', 'Plan your WotLK 3.3.5 talent build and share the link with others.');
$page_meta_robots = 'index';

$tc_active_slug = '';

ob_start();
?>
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
        padding: 1.75rem 1.25rem 2.5rem;
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
        margin: 0 0 0.5rem;
    }
    .tc-intro { text-align: center; color: #9ca3af; font-size: 0.9375rem; margin: 0 0 1.5rem; max-width: 36rem; margin-left: auto; margin-right: auto; }
    .tc-class-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr));
        gap: 1rem;
        max-width: 52rem;
        margin: 0 auto;
    }
    .tc-class-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 0.5rem;
        text-decoration: none;
        color: #e5e7eb;
        border: 1px solid rgba(201,162,39,.2);
        background: rgba(0,0,0,.35);
        transition: transform .2s, border-color .2s, box-shadow .2s;
    }
    .tc-class-card:hover {
        transform: translateY(-3px);
        border-color: rgba(242,207,82,.5);
        box-shadow: 0 8px 20px rgba(0,0,0,.45);
        color: #fff;
    }
    .tc-class-card img {
        width: 4.5rem;
        height: auto;
        border-radius: 4px;
    }
    .tc-class-card span {
        font-size: 0.8125rem;
        font-weight: 600;
        text-align: center;
    }
</style>
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';
?>

<div class="tc-content min-h-screen px-3 sm:px-6 lg:px-8 py-8 md:py-12">
    <div class="max-w-4xl mx-auto">
        <div class="tc-glass">
            <h1 class="tc-page-title text-2xl sm:text-3xl md:text-4xl"><?php echo htmlspecialchars(translate('tc_page_title', 'Talent Calculator'), ENT_QUOTES, 'UTF-8'); ?></h1>
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
