<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/site_downloads.php';

global $site_db;

$tableReady = isset($site_db) && $site_db instanceof mysqli && sahtout_downloads_table_exists($site_db);
$downloads = $tableReady ? sahtout_fetch_downloads($site_db, true) : [];
$grouped = sahtout_group_downloads_by_category($downloads);
$categories = sahtout_download_categories();

$page_class = 'download';
$page_title = $site_title_name . translate('download_title', ' - Download');
$page_meta_description = translate('download_meta_description', 'Download links for this server (client, launcher, tools, and more).');

ob_start();
?>
<style>
        * { font-family: 'Inter', sans-serif; }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            color: #d8d8d8;
            background: #05070b;
            background-image:
                radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.14), transparent 65%),
                radial-gradient(800px 600px at -5% 85%, rgba(124,58,237,.10), transparent 70%),
                linear-gradient(180deg, #0a0e16 0%, #060810 45%, #03040a 100%);
            background-attachment: fixed;
            padding-top: 80px;
        }

        header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            flex-shrink: 0;
            width: 100%;
            background: rgba(10, 14, 22, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(201,162,39,0.2);
        }

        .main-wrapper {
            flex: 1 0 auto;
            width: 100%;
            display: flex;
            flex-direction: column;
            padding-top: 1rem;
        }

        footer {
            flex-shrink: 0;
            width: 100%;
            margin-top: auto;
        }

        .panel {
            position: relative;
            background: linear-gradient(180deg, rgba(22,25,32,.92), rgba(8,10,14,.9));
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 12px 32px rgba(0,0,0,.55), inset 0 0 60px rgba(0,0,0,.45);
        }
        .panel::before {
            content: '';
            position: absolute;
            inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }

        .wow-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 3px 6px rgba(0,0,0,.85));
            letter-spacing: .02em;
        }

        .section-heading {
            font-family: 'Cinzel', serif;
            font-weight: 700;
            color: #f2cf5b;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            text-align: left;
            text-shadow: 0 0 12px rgba(201,162,39,.35), 0 2px 4px rgba(0,0,0,.8);
        }
        .section-heading::after {
            content: '';
            flex: 1;
            height: 1px;
            margin-left: 0.75rem;
            background: linear-gradient(90deg, rgba(201,162,39,.5), transparent);
        }

        .download-category-section + .download-category-section {
            margin-top: 3rem;
        }

        .download-list-block {
            width: 100%;
        }

        .download-grid {
            display: grid;
            grid-template-columns: 1fr;
            row-gap: 2rem;
            column-gap: 1.75rem;
        }

        @media (min-width: 640px) {
            .download-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .download-link-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            font-weight: 700;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            background: linear-gradient(180deg, #f6d478 0%, #c9a227 48%, #8a6a14 100%);
            color: #1a1200;
            text-decoration: none;
            border: none;
            width: 100%;
        }
        .download-link-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(201,162,39,.35);
        }

        .file-card {
            transition: border-color 0.25s ease, box-shadow 0.25s ease;
        }
        .file-card:hover {
            border-color: rgba(201,162,39,0.45);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(201, 162, 39, 0.15);
        }

        .info-box {
            background: rgba(52, 152, 219, 0.12);
            border: 1px solid rgba(52, 152, 219, 0.35);
            color: #93c5fd;
        }

        @media (max-width: 768px) {
            body { padding-top: 70px; }
        }
    </style>
<?php
$page_head = ob_get_clean();
include_once $project_root . 'includes/header.php';
?>
    <div class="main-wrapper">
        <main class="flex-1 flex flex-col w-full px-4 py-6 md:py-12">
            <div class="container mx-auto max-w-6xl w-full">

                <h1 class="wow-title text-3xl md:text-4xl mb-3">
                    <?php echo translate('download_title_h1', 'Downloads'); ?>
                </h1>
                <p class="text-gray-400 text-sm md:text-base max-w-3xl mb-8">
                    <?php echo translate('download_intro', 'Files are hosted externally. Each link opens in a new tab.'); ?>
                </p>

                <?php if (!$tableReady): ?>
                    <div class="panel p-8 info-box rounded-sm download-list-block">
                        <p><?php echo translate('download_table_missing', 'Download links are not available—the site_downloads table is missing. Re-import SQL/Hearthstone_DATABASE/FULL_UPDATE/hearthstone_site_install_demo.sql into your site database. After that, add links in Admin → Downloads.'); ?></p>
                    </div>
                <?php elseif (empty($downloads)): ?>
                    <div class="panel p-8 download-list-block">
                        <div class="text-5xl text-[#f2cf5b] mb-4"><i class="fas fa-folder-open"></i></div>
                        <p class="text-gray-400"><?php echo translate('download_none_published', 'No download links are published yet. Check back soon.'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="download-list-block">
                        <?php foreach (sahtout_download_category_order() as $catKey):
                            $items = $grouped[$catKey] ?? [];
                            if ($items === []) {
                                continue;
                            }
                            $catLabel = $categories[$catKey] ?? $catKey;
                            $icon = sahtout_download_category_icon($catKey);
                        ?>
                        <section class="download-category-section">
                            <h2 class="section-heading">
                                <i class="fas <?php echo htmlspecialchars($icon); ?> text-[#f2cf5b]"></i>
                                <?php echo htmlspecialchars($catLabel); ?>
                            </h2>
                            <div class="download-grid">
                                <?php foreach ($items as $item): ?>
                                <article class="panel p-5 md:p-6 file-card flex flex-col h-full">
                                    <div class="flex items-start justify-between gap-3 mb-2">
                                        <h3 class="text-lg font-bold text-white font-['Cinzel'] leading-snug">
                                            <?php echo htmlspecialchars($item['title']); ?>
                                        </h3>
                                        <?php if (!empty($item['size_label'])): ?>
                                        <span class="text-xs px-2 py-1 rounded-full bg-[#f2cf5b]/10 text-[#f2cf5b] border border-[#f2cf5b]/25 whitespace-nowrap shrink-0">
                                            <?php echo htmlspecialchars($item['size_label']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty(trim((string) $item['description']))): ?>
                                    <p class="text-sm text-gray-400 mb-4 flex-1"><?php echo nl2br(htmlspecialchars(trim($item['description']))); ?></p>
                                    <?php else: ?>
                                    <div class="flex-1 mb-4"></div>
                                    <?php endif; ?>
                                    <a href="<?php echo htmlspecialchars($item['url']); ?>"
                                       class="download-link-btn mt-auto"
                                       target="_blank"
                                       rel="noopener noreferrer">
                                        <i class="fas fa-external-link-alt"></i>
                                        <?php echo translate('download_open_link', 'Open link'); ?>
                                    </a>
                                </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <?php include_once $project_root . 'includes/footer.php'; ?>
