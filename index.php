<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/maintenance_announcements.php';
require_once $project_root . 'includes/breaking_news.php';
require_once $project_root . 'includes/holiday_calendar.php';
require_once $project_root . 'includes/news_helpers.php';

global $world_db;

$page_class = "home";
$page_title = $site_title_name . " " . translate('home_page_title', 'Home');
$page_meta_description = translate('home_meta_description', 'Welcome to our World of Warcraft server. Join our Discord, YouTube, Instagram, create an account, or download the game now!');
$page_meta_robots = 'index';

ob_start();
?>
<style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800;900&family=Cinzel:wght@600;700;900&display=swap');

        * { font-family: 'Inter', sans-serif; }

        /* ============ PURE-CSS GAMING BACKGROUND (Lighter & Cleaner) ============ */
        body {
            min-height: 100vh;
            position: relative;
            color: #d8d8d8;
            background-color: #12161f; /* Lighter base color */
            background-image:
                radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.25), transparent 65%), /* Stronger Blue */
                radial-gradient(800px 600px at -5% 85%, rgba(124,58,237,.20), transparent 70%),   /* Stronger Purple */
                linear-gradient(180deg, #181e2a 0%, #12161f 45%, #0a0d14 100%);                  /* Lighter Vignette */
            background-attachment: fixed;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                radial-gradient(2px 2px at 10% 20%, rgba(242,207,82,.8), transparent 55%),
                radial-gradient(1.5px 1.5px at 30% 70%, rgba(242,207,82,.6), transparent 55%),
                radial-gradient(2px 2px at 55% 40%, rgba(255,160,60,.65), transparent 55%),
                radial-gradient(1.5px 1.5px at 75% 80%, rgba(242,207,82,.55), transparent 55%),
                radial-gradient(2px 2px at 90% 25%, rgba(255,120,50,.6), transparent 55%),
                radial-gradient(1.5px 1.5px at 45% 90%, rgba(120,160,255,.6), transparent 55%),
                radial-gradient(2px 2px at 65% 10%, rgba(120,160,255,.55), transparent 55%);
            background-size: 900px 700px;
            animation: emberDrift 45s linear infinite;
            opacity: .65; /* Slightly more visible against lighter bg */
            pointer-events: none;
            z-index: 0;
        }
        @keyframes emberDrift {
            from { background-position: 0 0; }
            to   { background-position: 900px -700px; }
        }

        body::after {
            content: '';
            position: fixed; inset: 0;
            background: radial-gradient(ellipse at center, transparent 45%, rgba(0,0,0,.40) 100%); /* Lighter vignette */
            pointer-events: none;
            z-index: 0;
        }

        main { position: relative; z-index: 1; }

        /* ============ GAMING DESIGN SYSTEM ============ */

        .wow-panel {
            position: relative;
            background: linear-gradient(180deg, rgba(22,25,32,.92), rgba(8,10,14,.9));
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 12px 32px rgba(0,0,0,.55), inset 0 0 60px rgba(0,0,0,.45);
        }
        .wow-panel::before {
            content: ''; position: absolute; inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }
        .wow-panel::after {
            content: ''; position: absolute; inset: 0; pointer-events: none;
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

        .wow-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text; background-clip: text;
            color: transparent;
            filter: drop-shadow(0 3px 6px rgba(0,0,0,.85));
            letter-spacing: .02em;
        }

        .section-title {
            font-family: 'Cinzel', serif;
            font-weight: 700;
            color: #f2cf5b;
            text-shadow: 0 0 12px rgba(201,162,39,.35), 0 2px 4px rgba(0,0,0,.8);
            display: flex; align-items: center; gap: .6rem;
        }
        .section-title::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, rgba(201,162,39,.5), transparent);
        }

        .btn-game {
            display: inline-flex; align-items: center; gap: .55rem;
            padding: .95rem 2rem;
            font-weight: 800; font-size: .95rem; letter-spacing: .04em; text-transform: uppercase;
            clip-path: polygon(12px 0, 100% 0, 100% calc(100% - 12px), calc(100% - 12px) 100%, 0 100%, 0 12px);
            transition: transform .2s ease, filter .2s ease;
            text-decoration: none;
        }
        .btn-game:hover { transform: translateY(-2px) scale(1.02); }
        .btn-gold {
            background: linear-gradient(180deg, #f6d478 0%, #c9a227 48%, #8a6a14 100%);
            color: #1a1200;
            text-shadow: 0 1px 0 rgba(255,255,255,.35);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.28), inset 0 -8px 14px rgba(0,0,0,.25);
            animation: pulseGold 2.8s ease-in-out infinite;
        }
        @keyframes pulseGold {
            0%,100% { filter: drop-shadow(0 0 6px rgba(242,207,82,.35)); }
            50%     { filter: drop-shadow(0 0 18px rgba(242,207,82,.7)); }
        }
        .btn-iron {
            background: linear-gradient(180deg, #3a404d 0%, #232833 55%, #141821 100%);
            color: #cfe1ff;
            box-shadow: inset 0 0 0 1px rgba(120,160,255,.25), inset 0 -8px 14px rgba(0,0,0,.4);
            filter: drop-shadow(0 0 8px rgba(59,130,246,.25));
        }
        .btn-iron:hover { filter: drop-shadow(0 0 16px rgba(59,130,246,.5)); }

        .social-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px;
            font-weight: 700; font-size: 13px; letter-spacing: .03em;
            color: #fff; text-decoration: none; border: none; cursor: pointer;
            clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px);
            transition: transform .2s ease, filter .2s ease;
        }
        .social-btn:hover { transform: translateY(-3px); }
        .social-btn.discord   { background: linear-gradient(180deg,#7289ff,#4752c4); filter: drop-shadow(0 0 6px rgba(88,101,242,.3)); }
        .social-btn.discord:hover   { filter: drop-shadow(0 0 14px rgba(88,101,242,.55)); }

        .news-card {
            display: block;
            position: relative;
            overflow: hidden;
            max-width: 100%;
            background: linear-gradient(180deg, rgba(26,29,36,.9), rgba(10,12,16,.9));
            border: 1px solid rgba(201,162,39,.18);
            transition: transform .3s ease, border-color .3s ease, filter .3s ease;
            text-decoration: none;
        }
        .news-card:hover {
            transform: translateY(-6px);
            border-color: rgba(242,207,82,.6);
            filter: drop-shadow(0 10px 20px rgba(0,0,0,.6)) drop-shadow(0 0 10px rgba(242,207,82,.15));
        }
        .news-card .news-date {
            font-size: 11px;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #c9a227;
        }
        /* Featured news (home): fixed frame + contain so the whole image scales inside the card */
        .home-featured-news .home-featured-news-frame {
            position: relative;
            width: 100%;
            height: 9rem;
            overflow: hidden;
            background: rgba(0, 0, 0, 0.35);
            border-bottom: 1px solid rgba(201, 162, 39, 0.12);
        }
        @media (min-width: 640px) {
            .home-featured-news .home-featured-news-frame { height: 11rem; }
        }
        .home-featured-news .home-news-thumb {
            position: absolute !important;
            inset: 0 !important;
            display: block !important;
            width: 100% !important;
            height: 100% !important;
            max-width: 100% !important;
            object-fit: contain !important;
            object-position: center center !important;
        }

        .status-online  { color: #4ade80; text-shadow: 0 0 8px rgba(74,222,128,.5); }
        .status-offline { color: #f87171; text-shadow: 0 0 8px rgba(248,113,113,.5); }

        .ornate-divider {
            border: 0; height: 12px; margin: 24px 0; position: relative;
            background: linear-gradient(90deg, transparent, rgba(201,162,39,.55), transparent);
        }
        .ornate-divider::after {
            content: '◆'; position: absolute; left: 50%; top: 50%;
            transform: translate(-50%,-50%);
            color: #f2cf5b; font-size: 8px; text-shadow: 0 0 8px rgba(242,207,82,.8);
        }

        /* ============ RESPONSIVE SIDEBAR FIX ============ */
        @media (max-width: 1024px) {
            .sidebar-sticky {
                position: relative;
                top: 0;
            }
        }
        
        @media (max-width: 640px) {
            .sidebar-sticky .wow-panel {
                padding: 1rem;
            }
            .sidebar-sticky .wow-title {
                font-size: 1.1rem;
            }
        }

        /* Discord widget responsive */
        .discord-widget-container {
            position: relative;
            width: 100%;
            overflow: hidden;
        }
        .discord-widget-container iframe {
            max-width: 100%;
            height: 340px;
        }
        @media (max-width: 480px) {
            .discord-widget-container iframe {
                height: 280px;
            }
        }

        /* ============ FORCE TEXT WRAPPING ============ */
        .force-wrap {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .maintenance-status-line {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #f2cf5b;
            text-shadow: 0 0 12px rgba(242, 207, 82, 0.15);
        }

        .home-intro-welcome {
            font-family: 'Cinzel', serif;
            font-weight: 800;
            font-size: 1.125rem;
            line-height: 1.3;
            text-shadow: 0 0 20px rgba(59, 130, 246, 0.35), 0 4px 8px rgba(0, 0, 0, 0.9);
        }
        @media (min-width: 640px) {
            .home-intro-welcome { font-size: 1.25rem; }
        }
        @media (min-width: 768px) {
            .home-intro-welcome { font-size: 1.875rem; }
        }
        @media (min-width: 1024px) {
            .home-intro-welcome { font-size: 2.25rem; }
        }
    </style>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path . 'assets/css/home-breaking-news.css', ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path . 'assets/css/home-calendar.css', ENT_QUOTES, 'UTF-8'); ?>">
<?php
$page_head = ob_get_clean();

$header_file = $project_root . 'includes/header.php';
if (file_exists($header_file)) {
    include $header_file;
} else {
    die(translate('error_header_not_found', 'Error: Header file not found.'));
}

$query = "SELECT id, title, slug, image_url, post_date 
          FROM server_news 
          ORDER BY is_important DESC, post_date DESC 
          LIMIT 4";
$result = $site_db->query($query);
if ($result === false) {
    error_log('index.php server_news query failed: ' . $site_db->error);
}

$maintenance_visible = [];
if (isset($site_db) && $site_db instanceof mysqli && !$site_db->connect_error) {
    $maintenance_visible = sahtout_maintenance_fetch_visible($site_db);
}

$home_breaking_news = null;
if (isset($world_db) && $world_db instanceof mysqli && !$world_db->connect_error) {
    $home_breaking_news = sahtout_breaking_news_fetch_for_display($world_db);
}

$home_cal = sahtout_holiday_calendar_for_home(
    isset($site_db) && $site_db instanceof mysqli ? $site_db : null,
    isset($_GET['cal']) ? (string) $_GET['cal'] : null,
    isset($site_timezone) ? (string) $site_timezone : '',
    isset($world_db) && $world_db instanceof mysqli ? $world_db : null
);
?>

    <main class="max-w-350 mx-auto px-4 sm:px-6 lg:px-8 py-6 md:py-10">

        <!-- Responsive grid: stacks on mobile, side-by-side on large screens -->
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_380px] gap-6 md:gap-8">

            <!-- ============ LEFT CONTENT ============ -->
            <div class="min-w-0 space-y-8 md:space-y-12">

                <!-- HERO -->
                <div class="wow-panel p-4 sm:p-6 md:p-8 text-center relative overflow-hidden">
                    <div class="absolute -top-24 -left-24 w-72 h-72 rounded-full blur-3xl opacity-20" style="background:#1e5aa8;"></div>
                    <div class="absolute -bottom-24 -right-24 w-72 h-72 rounded-full blur-3xl opacity-20" style="background:#a32626;"></div>

                    <div class="relative z-10">
                        <h1 class="flex flex-col items-center gap-1 sm:gap-2 leading-tight force-wrap">
                            <span class="home-intro-welcome text-white"><?php echo trim(translate('home_intro_title', 'Welcome to')); ?></span>
                            <span class="wow-title text-3xl sm:text-4xl md:text-6xl lg:text-7xl"><?php echo $site_title_name; ?></span>
                        </h1>

                        <div class="flex items-center justify-center gap-3 mt-4 sm:mt-5">
                            <span class="h-px w-12 md:w-28" style="background:linear-gradient(90deg,transparent,#c9a227);"></span>
                            <span class="text-[#f2cf5b] text-xs" style="text-shadow:0 0 10px rgba(242,207,82,.8);">◆</span>
                            <span class="h-px w-12 md:w-28" style="background:linear-gradient(90deg,#c9a227,transparent);"></span>
                        </div>

                        <div class="flex flex-wrap justify-center gap-3 sm:gap-4 mt-4 sm:mt-6">
                            <?php if (!isset($_SESSION['user_id'])): ?>
                                <a href="<?php echo htmlspecialchars($base_path . 'register', ENT_QUOTES, 'UTF-8'); ?>" class="btn-game btn-gold text-sm sm:text-base px-4 sm:px-6">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                                    <?php echo translate('home_create_account', 'Create Account'); ?>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($base_path . 'account', ENT_QUOTES, 'UTF-8'); ?>" class="btn-game btn-gold text-sm sm:text-base px-4 sm:px-6">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <?php echo translate('home_my_account', 'My Account'); ?>
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo htmlspecialchars($base_path . 'download/', ENT_QUOTES, 'UTF-8'); ?>" class="btn-game btn-iron text-sm sm:text-base px-4 sm:px-6">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                <?php echo translate('home_download', 'Download'); ?>
                            </a>
                        </div>

                        <?php if (!empty($discord_join_url)): ?>
                        <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-3 mt-4 sm:mt-6">
                            <a href="<?php echo htmlspecialchars($discord_join_url, ENT_QUOTES, 'UTF-8'); ?>" class="social-btn discord text-xs sm:text-sm px-3 sm:px-4" target="_blank" rel="noopener noreferrer" aria-label="<?php echo translate('discord_alt', 'Discord'); ?>">
                                <svg class="w-3 h-3 sm:w-4 sm:h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.27 5.33C17.94 4.71 16.5 4.26 15 4a.09.09 0 0 0-.07.03c-.18.33-.39.76-.53 1.09a16.09 16.09 0 0 0-4.8 0c-.14-.34-.35-.76-.54-1.09c-.01-.02-.04-.03-.07-.03c-1.5.26-2.93.71-4.27 1.33c-.01 0-.02.01-.03.02c-2.72 4.07-3.47 8.03-3.1 11.95c0 .02.01.04.03.05c1.8 1.32 3.53 2.12 5.24 2.65c.03.01.06 0 .07-.02c.4-.55.76-1.13 1.07-1.74c.02-.04 0-.08-.04-.09c-.57-.22-1.11-.48-1.64-.78c-.04-.02-.04-.08-.01-.11c.11-.08.22-.17.33-.25c.02-.02.05-.02.07-.01c3.44 1.57 7.15 1.57 10.55 0c.02-.01.05-.01.07.01c.11.08.22.17.33.26c.04.03.04.09-.01.11c-.52.31-1.07.56-1.64.78c-.04.01-.05.06-.04.09c.32.61.68 1.19 1.07 1.74c.03.01.06.02.09.01c1.72-.53 3.45-1.33 5.25-2.65c.02-.01.03-.03.03-.05c.44-4.53-.73-8.46-3.1-11.95c-.01-.01-.02-.02-.04-.02zM8.52 14.91c-1.03 0-1.89-.95-1.89-2.12s.84-2.12 1.89-2.12c1.06 0 1.9.96 1.89 2.12c0 1.17-.83 2.12-1.89 2.12zm6.97 0c-1.03 0-1.89-.95-1.89-2.12s.84-2.12 1.89-2.12c1.06 0 1.9.96 1.89 2.12c0 1.17-.83 2.12-1.89 2.12z"/></svg>
                                Discord
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($home_cal['show'])): ?>
                <?php
                $home_calendar = is_array($home_cal['calendar'] ?? null)
                    ? $home_cal['calendar']
                    : sahtout_holiday_calendar_empty_month($home_cal['year'], $home_cal['month']);
                $home_cal_year = $home_cal['year'];
                $home_cal_month = $home_cal['month'];
                $home_cal_is_current_month = $home_cal['is_current_month'];
                $home_cal_today_day = $home_cal['today_day'];
                $home_cal_prev_y = $home_cal['prev_y'];
                $home_cal_prev_m = $home_cal['prev_m'];
                $home_cal_next_y = $home_cal['next_y'];
                $home_cal_next_m = $home_cal['next_m'];
                $home_cal_month_label = $home_cal['month_label'];
                $home_cal_today = $home_cal['today'];
                ?>
                <section class="wow-panel home-event-calendar p-3 sm:p-4 md:p-6" aria-labelledby="home-event-calendar-heading">
                    <h2 id="home-event-calendar-heading" class="section-title text-lg sm:text-xl md:text-2xl mb-4 sm:mb-5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <?php echo translate('home_event_calendar_title', 'In-Game Event Calendar'); ?>
                    </h2>

                    <div class="home-event-calendar-header">
                        <div class="home-event-calendar-nav">
                            <a href="<?php echo sahtout_holiday_calendar_nav_url($base_path, $home_cal_prev_y, $home_cal_prev_m); ?>" aria-label="<?php echo translate('home_event_calendar_prev', 'Previous month'); ?>">‹</a>
                            <span class="home-event-calendar-month-label"><?php echo htmlspecialchars($home_cal_month_label, ENT_QUOTES, 'UTF-8'); ?></span>
                            <a href="<?php echo sahtout_holiday_calendar_nav_url($base_path, $home_cal_next_y, $home_cal_next_m); ?>" aria-label="<?php echo translate('home_event_calendar_next', 'Next month'); ?>">›</a>
                        </div>
                        <?php if (!$home_cal_is_current_month && $home_cal_today instanceof DateTimeImmutable): ?>
                        <a href="<?php echo sahtout_holiday_calendar_nav_url($base_path, (int) $home_cal_today->format('Y'), (int) $home_cal_today->format('n')); ?>" class="text-xs font-semibold text-[#f2cf5b] hover:text-white transition-colors">
                            <?php echo translate('home_event_calendar_today', 'Today'); ?>
                        </a>
                        <?php endif; ?>
                    </div>

                    <?php
                    $cal_grid_start = new DateTimeImmutable(sprintf('%04d-%02d-01', $home_cal_year, $home_cal_month));
                    $cal_leading_blanks = ((int) $cal_grid_start->format('w') + 6) % 7;
                    $cal_days_in_month = (int) $cal_grid_start->format('t');
                    $dow_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    ?>
                    <div class="home-event-calendar-grid" role="grid" aria-readonly="true">
                        <?php foreach ($dow_labels as $dow): ?>
                            <div class="home-event-calendar-dow" role="columnheader"><?php echo htmlspecialchars($dow, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endforeach; ?>

                        <?php for ($blank = 0; $blank < $cal_leading_blanks; $blank++): ?>
                            <div class="home-event-calendar-cell is-empty" role="gridcell" aria-hidden="true"></div>
                        <?php endfor; ?>

                        <?php for ($day = 1; $day <= $cal_days_in_month; $day++):
                            $is_today = $home_cal_is_current_month && $day === $home_cal_today_day;
                            $day_events = $home_calendar['events_by_day'][$day] ?? [];
                            $cell_classes = 'home-event-calendar-cell' . ($is_today ? ' is-today' : '');
                            ?>
                            <div class="<?php echo $cell_classes; ?>" role="gridcell">
                                <div class="home-event-calendar-daynum"><?php echo $day; ?></div>
                                <?php
                                $chips_shown = 0;
                                foreach ($day_events as $ev):
                                    if ($chips_shown >= 3) {
                                        break;
                                    }
                                    $chips_shown++;
                                    $tip = trim($ev['name'] . (isset($ev['description']) && $ev['description'] !== '' ? "\n" . $ev['description'] : ''));
                                    ?>
                                    <span class="home-event-calendar-chip" title="<?php echo htmlspecialchars($tip, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($ev['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($day_events) > 3): ?>
                                    <span class="text-[10px] text-[#f2cf5b]">+<?php echo count($day_events) - 3; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <?php if ($home_calendar['month_events'] !== []): ?>
                    <div class="home-event-calendar-list">
                        <h3 class="text-sm font-bold text-[#f2cf5b] mb-2"><?php echo translate('home_event_calendar_this_month', 'Events this month'); ?></h3>
                        <ul>
                            <?php
                            $listed = [];
                            foreach ($home_calendar['month_events'] as $ev):
                                $key = $ev['holiday_id'] . '|' . $ev['start'];
                                if (isset($listed[$key])) {
                                    continue;
                                }
                                $listed[$key] = true;
                                $range = $ev['start'] === $ev['end']
                                    ? date('M j', strtotime($ev['start']))
                                    : date('M j', strtotime($ev['start'])) . ' – ' . date('M j', strtotime($ev['end']));
                                ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($ev['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="dates"> · <?php echo htmlspecialchars($range, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($ev['description'] !== ''): ?>
                                        <?php
                                        $ev_desc = (string) $ev['description'];
                                        if (strlen($ev_desc) > 160) {
                                            $ev_desc = substr($ev_desc, 0, 157) . '…';
                                        }
                                        ?>
                                        <br><span><?php echo htmlspecialchars($ev_desc, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php else: ?>
                    <p class="home-event-calendar-footnote mt-4 mb-0"><?php echo translate('home_event_calendar_empty', 'No world events fall in this month.'); ?></p>
                    <?php endif; ?>

                    <?php
                    $cal_meta = is_array($home_calendar) ? $home_calendar : [];
                    $dbc_rows = (int) ($cal_meta['dbc_row_count'] ?? 0);
                    $loaded = (int) ($cal_meta['loaded_holiday_count'] ?? 0);
                    $occ_total = (int) ($cal_meta['total_occurrences'] ?? 0);
                    $occ_month = (int) ($cal_meta['month_occurrence_count'] ?? count($home_calendar['month_events'] ?? []));
                    ?>
                    <p class="home-event-calendar-footnote mb-0">
                        <?php echo translate('home_event_calendar_footnote', 'Dates follow WoW calendar rules (same as the in-game event schedule). Battleground Call to Arms weekends are hidden.'); ?>
                        <?php
                        printf(
                            ' ' . translate('home_event_calendar_stats', '(%1$d DBC rows, %2$d holidays loaded, %3$d dated spans, %4$d this month).'),
                            $dbc_rows,
                            $loaded,
                            $occ_total,
                            $occ_month
                        );
                        ?>
                        <?php if ($dbc_rows === 0): ?>
                            <?php echo ' '; echo translate('home_event_calendar_no_dbc_rows', 'Import holidays_dbc data into the site database (dbc_import.sql).'); ?>
                        <?php elseif ($loaded === 0): ?>
                            <?php echo ' '; echo translate('home_event_calendar_no_loaded', 'Holiday rows exist but none could be loaded — check PHP error log.'); ?>
                        <?php elseif ($occ_total === 0): ?>
                            <?php echo ' '; echo translate('home_event_calendar_no_occurrences', 'Holidays loaded but no dated occurrences — check holiday_date_calculator / game_event.'); ?>
                        <?php endif; ?>
                    </p>
                </section>
                <?php endif; ?>

                <!-- Featured / latest news -->
                <section class="wow-panel home-featured-news p-3 sm:p-4 md:p-6" aria-labelledby="home-featured-news-heading">
                    <div class="flex items-center justify-between mb-4 sm:mb-6 gap-4">
                        <h2 id="home-featured-news-heading" class="section-title text-lg sm:text-xl md:text-2xl">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                            <?php echo translate('home_news_title', 'Latest News'); ?>
                        </h2>
                        <a href="<?php echo $base_path; ?>news" class="text-xs sm:text-sm font-semibold text-[#f2cf5b] hover:text-white transition-colors flex items-center gap-1 whitespace-nowrap">
                            <?php echo translate('home_view_all', 'View All'); ?>
                            <svg class="w-3 h-3 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                        <?php if (!$result instanceof mysqli_result || $result->num_rows === 0): ?>
                            <p class="col-span-full text-gray-400 text-center py-8">
                                <?php echo translate('home_no_news', 'No news available at the time.'); ?>
                            </p>
                        <?php else: ?>
                            <?php while ($news = $result->fetch_assoc()): ?>
                                <?php
                                $home_news_src = sahtout_news_image_src((string) ($news['image_url'] ?? ''), $base_path);
                                $home_news_default = sahtout_news_image_src('', $base_path);
                                ?>
                                <a href="<?php echo htmlspecialchars(sahtout_news_article_href($news, $base_path), ENT_QUOTES, 'UTF-8'); ?>" class="news-card group min-w-0">
                                    <div class="home-featured-news-frame">
                                        <img src="<?php echo htmlspecialchars($home_news_src, ENT_QUOTES, 'UTF-8'); ?>"
                                             alt="<?php echo htmlspecialchars($news['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                             class="home-news-thumb"
                                             style="<?php echo sahtout_news_home_thumbnail_style(); ?>"
                                             loading="lazy"
                                             decoding="async"
                                             onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($home_news_default, ENT_QUOTES, 'UTF-8'); ?>';">
                                    </div>
                                    <div class="p-2 sm:p-3 min-w-0">
                                        <h3 class="text-xs sm:text-sm font-bold text-white min-w-0 wrap-anywhere line-clamp-2 mb-1"><?php echo htmlspecialchars($news['title']); ?></h3>
                                        <p class="news-date text-[10px] sm:text-xs m-0"><?php echo date('M j, Y', strtotime($news['post_date'])); ?></p>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- MAINTENANCE STATUS -->
                <section class="wow-panel p-3 sm:p-4 md:p-6">
                    <h2 class="section-title text-lg sm:text-xl md:text-2xl mb-4 sm:mb-5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        <?php echo translate('home_maintenance_title', 'Maintenance Status'); ?>
                    </h2>
                    <div class="text-center py-4 sm:py-6 space-y-4">
                        <?php if ($maintenance_visible !== []): ?>
                            <ul class="space-y-2 max-w-2xl mx-auto text-center px-2 list-none">
                                <?php foreach ($maintenance_visible as $ann): ?>
                                    <li class="maintenance-status-line normal-case tracking-normal text-sm sm:text-base leading-snug text-center">
                                        <?php echo htmlspecialchars((string) ($ann['message'] ?? '')); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="maintenance-status-line normal-case tracking-normal text-sm sm:text-base">
                                <?php echo translate('home_maintenance_none', 'No maintenance is scheduled at this time.'); ?>
                            </p>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="<?php echo $base_path; ?>account?tab=tickets" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-[#1a1200] bg-gradient-to-b from-[#f6d478] to-[#c9a227] border border-[#8a6a14] hover:brightness-110 transition-all">
                                <?php echo translate('home_support_cta_account', 'View My Support Tickets'); ?>
                            </a>
                        <?php else: ?>
                            <a href="<?php echo $base_path; ?>login" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-[#f2cf5b] border border-[rgba(201,162,39,0.35)] bg-[rgba(242,207,82,0.08)] hover:bg-[rgba(242,207,82,0.15)] transition-all">
                                <?php echo translate('home_support_cta_login', 'Log in to view tickets'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <!-- ============ RIGHT SIDE PANEL - Now visible on all screen sizes ============ -->
            <div class="sidebar-sticky">
                <div class="lg:sticky lg:top-24">
                    <div class="wow-panel p-4 sm:p-5 md:p-6 space-y-4 sm:space-y-6">
                        
                        <!-- Server Status -->
                        <div>
                            <h3 class="wow-title text-base sm:text-lg mb-3 sm:mb-4 flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                                <?php echo translate('home_server_status', 'Server Status'); ?>
                            </h3>
                            <?php
                            $realm_status_file = $project_root . 'includes/realm_status.php';
                            if (file_exists($realm_status_file)) {
                                ob_start();
                                include $realm_status_file;
                                $status_content = ob_get_clean();
                                echo '<div class="space-y-2 text-xs bg-black/30 p-2 sm:p-3 border border-[#c9a227]/20 rounded-sm force-wrap">';
                                echo $status_content;
                                echo '</div>';
                            } else {
                                echo '<p class="status-offline text-xs bg-black/30 p-2 sm:p-3 border border-red-500/20 rounded-sm force-wrap">' . translate('home_realm_status_error', 'Error: Realm status unavailable.') . '</p>';
                            }
                            ?>
                        </div>

                        <?php if ($home_breaking_news !== null): ?>
                        <div>
                            <hr class="ornate-divider">
                            <aside class="home-breaking-news force-wrap" aria-label="<?php echo translate('home_breaking_news_aria', 'Breaking news'); ?>">
                                <h4 class="home-breaking-news-title"><?php echo htmlspecialchars($home_breaking_news['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                <div class="home-breaking-news-body">
                                    <?php echo $home_breaking_news['body_html']; ?>
                                </div>
                            </aside>
                        </div>
                        <?php endif; ?>

                        <hr class="ornate-divider">

                        <!-- Discord -->
                        <div>
                            <h3 class="wow-title text-base sm:text-lg mb-3 sm:mb-4 flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M19.27 5.33C17.94 4.71 16.5 4.26 15 4a.09.09 0 0 0-.07.03c-.18.33-.39.76-.53 1.09a16.09 16.09 0 0 0-4.8 0c-.14-.34-.35-.76-.54-1.09c-.01-.02-.04-.03-.07-.03c-1.5.26-2.93.71-4.27 1.33c-.01 0-.02.01-.03.02c-2.72 4.07-3.47 8.03-3.1 11.95c0 .02.01.04.03.05c1.8 1.32 3.53 2.12 5.24 2.65c.03.01.06 0 .07-.02c.4-.55.76-1.13 1.07-1.74c.02-.04 0-.08-.04-.09c-.57-.22-1.11-.48-1.64-.78c-.04-.02-.04-.08-.01-.11c.11-.08.22-.17.33-.25c.02-.02.05-.02.07-.01c3.44 1.57 7.15 1.57 10.55 0c.02-.01.05-.01.07.01c.11.08.22.17.33.26c.04.03.04.09-.01.11c-.52.31-1.07.56-1.64.78c-.04.01-.05.06-.04.09c.32.61.68 1.19 1.07 1.74c.03.01.06.02.09.01c1.72-.53 3.45-1.33 5.25-2.65c.02-.01.03-.03.03-.05c.44-4.53-.73-8.46-3.1-11.95c-.01-.01-.02-.02-.04-.02zM8.52 14.91c-1.03 0-1.89-.95-1.89-2.12s.84-2.12 1.89-2.12c1.06 0 1.9.96 1.89 2.12c0 1.17-.83 2.12-1.89 2.12zm6.97 0c-1.03 0-1.89-.95-1.89-2.12s.84-2.12 1.89-2.12c1.06 0 1.9.96 1.89 2.12c0 1.17-.83 2.12-1.89 2.12z"/></svg>
                                <?php echo translate('home_discord_title', 'Join Our Discord'); ?>
                            </h3>
                            <div class="discord-widget-container bg-black/30 p-1 sm:p-2 border border-[#c9a227]/20 rounded-sm">
                                <?php if (!empty($discord_widget_enabled) && !empty($discord_guild_id)): ?>
                                <iframe src="https://discord.com/widget?id=<?php echo htmlspecialchars($discord_guild_id); ?>&theme=dark"
                                  width="100%"
                                  height="340"
                                  allowtransparency="true"
                                  frameborder="0"
                                  sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
                                  class="rounded-sm"
                                  title="<?php echo translate('home_discord_title', 'Join Our Discord'); ?>"></iframe>
                                <?php else: ?>
                                <p class="text-center text-sm text-gray-400 py-8 px-3">
                                    <?php echo translate('home_discord_widget_disabled', 'Discord widget is not configured. Set a server ID in Admin → Settings → General.'); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php
    $footer_file = $project_root . 'includes/footer.php';
    if (file_exists($footer_file)) {
        include $footer_file;
    } else {
        die(translate('error_footer_not_found', 'Error: Footer file not found.'));
    }
    ?>
<?php
if (isset($site_db)) {
    $site_db->close();
}
?>
