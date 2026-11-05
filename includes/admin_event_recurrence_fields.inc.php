<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** @var bool $recurrence_form_is_recurring */
/** @var int $recurrence_form_days */
$recurrence_form_is_recurring = !empty($recurrence_form_is_recurring);
$recurrence_form_days = (int) ($recurrence_form_days ?? 0);
$weekdays = sahtout_site_calendar_events_weekday_definitions();
?>
<div class="border border-[rgba(201,162,39,0.15)] rounded-sm p-4 space-y-3 bg-black/20">
    <label class="flex items-center gap-2 text-sm text-gray-300">
        <input type="checkbox"
               name="is_recurring"
               value="1"
               class="event-is-recurring"
               <?php echo $recurrence_form_is_recurring ? 'checked' : ''; ?>>
        <?php echo translate('admin_events_label_recurring', 'Recurring weekly (only on selected weekdays)'); ?>
    </label>
    <p class="text-xs text-gray-500 mb-0">
        <?php echo translate('admin_events_help_recurring', 'Leave unchecked to show every day from start through end (like most in-game events). Use one day for both start and end for a single-day event.'); ?>
    </p>
    <div class="event-recurrence-days flex flex-wrap gap-3 <?php echo $recurrence_form_is_recurring ? '' : 'hidden'; ?>">
        <?php foreach ($weekdays as $bit => $labelKey): ?>
            <?php $checked = ($recurrence_form_days & $bit) !== 0; ?>
            <label class="flex items-center gap-1.5 text-sm text-gray-300">
                <input type="checkbox"
                       name="recurrence_day[]"
                       value="<?php echo (int) $bit; ?>"
                       class="event-recurrence-day"
                       <?php echo $recurrence_form_is_recurring ? '' : 'disabled'; ?>
                       <?php echo $checked ? 'checked' : ''; ?>>
                <?php echo htmlspecialchars(translate($labelKey, sahtout_site_calendar_events_weekday_default_label($bit)), ENT_QUOTES, 'UTF-8'); ?>
            </label>
        <?php endforeach; ?>
    </div>
</div>
