<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/admin_achievement_rewards.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

$page_class = 'rewards';
global $site_db;

$rewardsReady = isset($site_db) && $site_db instanceof mysqli && sahtout_admin_achievement_rewards_table_exists($site_db);
$catalogReady = $rewardsReady && sahtout_admin_achievement_catalog_table_exists($site_db);
$formAction = htmlspecialchars($base_path . 'admin/rewards', ENT_QUOTES, 'UTF-8');
$searchUrl = htmlspecialchars($base_path . 'admin/achievement_search', ENT_QUOTES, 'UTF-8');
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rewardsReady) {
    $action = (string) ($_POST['action'] ?? '');
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $flash = 'error:' . translate('error_csrf_invalid', 'Invalid CSRF token.');
    } elseif ($action === 'update') {
        $achievementId = (int) ($_POST['achievement_id'] ?? 0);
        $tokens = max(0, min(9999999, (int) ($_POST['tokens'] ?? 0)));
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        if ($achievementId > 0) {
            $stmt = $site_db->prepare(
                'UPDATE achievement_token_rewards SET tokens = ?, is_enabled = ? WHERE achievement_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('iii', $tokens, $isEnabled, $achievementId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    header('Location: ' . $base_path . 'admin/rewards?status=updated');
                    exit;
                }
            }
        }
        $flash = 'error:' . translate('admin_rewards_save_failed', 'Could not save changes.');
    } elseif ($action === 'add' && $catalogReady) {
        $achievementId = (int) ($_POST['achievement_id'] ?? 0);
        $milestoneType = sahtout_admin_normalize_milestone_type((string) ($_POST['milestone_type'] ?? ''));
        $milestoneValue = (int) ($_POST['milestone_value'] ?? 0);
        $dungeonName = trim((string) ($_POST['dungeon_name'] ?? ''));

        if ($achievementId <= 0 || $milestoneType === null) {
            $flash = 'error:' . translate('admin_rewards_validation', 'Choose a milestone type and a valid achievement.');
        } else {
            $catalog = sahtout_admin_achievement_catalog_row($site_db, $achievementId);
            if ($catalog === null) {
                $flash = 'error:' . translate('admin_rewards_unknown_achievement', 'That achievement is not in the catalog. Run the DBC import first.');
            } else {
                $instanceMapId = null;
                $milestoneValueSql = null;

                if ($milestoneType === 'level' || $milestoneType === 'quest_count') {
                    if ($milestoneValue <= 0) {
                        $critType = (int) ($catalog['criteria_type'] ?? 0);
                        $critVal = (int) ($catalog['criteria_value'] ?? 0);
                        $expectedType = $milestoneType === 'level' ? 5 : 9;
                        if ($critType === $expectedType && $critVal > 0) {
                            $milestoneValue = $critVal;
                        }
                    }
                    if ($milestoneValue <= 0) {
                        $flash = 'error:' . translate('admin_rewards_need_milestone_value', 'Enter a level or quest count for this milestone type.');
                    } else {
                        $milestoneValueSql = $milestoneValue;
                    }
                } elseif ($milestoneType === 'dungeon') {
                    global $world_db;
                    $resolved = sahtout_admin_resolve_catalog_instance_map(
                        $site_db,
                        (isset($world_db) && $world_db instanceof mysqli) ? $world_db : null,
                        $catalog
                    );
                    if ($resolved !== null && $resolved > 0) {
                        $instanceMapId = $resolved;
                    }
                }

                if ($flash === '') {
                    $check = $site_db->prepare('SELECT achievement_id FROM achievement_token_rewards WHERE achievement_id = ? LIMIT 1');
                    $check->bind_param('i', $achievementId);
                    $check->execute();
                    $exists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if ($exists) {
                        $flash = 'error:' . translate('admin_rewards_duplicate', 'This achievement already has a reward row.');
                    } else {
                        $tokens = 0;
                        $isEnabled = 0;
                        $milestoneValueParam = ($milestoneType === 'level' || $milestoneType === 'quest_count')
                            ? (int) $milestoneValueSql
                            : null;
                        $instanceMapIdParam = ($milestoneType === 'dungeon' && $instanceMapId !== null && $instanceMapId > 0)
                            ? (int) $instanceMapId
                            : null;
                        $dungeonNameParam = ($milestoneType === 'dungeon' && $dungeonName !== '')
                            ? $dungeonName
                            : null;
                        $dungeonMinParam = null;
                        $dungeonMaxParam = null;
                        $dungeonTargetParam = null;
                        if ($milestoneType === 'dungeon' && $instanceMapIdParam !== null) {
                            $lfgLevels = sahtout_lfg_dungeon_levels_for_map((int) $instanceMapIdParam);
                            if ($lfgLevels !== null) {
                                $dungeonMinParam = ($lfgLevels['min'] ?? 0) > 0 ? (int) $lfgLevels['min'] : null;
                                $dungeonMaxParam = ($lfgLevels['max'] ?? 0) > 0 ? (int) $lfgLevels['max'] : null;
                                $dungeonTargetParam = ($lfgLevels['target'] ?? 0) > 0 ? (int) $lfgLevels['target'] : null;
                            }
                        }

                        $stmt = $site_db->prepare(
                            'INSERT INTO achievement_token_rewards
                             (achievement_id, tokens, milestone_type, milestone_value, instance_map_id, dungeon_name,
                              dungeon_min_level, dungeon_max_level, dungeon_target_level, is_enabled)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        if ($stmt) {
                            $stmt->bind_param(
                                'iisiisiiii',
                                $achievementId,
                                $tokens,
                                $milestoneType,
                                $milestoneValueParam,
                                $instanceMapIdParam,
                                $dungeonNameParam,
                                $dungeonMinParam,
                                $dungeonMaxParam,
                                $dungeonTargetParam,
                                $isEnabled
                            );
                        }
                        $ok = isset($stmt) && $stmt->execute();
                        $stmt?->close();
                        if ($ok) {
                            header('Location: ' . $base_path . 'admin/rewards?status=added');
                            exit;
                        }
                        $flash = 'error:' . translate('admin_rewards_save_failed', 'Could not save changes.');
                    }
                }
            }
        }
    }
}

