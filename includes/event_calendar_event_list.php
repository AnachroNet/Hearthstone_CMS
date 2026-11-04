<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** @var list<array<string, mixed>> $event_list_events */
$event_list_events = $event_list_events ?? [];
$event_list_empty_message = $event_list_empty_message ?? translate('home_event_calendar_empty_upcoming', 'No events are active or scheduled in the next 30 days.');
$event_list_show_description = $event_list_show_description ?? true;
$event_list_description_max = (int) ($event_list_description_max ?? 160);

if ($event_list_events === []): ?>
    <p class="home-event-calendar-footnote mt-0 mb-0"><?php echo htmlspecialchars($event_list_empty_message, ENT_QUOTES, 'UTF-8'); ?></p>
<?php else: ?>
    <ul>
        <?php
        $listed = [];
        foreach ($event_list_events as $ev):
            $key = ($ev['holiday_id'] ?? 0) . '|' . ($ev['start'] ?? '');
            if (isset($listed[$key])) {
                continue;
            }
            $listed[$key] = true;
            $range = sahtout_holiday_event_date_range($ev);
            $list_tooltip = sahtout_holiday_event_tooltip_html($ev);
            ?>
            <li class="has-event-tooltip" data-tooltip="<?php echo htmlspecialchars($list_tooltip, ENT_QUOTES, 'UTF-8'); ?>">
                <strong><?php echo htmlspecialchars($ev['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="dates"> · <?php echo htmlspecialchars($range, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($event_list_show_description && ($ev['description'] ?? '') !== ''): ?>
                    <?php
                    $ev_desc = (string) $ev['description'];
                    if ($event_list_description_max > 0 && strlen($ev_desc) > $event_list_description_max) {
                        $ev_desc = substr($ev_desc, 0, $event_list_description_max - 3) . '…';
                    }
                    ?>
                    <br><span><?php echo htmlspecialchars($ev_desc, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
