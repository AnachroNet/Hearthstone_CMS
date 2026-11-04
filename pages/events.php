<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/holiday_calendar.php';

global $site_db, $world_db;

$page_class = 'events';
$page_title = $site_title_name . ' ' . translate('events_page_title', 'Event Calendar');
$page_meta_description = translate('events_meta_description', 'World and community event schedule');
$page_meta_robots = 'index';

$event_cal = sahtout_holiday_calendar_for_home(
    isset($site_db) && $site_db instanceof mysqli ? $site_db : null,
    isset($_GET['cal']) ? (string) $_GET['cal'] : null,
    isset($site_timezone) ? (string) $site_timezone : '',
    isset($world_db) && $world_db instanceof mysqli ? $world_db : null
);

$event_calendar = is_array($event_cal['calendar'] ?? null)
    ? $event_cal['calendar']
    : sahtout_holiday_calendar_empty_month($event_cal['year'], $event_cal['month']);
$event_cal_show_footnote_stats = true;

ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($base_path . 'assets/css/home-calendar.css', ENT_QUOTES, 'UTF-8'); ?>">
<style>
        body {
            background: url('<?php echo $base_path; ?>img/backgrounds/bg-events.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            padding-top: 112px;
            margin: 0;
            position: relative;
        }
        .events-glass {
            background: rgba(5, 7, 11, 0.65);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.8), inset 0 0 60px rgba(0,0,0,.25);
            position: relative;
        }
        .events-glass::before {
            content: '';
            position: absolute;
            inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }
        .events-page-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .events-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.75rem;
            color: rgba(216, 216, 216, 0.75);
            margin-bottom: 1rem;
        }
        .events-legend span {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .events-legend i {
            display: inline-block;
            width: 1rem;
            height: 0.65rem;
            border-radius: 2px;
        }
        .events-legend .legend-wow {
            background: linear-gradient(180deg, #f6d478, #c9a227);
        }
        .events-legend .legend-site {
            background: linear-gradient(180deg, #f0f2f5 0%, #8a939f 100%);
            border: 1px solid rgba(192, 202, 214, 0.55);
        }
</style>
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';
?>

<main class="max-w-5xl mx-auto px-4 sm:px-6 py-6 md:py-10">
    <?php if (empty($event_cal['show'])): ?>
        <div class="events-glass p-6 md:p-8 text-center text-gray-300">
            <?php echo translate('events_calendar_unavailable', 'The event calendar is not available yet. Import holiday DBC data into the site database.'); ?>
        </div>
    <?php else: ?>
        <div class="events-glass p-4 sm:p-6 md:p-8">
            <h1 id="event-calendar-page-heading" class="events-page-title text-2xl sm:text-3xl md:text-4xl text-center mb-2">
                <?php echo translate('home_event_calendar_title', 'Event Calendar'); ?>
            </h1>
            <p class="text-center text-sm text-gray-400 mb-4 max-w-2xl mx-auto">
                <?php echo translate('events_page_intro', 'World holidays from the in-game schedule plus community events managed by staff.'); ?>
            </p>
            <div class="events-legend justify-center">
                <span><i class="legend-wow" aria-hidden="true"></i><?php echo translate('events_legend_wow', 'World events'); ?></span>
                <span><i class="legend-site" aria-hidden="true"></i><?php echo translate('events_legend_site', 'Community events'); ?></span>
            </div>
            <?php include $project_root . 'includes/event_calendar_panel.php'; ?>
        </div>
        <script src="<?php echo htmlspecialchars($base_path . 'assets/js/home-event-calendar-tooltip.js', ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php endif; ?>
</main>

<?php include $project_root . 'includes/footer.php'; ?>
