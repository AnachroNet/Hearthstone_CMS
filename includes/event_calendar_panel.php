<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** @var array<string, mixed> $event_cal */
/** @var array<string, mixed> $event_calendar */
$event_cal = $event_cal ?? [];
$event_calendar = $event_calendar ?? sahtout_holiday_calendar_empty_month(
    (int) ($event_cal['year'] ?? (int) date('Y')),
    (int) ($event_cal['month'] ?? (int) date('n'))
);
$event_cal_show_footnote_stats = !empty($event_cal_show_footnote_stats);

$event_cal_year = (int) ($event_cal['year'] ?? $event_calendar['year']);
$event_cal_month = (int) ($event_cal['month'] ?? $event_calendar['month']);
$event_cal_is_current_month = !empty($event_cal['is_current_month']);
$event_cal_today_day = (int) ($event_cal['today_day'] ?? 1);
$event_cal_prev_y = (int) ($event_cal['prev_y'] ?? $event_cal_year);
$event_cal_prev_m = (int) ($event_cal['prev_m'] ?? $event_cal_month);
$event_cal_next_y = (int) ($event_cal['next_y'] ?? $event_cal_year);
$event_cal_next_m = (int) ($event_cal['next_m'] ?? $event_cal_month);
$event_cal_month_label = (string) ($event_cal['month_label'] ?? '');
$event_cal_today = $event_cal['today'] ?? null;

$cal_grid_start = new DateTimeImmutable(sprintf('%04d-%02d-01', $event_cal_year, $event_cal_month));
$cal_leading_blanks = ((int) $cal_grid_start->format('w') + 6) % 7;
$cal_days_in_month = (int) $cal_grid_start->format('t');
$dow_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
?>
<section class="home-event-calendar p-0" aria-labelledby="event-calendar-page-heading">
    <div class="home-event-calendar-header">
        <div class="home-event-calendar-nav">
            <a href="<?php echo sahtout_holiday_calendar_nav_url($base_path, $event_cal_prev_y, $event_cal_prev_m); ?>" aria-label="<?php echo translate('home_event_calendar_prev', 'Previous month'); ?>">‹</a>
            <span class="home-event-calendar-month-label"><?php echo htmlspecialchars($event_cal_month_label, ENT_QUOTES, 'UTF-8'); ?></span>
            <a href="<?php echo sahtout_holiday_calendar_nav_url($base_path, $event_cal_next_y, $event_cal_next_m); ?>" aria-label="<?php echo translate('home_event_calendar_next', 'Next month'); ?>">›</a>
        </div>
        <?php if (!$event_cal_is_current_month && $event_cal_today instanceof DateTimeImmutable): ?>
        <a href="<?php echo sahtout_holiday_calendar_nav_url($base_path, (int) $event_cal_today->format('Y'), (int) $event_cal_today->format('n')); ?>" class="text-xs font-semibold text-[#f2cf5b] hover:text-white transition-colors">
            <?php echo translate('home_event_calendar_today', 'Today'); ?>
        </a>
        <?php endif; ?>
    </div>

    <div class="home-event-calendar-grid" role="grid" aria-readonly="true">
        <?php foreach ($dow_labels as $dow): ?>
            <div class="home-event-calendar-dow" role="columnheader"><?php echo htmlspecialchars($dow, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endforeach; ?>

        <?php for ($blank = 0; $blank < $cal_leading_blanks; $blank++): ?>
            <div class="home-event-calendar-cell is-empty" role="gridcell" aria-hidden="true"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $cal_days_in_month; $day++):
            $is_today = $event_cal_is_current_month && $day === $event_cal_today_day;
            $day_events = $event_calendar['events_by_day'][$day] ?? [];
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
                    $chip_tooltip = sahtout_holiday_event_tooltip_html($ev);
                    $chip_class = 'home-event-calendar-chip has-event-tooltip';
                    if (!empty($ev['is_site_event'])) {
                        $chip_class .= ' is-site-event';
                    }
                    ?>
                    <span class="<?php echo $chip_class; ?>" data-tooltip="<?php echo htmlspecialchars($chip_tooltip, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($ev['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php endforeach; ?>
                <?php if (count($day_events) > 3): ?>
                    <span class="text-[10px] text-[#f2cf5b]">+<?php echo count($day_events) - 3; ?></span>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>

    <?php if (($event_calendar['month_events'] ?? []) !== []): ?>
    <div class="home-event-calendar-list">
        <h3 class="text-sm font-bold text-[#f2cf5b] mb-2"><?php echo translate('home_event_calendar_this_month', 'Events this month'); ?></h3>
        <?php
        $event_list_events = $event_calendar['month_events'];
        $event_list_empty_message = translate('home_event_calendar_empty', 'No world events fall in this month.');
        include __DIR__ . '/event_calendar_event_list.php';
        ?>
    </div>
    <?php else: ?>
    <p class="home-event-calendar-footnote mt-4 mb-0"><?php echo translate('home_event_calendar_empty', 'No world events fall in this month.'); ?></p>
    <?php endif; ?>

    <?php if ($event_cal_show_footnote_stats): ?>
    <?php
    $cal_meta = is_array($event_calendar) ? $event_calendar : [];
    $dbc_rows = (int) ($cal_meta['dbc_row_count'] ?? 0);
    $loaded = (int) ($cal_meta['loaded_holiday_count'] ?? 0);
    $occ_total = (int) ($cal_meta['total_occurrences'] ?? 0);
    $occ_month = (int) ($cal_meta['month_occurrence_count'] ?? count($event_calendar['month_events'] ?? []));
    ?>
    <p class="home-event-calendar-footnote mb-0 mt-4">
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
    </p>
    <?php endif; ?>
</section>
