<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';

$page_class = 'credits';
$page_title = $site_title_name . ' ' . translate('credits_page_title', 'Credits');
$page_meta_description = translate('credits_meta_description', 'Open-source projects and contributors that made this site possible.');
$page_meta_robots = 'index';

ob_start();
?>
<style>
        body {
            background: url('<?php echo sahtout_page_background_url('news'); ?>') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            padding-top: 112px;
            margin: 0;
            position: relative;
        }

        .credits-content {
            position: relative;
            z-index: 1;
        }

        .credits-content .glass-container {
            background: rgba(5, 7, 11, 0.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.8), inset 0 0 60px rgba(0,0,0,.25);
            position: relative;
        }

        .credits-content .glass-container::before {
            content: '';
            position: absolute;
            inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }

        .credits-content .glass-container::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(#e8c552,#e8c552) left top / 18px 2px,
                linear-gradient(#e8c552,#e8c552) left top / 2px 18px,
                linear-gradient(#e8c552,#e8c552) right top / 18px 2px,
                linear-gradient(#e8c552,#e8c552) right top / 2px 18px,
                linear-gradient(#e8c552,#e8c552) left bottom / 18px 2px,
                linear-gradient(#e8c552,#e8c552) left bottom / 2px 18px,
                linear-gradient(#e8c552,#e8c552) right bottom / 18px 2px,
                linear-gradient(#e8c552,#e8c552) right bottom / 2px 18px;
            background-repeat: no-repeat;
        }

        .credits-page-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .credits-entry {
            position: relative;
            z-index: 1;
            padding: 1.25rem 0;
            border-bottom: 1px solid rgba(201,162,39,.12);
        }

        .credits-entry:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .credits-entry:first-of-type {
            padding-top: 0;
        }

        .credits-entry-title {
            font-family: 'Cinzel', serif;
            color: #f2cf5b;
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0 0 0.35rem;
        }

        .credits-entry-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem 1.25rem;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
        }

        .credits-entry-meta a {
            color: #9ca3af;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .credits-entry-meta a:hover {
            color: #f2cf5b;
        }

        .credits-license {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #d8d8d8;
            border: 1px solid rgba(201,162,39,.25);
            background: rgba(0,0,0,.35);
        }

        .credits-entry-body {
            color: #c4c4c4;
            line-height: 1.65;
            font-size: 0.95rem;
            margin: 0;
        }
</style>
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';
?>

<div class="credits-content min-h-screen flex items-start justify-center px-4 md:px-8 py-8 pb-16">
    <div class="container mx-auto max-w-3xl px-2 sm:px-4">
        <div class="glass-container p-6 md:p-10">
            <h1 class="credits-page-title text-2xl sm:text-3xl text-center mb-3">
                <?php echo htmlspecialchars(translate('credits_heading', 'Credits'), ENT_QUOTES, 'UTF-8'); ?>
            </h1>
            <p class="text-center text-sm text-gray-400 mb-8 max-w-2xl mx-auto">
                <?php echo htmlspecialchars(translate('credits_intro', 'This site builds on excellent open-source work. We are grateful to the projects and people below.'), ENT_QUOTES, 'UTF-8'); ?>
            </p>

            <article class="credits-entry">
                <h2 class="credits-entry-title"><?php echo htmlspecialchars(translate('credits_sahtout_name', 'SahtoutCMS'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <div class="credits-entry-meta">
                    <a href="<?php echo htmlspecialchars(translate('credits_sahtout_url', 'https://github.com/SahtoutDev/SahtoutCMS'), ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank"
                       rel="noopener noreferrer">
                        <?php echo htmlspecialchars(translate('credits_sahtout_url', 'https://github.com/SahtoutDev/SahtoutCMS'), ENT_QUOTES, 'UTF-8'); ?>
                        <span class="sr-only"> (<?php echo htmlspecialchars(translate('credits_link_label', 'View repository'), ENT_QUOTES, 'UTF-8'); ?>)</span>
                    </a>
                    <span class="credits-license"><?php echo htmlspecialchars(translate('credits_sahtout_license', 'MIT License'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <p class="credits-entry-body"><?php echo htmlspecialchars(translate('credits_sahtout_body', ''), ENT_QUOTES, 'UTF-8'); ?></p>
            </article>

            <article class="credits-entry">
                <h2 class="credits-entry-title"><?php echo htmlspecialchars(translate('credits_talent_name', 'Offline Talent Calculator for WotLK-3.3.5a'), ENT_QUOTES, 'UTF-8'); ?></h2>
                <div class="credits-entry-meta">
                    <a href="<?php echo htmlspecialchars(translate('credits_talent_url', 'https://github.com/CraftedRO/Offline-talent-calculator-for-WotLK-3.3.5a'), ENT_QUOTES, 'UTF-8'); ?>"
                       target="_blank"
                       rel="noopener noreferrer">
                        <?php echo htmlspecialchars(translate('credits_talent_url', 'https://github.com/CraftedRO/Offline-talent-calculator-for-WotLK-3.3.5a'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <span class="credits-license"><?php echo htmlspecialchars(translate('credits_talent_license', 'GPL-3.0 License'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <p class="credits-entry-body"><?php echo htmlspecialchars(translate('credits_talent_body', ''), ENT_QUOTES, 'UTF-8'); ?></p>
            </article>
        </div>
    </div>
</div>

<?php include $project_root . 'includes/footer.php'; ?>