if (isset($_GET['status'])) {
    $flash = match ($_GET['status']) {
        'added' => 'success:' . translate('admin_rewards_added', 'Reward row added.'),
        'updated' => 'success:' . translate('admin_rewards_updated', 'Reward settings saved.'),
        default => 'success:' . translate('admin_rewards_saved', 'Changes saved.'),
    };
}

$allRewards = $rewardsReady ? sahtout_admin_fetch_achievement_rewards($site_db) : [];
global $world_db;
$rewardCreatureNames = $catalogReady
    ? sahtout_admin_creature_names_for_rewards(
        (isset($world_db) && $world_db instanceof mysqli) ? $world_db : null,
        $allRewards
    )
    : [];

$page_title = translate('admin_rewards_page_title', 'Reward Management');
$page_meta_description = translate('admin_rewards_meta', 'Configure achievement token rewards');
$page_meta_robots = 'noindex';

ob_start();
?>
<style>
        * { font-family: 'Inter', sans-serif; }
        body {
            min-height: 100vh;
            color: #d8d8d8;
            background: #05070b;
            background-image:
                radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.14), transparent 65%),
                linear-gradient(180deg, #0a0e16 0%, #060810 45%, #03040a 100%);
            background-attachment: fixed;
        }
        .wow-panel {
            position: relative;
            background: linear-gradient(180deg, rgba(22,25,32,.92), rgba(8,10,14,.9));
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 12px 32px rgba(0,0,0,.55);
        }
        .wow-title { font-family: 'Cinzel', serif; font-weight: 900; color: #f2cf5b; }
        .wow-input, .wow-select {
            width: 100%;
            padding: .65rem .85rem;
            background: rgba(0,0,0,.45);
            border: 1px solid rgba(201,162,39,.25);
            color: #e5e7eb;
        }
        .table-dark { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .table-dark th {
            text-align: left;
            padding: .75rem 1rem;
            color: #f2cf5b;
            font-size: .7rem;
            text-transform: uppercase;
            border-bottom: 1px solid rgba(201,162,39,.2);
        }
        .table-dark td { padding: .75rem 1rem; border-bottom: 1px solid rgba(201,162,39,.08); vertical-align: top; }
        .btn-game { display: inline-flex; align-items: center; gap: .4rem; padding: .55rem 1rem; font-weight: 700; font-size: .75rem; text-transform: uppercase; cursor: pointer; border: 1px solid rgba(201,162,39,.35); }
        .btn-gold { background: linear-gradient(180deg, #f6d478, #c9a227); color: #1a1200; }
        .btn-iron { background: rgba(0,0,0,.35); color: #e5e7eb; }
        .badge-on { color: #86efac; font-size: .75rem; }
        .badge-off { color: #9ca3af; font-size: .75rem; }
        .main-content-area { min-height: calc(100vh - 72px); }
        @media (min-width: 1024px) { .main-content-area.lg\:ml-70 { margin-left: 280px; } }
        .ach-search-results {
            position: absolute;
            z-index: 50;
            left: 0;
            right: 0;
            max-height: 14rem;
            overflow-y: auto;
            background: rgba(8,10,14,.98);
            border: 1px solid rgba(201,162,39,.35);
            display: none;
        }
        .ach-search-results button {
            display: block;
            width: 100%;
            text-align: left;
            padding: .5rem .75rem;
            font-size: .8rem;
            color: #e5e7eb;
            background: transparent;
            border: none;
            cursor: pointer;
        }
        .ach-search-results button:hover { background: rgba(201,162,39,.12); }
        .type-pill {
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: .2rem .45rem;
            border: 1px solid rgba(201,162,39,.25);
            color: #f2cf5b;
        }
</style>
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';

$alertHtml = '';
if ($flash !== '') {
    [$type, $msg] = explode(':', $flash, 2);
    $alertHtml = $type === 'success'
        ? '<div class="bg-green-900/40 border border-green-500/50 text-green-300 px-4 py-3 rounded-sm mb-6">' . htmlspecialchars($msg) . '</div>'
        : '<div class="bg-red-900/40 border border-red-500/50 text-red-300 px-4 py-3 rounded-sm mb-6">' . htmlspecialchars($msg) . '</div>';
}

$milestoneLabels = [
    'level' => translate('admin_rewards_type_level', 'Level'),
    'quest_count' => translate('admin_rewards_type_quest', 'Quests'),
    'dungeon' => translate('admin_rewards_type_dungeon', 'Dungeon'),
    'manual' => translate('admin_rewards_type_other', 'Other'),
];
?>
<div class="flex relative min-h-screen">
    <?php include $project_root . 'includes/admin_sidebar.php'; ?>
    <main class="main-content-area flex-1 p-4 md:p-8 lg:ml-70">
        <div class="max-w-6xl mx-auto space-y-8">
            <h1 class="wow-title text-2xl md:text-3xl"><?php echo translate('admin_rewards_heading', 'Reward Management'); ?></h1>
            <p class="text-gray-400 text-sm"><?php echo translate('admin_rewards_help', 'Link in-game achievements to website shop tokens. After import, set token amounts and enable each row. Login-time granting can be added later.'); ?></p>

            <?php echo $alertHtml; ?>

            <?php if (!$rewardsReady): ?>
                <div class="wow-panel p-6 text-amber-300 border border-amber-500/30">
                    <?php echo translate('admin_rewards_table_missing', 'Run achievement_catalog.sql on the site database first.'); ?>
                </div>
            <?php else: ?>

            <?php if (!$catalogReady): ?>
                <div class="wow-panel p-4 text-amber-300 text-sm border border-amber-500/30">
                    <?php echo translate('admin_rewards_catalog_missing', 'Import achievement_catalog data to search achievements when adding rows.'); ?>
                </div>
            <?php endif; ?>

            <div class="wow-panel p-6">
                <h2 class="text-[#f2cf5b] font-['Cinzel'] text-lg mb-4"><?php echo translate('admin_rewards_add', 'Add reward'); ?></h2>
                <form method="post" action="<?php echo $formAction; ?>" class="space-y-4" id="addRewardForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="achievement_id" id="achievement_id" value="0">

                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1" for="milestone_type"><?php echo translate('admin_rewards_col_type', 'Milestone type'); ?></label>
                            <select name="milestone_type" id="milestone_type" class="wow-select" required>
                                <option value="level"><?php echo htmlspecialchars($milestoneLabels['level']); ?></option>
                                <option value="quest_count"><?php echo htmlspecialchars($milestoneLabels['quest_count']); ?></option>
                                <option value="dungeon"><?php echo htmlspecialchars($milestoneLabels['dungeon']); ?></option>
                                <option value="other"><?php echo htmlspecialchars($milestoneLabels['manual']); ?></option>
                            </select>
                        </div>
                        <div id="milestoneValueWrap">
                            <label class="block text-xs text-gray-400 mb-1" for="milestone_value"><?php echo translate('admin_rewards_milestone_value', 'Level or quest count'); ?></label>
                            <input type="number" min="1" max="999999" name="milestone_value" id="milestone_value" class="wow-input" placeholder="80">
                        </div>
                    </div>

                    <div id="dungeonNameWrap" class="hidden">
                        <label class="block text-xs text-gray-400 mb-1" for="dungeon_name"><?php echo translate('admin_rewards_dungeon_name', 'Dungeon name (optional)'); ?></label>
                        <input type="text" name="dungeon_name" id="dungeon_name" class="wow-input" maxlength="128" placeholder="Utgarde Keep">
                    </div>

                    <div class="relative">
                        <label class="block text-xs text-gray-400 mb-1" for="achievement_search"><?php echo translate('admin_rewards_achievement', 'Achievement'); ?></label>
                        <input type="text" id="achievement_search" class="wow-input" autocomplete="off"
                               placeholder="<?php echo htmlspecialchars(translate('admin_rewards_search_placeholder', 'Search by title or achievement ID…')); ?>"
                               <?php echo $catalogReady ? '' : 'disabled'; ?>>
                        <p id="achievement_picked" class="text-sm text-gray-500 mt-1"></p>
                        <div id="achievement_results" class="ach-search-results"></div>
                    </div>

                    <button type="submit" class="btn-game btn-gold" id="addRewardBtn" disabled>
                        <i class="fas fa-plus"></i> <?php echo translate('admin_rewards_create', 'Add reward row'); ?>
                    </button>
                </form>
            </div>

            <div class="wow-panel p-6 overflow-x-auto">
                <h2 class="text-[#f2cf5b] font-['Cinzel'] text-lg mb-4"><?php echo translate('admin_rewards_list', 'Configured rewards'); ?></h2>
                <?php if ($allRewards === []): ?>
                    <p class="text-gray-500"><?php echo translate('admin_rewards_empty', 'No reward rows yet. Run the DBC import or add one above.'); ?></p>
                <?php else: ?>
                <table class="table-dark">
                    <thead>
                        <tr>
                            <th><?php echo translate('admin_rewards_col_achievement', 'Achievement'); ?></th>
                            <th><?php echo translate('admin_rewards_col_type', 'Type'); ?></th>
                            <th><?php echo translate('admin_rewards_col_detail', 'Detail'); ?></th>
                            <th><?php echo translate('admin_rewards_col_tokens', 'Tokens'); ?></th>
                            <th><?php echo translate('admin_rewards_col_enabled', 'Enabled'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allRewards as $row):
                            $achId = (int) $row['achievement_id'];
                            $type = (string) ($row['milestone_type'] ?? 'manual');
                            $title = (string) ($row['title_enus'] ?? '');
                            if ($title === '') {
                                $title = translate('admin_rewards_unknown_title', 'Achievement #%d', $achId);
                            }
                            $achDescription = trim((string) ($row['description_enus'] ?? ''));
                        ?>
                        <tr>
                            <td>
                                <div class="font-semibold text-white"><?php echo htmlspecialchars($title); ?></div>
                                <?php if ($achDescription !== ''): ?>
                                    <div class="text-xs text-gray-400 mt-1 max-w-xl"><?php echo htmlspecialchars($achDescription); ?></div>
                                <?php endif; ?>
                                <div class="text-xs text-gray-500 mt-1">ID <?php echo $achId; ?></div>
                            </td>
                            <td>
                                <span class="type-pill"><?php echo htmlspecialchars($milestoneLabels[$type] ?? $type); ?></span>
                                <?php
                                $typeSubtitle = sahtout_admin_milestone_type_subtitle($row);
                                if ($typeSubtitle !== null && $typeSubtitle !== ''):
                                ?>
                                    <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($typeSubtitle); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-gray-300 text-sm"><?php echo htmlspecialchars(sahtout_admin_reward_detail_label($row, $rewardCreatureNames)); ?></td>
                            <td colspan="3">
                                <form method="post" action="<?php echo $formAction; ?>" class="flex flex-wrap items-end gap-3 justify-end">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="achievement_id" value="<?php echo $achId; ?>">
                                    <div class="w-28">
                                        <label class="sr-only"><?php echo translate('admin_rewards_col_tokens', 'Tokens'); ?></label>
                                        <input type="number" name="tokens" class="wow-input text-sm py-2" min="0" max="9999999"
                                               value="<?php echo (int) ($row['tokens'] ?? 0); ?>">
                                    </div>
                                    <label class="flex items-center gap-2 text-sm text-gray-400 cursor-pointer">
                                        <input type="checkbox" name="is_enabled" value="1" class="rounded border-gray-600"
                                            <?php echo !empty($row['is_enabled']) ? 'checked' : ''; ?>>
                                        <?php echo !empty($row['is_enabled'])
                                            ? translate('admin_rewards_enabled', 'Enabled')
                                            : translate('admin_rewards_disabled', 'Disabled'); ?>
                                    </label>
                                    <button type="submit" class="btn-game btn-gold text-xs py-2 px-3">
                                        <?php echo translate('admin_rewards_save', 'Save'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(function () {
    const searchUrl = <?php echo json_encode($searchUrl); ?>;
    const searchInput = document.getElementById('achievement_search');
    const resultsBox = document.getElementById('achievement_results');
    const achIdInput = document.getElementById('achievement_id');
    const pickedLabel = document.getElementById('achievement_picked');
    const addBtn = document.getElementById('addRewardBtn');
    const typeSelect = document.getElementById('milestone_type');
    const valueWrap = document.getElementById('milestoneValueWrap');
    const dungeonWrap = document.getElementById('dungeonNameWrap');
    let debounceTimer = null;

    function syncTypeFields() {
        const t = typeSelect.value;
        const needsValue = t === 'level' || t === 'quest_count';
        valueWrap.classList.toggle('hidden', !needsValue);
        dungeonWrap.classList.toggle('hidden', t !== 'dungeon');
    }
    typeSelect.addEventListener('change', syncTypeFields);
    syncTypeFields();

    function setAchievement(id, title) {
        achIdInput.value = String(id);
        pickedLabel.textContent = title + ' (ID ' + id + ')';
        addBtn.disabled = false;
        resultsBox.style.display = 'none';
        searchInput.value = title;
    }

    if (searchInput && resultsBox) {
        searchInput.addEventListener('input', function () {
            achIdInput.value = '0';
            pickedLabel.textContent = '';
            addBtn.disabled = true;
            const q = searchInput.value.trim();
            if (q.length < 2) {
                resultsBox.style.display = 'none';
                return;
            }
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                fetch(searchUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        resultsBox.innerHTML = '';
                        if (!data.ok || !data.achievements || !data.achievements.length) {
                            resultsBox.style.display = 'none';
                            return;
                        }
                        data.achievements.forEach(function (a) {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.textContent = '[' + a.id + '] ' + a.title;
                            btn.addEventListener('click', function () {
                                setAchievement(a.id, a.title);
                                if (a.criteria_value && typeSelect.value === 'level' && a.criteria_type === 5) {
                                    document.getElementById('milestone_value').value = a.criteria_value;
                                }
                                if (a.criteria_value && typeSelect.value === 'quest_count' && a.criteria_type === 9) {
                                    document.getElementById('milestone_value').value = a.criteria_value;
                                }
                            });
                            resultsBox.appendChild(btn);
                        });
                        resultsBox.style.display = 'block';
                    })
                    .catch(function () { resultsBox.style.display = 'none'; });
            }, 250);
        });
        document.addEventListener('click', function (e) {
            if (!resultsBox.contains(e.target) && e.target !== searchInput) {
                resultsBox.style.display = 'none';
            }
        });
    }

    document.getElementById('addRewardForm')?.addEventListener('submit', function (e) {
        if (parseInt(achIdInput.value, 10) <= 0) {
            e.preventDefault();
        }
    });
})();
</script>

<?php include $project_root . 'includes/footer.php'; ?>
