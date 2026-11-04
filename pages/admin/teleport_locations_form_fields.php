<?php
if (!defined('ALLOWED_ACCESS')) {
    exit('Forbidden');
}

$row = $teleportFormRow ?? null;
$nameVal = $row ? htmlspecialchars($row['name']) : '';
$mapVal = $row ? (int) $row['map'] : 0;
$posXVal = $row ? $row['position_x'] : '';
$posYVal = $row ? $row['position_y'] : '';
$posZVal = $row ? $row['position_z'] : '';
$orientVal = $row ? $row['orientation'] : 0;
$sortVal = $row ? (int) $row['sort_order'] : 0;
$activeChecked = $row ? ($row['is_active'] ? 'checked' : '') : 'checked';
$gmOnlyChecked = $row && !empty($row['is_gm_only']) ? 'checked' : '';
?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="form-label"><?php echo translate('admin_teleport_locations_label_name', 'Display name'); ?></label>
        <input type="text" name="name" class="input-dark" maxlength="128" required value="<?php echo $nameVal; ?>">
    </div>
    <div>
        <label class="form-label"><?php echo translate('admin_teleport_locations_label_map', 'Map ID'); ?></label>
        <input type="number" name="map" class="input-dark" min="0" max="65535" required value="<?php echo $mapVal; ?>">
    </div>
</div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div>
        <label class="form-label">X</label>
        <input type="number" step="any" name="position_x" class="input-dark" required value="<?php echo htmlspecialchars((string) $posXVal); ?>">
    </div>
    <div>
        <label class="form-label">Y</label>
        <input type="number" step="any" name="position_y" class="input-dark" required value="<?php echo htmlspecialchars((string) $posYVal); ?>">
    </div>
    <div>
        <label class="form-label">Z</label>
        <input type="number" step="any" name="position_z" class="input-dark" value="<?php echo htmlspecialchars((string) $posZVal); ?>">
    </div>
    <div>
        <label class="form-label"><?php echo translate('admin_teleport_locations_label_orientation', 'Orientation'); ?></label>
        <input type="number" step="any" name="orientation" class="input-dark" value="<?php echo htmlspecialchars((string) $orientVal); ?>">
    </div>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
    <div>
        <label class="form-label"><?php echo translate('admin_teleport_locations_label_sort', 'Sort order'); ?></label>
        <input type="number" name="sort_order" class="input-dark" value="<?php echo $sortVal; ?>">
    </div>
    <label class="flex items-center gap-2 text-sm text-gray-300 pb-2">
        <input type="checkbox" name="is_active" value="1" <?php echo $activeChecked; ?>>
        <?php echo translate('admin_teleport_locations_label_active', 'Active (shown in teleport dropdowns)'); ?>
    </label>
    <label class="flex items-center gap-2 text-sm text-gray-300 pb-2">
        <input type="checkbox" name="is_gm_only" value="1" <?php echo $gmOnlyChecked; ?>>
        <?php echo translate('admin_teleport_locations_label_gm_only', 'GM only (website moderators and administrators)'); ?>
    </label>
</div>
<p class="text-xs text-gray-500"><?php echo translate('admin_teleport_locations_coords_tip', 'Tip: Use .gps in-game to capture coordinates for custom destinations.'); ?></p>
