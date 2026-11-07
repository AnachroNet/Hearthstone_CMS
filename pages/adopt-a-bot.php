<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/page_backgrounds.php';
require_once $project_root . 'includes/adopt_a_bot.php';

global $site_db, $char_db, $auth_db, $db_char_name, $db_auth_name, $base_path;

$page_class = 'adopt-a-bot';

if (empty($_SESSION['user_id'])) {
    header("Location: {$base_path}login?return=" . rawurlencode('adopt-a-bot'));
    exit;
}

$accountId = (int) $_SESSION['user_id'];
sahtout_adopt_a_bot_claims_ensure_schema($site_db);

$openClaim = sahtout_adopt_a_bot_get_open_claim($site_db, $accountId);
if ($openClaim === null) {
    $_SESSION['error'] = translate('adopt_a_bot_error_no_claim', 'You do not have an Adopt a Bot purchase ready to redeem.');
    header("Location: {$base_path}account");
    exit;
}
$claimProcessingInBackground = (string) ($openClaim['status'] ?? '') === 'processing';
$claim = $openClaim;

$error = '';
$search = trim((string) ($_GET['q'] ?? ''));
$search = substr($search, 0, 12);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        $error = translate('adopt_a_bot_error_csrf', 'Invalid form token. Please try again.');
    } else {
        $botGuid = (int) ($_POST['character_guid'] ?? 0);
        $selected = sahtout_adopt_a_bot_fetch_eligible_pool_character(
            $char_db,
            $auth_db,
            $site_db,
            $accountId,
            $botGuid
        );
        if ($selected === null) {
            $error = translate('adopt_a_bot_error_bot_missing', 'That character is no longer available.');
        } else {
            header('Location: ' . $base_path . 'adopt-a-bot-character?guid=' . $botGuid);
            exit;
        }
    }
}

$poolAccountIds = sahtout_adopt_a_bot_pool_account_ids($auth_db, $site_db);
$poolConfigured = $poolAccountIds !== [];
$buyerHighest = sahtout_adopt_a_bot_highest_character_level($char_db, $accountId);
$maxBotLevel = sahtout_adopt_a_bot_max_adoptable_bot_level($char_db, $accountId);
$pageSize = SAHTOUT_ADOPT_A_BOT_PICKER_PAGE_SIZE;
$totalBots = $poolConfigured
    ? sahtout_adopt_a_bot_count_pool_characters($char_db, $poolAccountIds, $search, $maxBotLevel)
    : 0;
$totalPages = $pageSize > 0 ? max(1, (int) ceil($totalBots / $pageSize)) : 1;
$page = max(1, (int) ($_GET['page'] ?? 1));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$bots = $poolConfigured
    ? sahtout_adopt_a_bot_list_pool_characters($char_db, $poolAccountIds, $search, $pageSize, $maxBotLevel, $offset)
    : [];
$canCancelDraft = !$claimProcessingInBackground && (string) ($openClaim['status'] ?? '') === 'draft';

$adoptPickerPageUrl = static function (int $targetPage) use ($base_path, $search): string {
    $params = [];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($targetPage > 1) {
        $params['page'] = (string) $targetPage;
    }
    $qs = $params !== [] ? ('?' . http_build_query($params)) : '';

    return $base_path . 'adopt-a-bot' . $qs;
};

$page_title = $site_title_name . ' — ' . translate('adopt_a_bot_page_title', 'Adopt a Bot');
$page_meta_description = translate('adopt_a_bot_meta', 'Choose a bot character to add to your account.');
$page_meta_robots = 'noindex';

ob_start();
?>
<style>
        body {
            background: url('<?php echo htmlspecialchars(sahtout_page_background_url('armory'), ENT_QUOTES, 'UTF-8'); ?>') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            padding-top: 112px;
            margin: 0;
            position: relative;
        }

        .adopt-content {
            position: relative;
            z-index: 1;
        }

        .glass-container {
            background: rgba(5, 7, 11, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(201,162,39,.22);
            border-radius: 0;
            padding: 2.5rem 2.5rem;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.8), inset 0 0 60px rgba(0,0,0,.25);
            position: relative;
        }

        .glass-container::before {
            content: ''; position: absolute; inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }

        .glass-container::after {
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

        .table-container {
            scrollbar-width: thin;
            scrollbar-color: #f2cf5b #1f2937;
            font-family: 'Arial', sans-serif;
            border-radius: 0;
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            display: block;
            width: 100%;
        }

        .table-container::-webkit-scrollbar { width: 8px; height: 8px; }
        .table-container::-webkit-scrollbar-track { background: #1f2937; border-radius: 4px; }
        .table-container::-webkit-scrollbar-thumb { background: #f2cf5b; border-radius: 4px; }
        .table-container table { min-width: 640px; width: 100%; border-collapse: collapse; }

        .table-container th, .table-container td { padding: 12px 8px; white-space: nowrap; }

        @media (max-width: 640px) {
            .table-container th, .table-container td { padding: 10px 6px; font-size: 0.75rem; }
        }

        .player-row:hover {
            background: rgba(242, 207, 82, 0.15) !important;
            transition: background-color 0.2s ease-in-out;
        }
        .player-row { background: rgba(0, 0, 0, 0.4); transition: all 0.2s ease-in-out; }

        #search-btn, .adopt-btn {
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #f2cf5b, #c9a227);
            border: 2px solid #f2cf5b;
            color: #1a1200;
            font-weight: 700;
            text-shadow: 0 1px 0 rgba(255,255,255,0.2);
        }

        #search-btn:hover, .adopt-btn:hover {
            transform: scale(1.05);
            background: linear-gradient(135deg, #f6d478, #d4b040);
            box-shadow: 0 0 30px rgba(242, 207, 82, 0.5);
            border-color: #f6d478;
        }

        #search-btn i { color: #1a1200; }

        .adopt-btn {
            font-size: 0.75rem;
            padding: 0.4rem 0.75rem;
        }

        .reset-btn {
            background: rgba(10, 14, 22, 0.6);
            border: 2px solid rgba(201, 162, 39, 0.3);
            color: rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .reset-btn:hover {
            transform: scale(1.05);
            border-color: #f2cf5b;
            background: rgba(242, 207, 82, 0.1);
            color: #f2cf5b;
            box-shadow: 0 0 20px rgba(242, 207, 82, 0.15);
        }

        .cancel-adoption-btn {
            background: rgba(10, 14, 22, 0.75);
            border: 2px solid rgba(239, 68, 68, 0.45);
            color: #fecaca;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .cancel-adoption-btn:hover {
            transform: scale(1.03);
            border-color: rgba(248, 113, 113, 0.85);
            background: rgba(127, 29, 29, 0.35);
            color: #fff;
            box-shadow: 0 0 18px rgba(239, 68, 68, 0.25);
        }

        .adopt-pagination {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.25rem;
        }
        .adopt-pagination a, .adopt-pagination span {
            min-width: 2.25rem;
            padding: 0.45rem 0.75rem;
            font-size: 0.85rem;
            font-weight: 700;
            border-radius: 0.5rem;
            text-decoration: none;
            text-align: center;
        }
        .adopt-pagination a {
            color: #f2cf5b;
            border: 1px solid rgba(201, 162, 39, 0.45);
            background: rgba(0, 0, 0, 0.45);
            transition: background 0.2s, border-color 0.2s;
        }
        .adopt-pagination a:hover {
            background: rgba(242, 207, 82, 0.15);
            border-color: #f2cf5b;
        }
        .adopt-pagination .current {
            color: #1a1200;
            background: linear-gradient(135deg, #f2cf5b, #c9a227);
            border: 1px solid #f2cf5b;
        }
        .adopt-pagination .disabled {
            opacity: 0.4;
            pointer-events: none;
            color: #9ca3af;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .adopt-pagination-summary {
            width: 100%;
            text-align: center;
            font-size: 0.8rem;
            color: #9ca3af;
            margin-bottom: 0.25rem;
        }

        .player-link { color: #ffffff; text-decoration: none; transition: color 0.2s ease; }
        .player-link:hover { text-decoration: none; color: #f2cf5b; }

        .wow-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,.9));
            letter-spacing: .02em;
        }

        @media (max-width: 767px) {
            body { padding-top: 96px; }
            .glass-container { padding: 1.5rem 0.75rem; }
        }
</style>
<?php
$page_head = ob_get_clean();
include_once $project_root . 'includes/header.php';
?>

<div class="adopt-content min-h-screen flex items-start justify-center px-4 md:px-8 py-8">
    <div class="container mx-auto max-w-7xl px-2 sm:px-4">
        <div class="glass-container relative z-[1]">
            <h1 class="wow-title text-3xl md:text-5xl font-bold text-center mb-4">
                <?php echo translate('adopt_a_bot_heading', 'Adopt a Bot'); ?>
            </h1>
            <p class="text-gray-300 mb-6 text-center text-sm md:text-base max-w-3xl mx-auto">
                <?php echo translate(
                    'adopt_a_bot_intro',
                    'Select a character from the bot pool (level %d or below; your highest character is level %d). Adoption is permanent.',
                    $maxBotLevel,
                    $buyerHighest
                ); ?>
            </p>

            <?php if ($error !== ''): ?>
                <div class="mb-4 text-center text-red-400 font-semibold text-sm"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php
            if (!empty($claimProcessingInBackground)) {
                $procGuid = (int) ($openClaim['bot_character_guid'] ?? 0);
                $procResume = $procGuid > 0 ? sahtout_adopt_a_bot_resume_url($openClaim, $base_path) : '';
                ?>
                <div class="mb-4 px-4 py-3 border border-[#c9a227]/40 bg-[rgba(242,207,82,0.1)] text-gray-200 text-sm text-center rounded-lg">
                    <?php echo htmlspecialchars(translate('adopt_a_bot_processing_background', 'An adoption is still running. Open the progress view to continue.')); ?>
                    <?php if ($procResume !== ''): ?>
                        <a href="<?php echo htmlspecialchars($procResume, ENT_QUOTES, 'UTF-8'); ?>" class="block mt-2 text-[#f2cf5b] font-semibold hover:underline"><?php echo htmlspecialchars(translate('adopt_progress_heading', 'Adoption in progress')); ?></a>
                    <?php endif; ?>
                </div>
            <?php } ?>

            <form method="get" class="mb-8 flex flex-col sm:flex-row justify-center items-stretch sm:items-center gap-3 flex-wrap">
                <input type="hidden" name="page" value="1">
                <input
                    type="text"
                    name="q"
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="<?php echo translate('adopt_a_bot_search_placeholder', 'Search by name…'); ?>"
                    maxlength="12"
                    class="w-full sm:w-80 px-4 py-2.5 rounded-lg bg-black/60 text-white border-2 border-[rgba(201,162,39,0.4)] focus:outline-none focus:border-[#f2cf5b] focus:shadow-[0_0_15px_rgba(242,207,82,0.2)] transition-all duration-300 placeholder:text-gray-400 text-sm"
                >
                <button
                    type="submit"
                    id="search-btn"
                    class="px-6 py-2.5 rounded-lg transition-all duration-300 shadow-lg shadow-[rgba(242,207,82,0.3)] flex items-center justify-center gap-2"
                >
                    <i class="fas fa-search"></i> <?php echo translate('adopt_a_bot_search', 'Search'); ?>
                </button>
                <?php if ($canCancelDraft): ?>
                    <button
                        type="submit"
                        form="adopt-cancel-draft-form"
                        class="cancel-adoption-btn px-5 py-2.5 rounded-lg flex items-center justify-center gap-2"
                        onclick="return confirm(<?php echo json_encode(translate('adopt_a_bot_cancel_confirm', 'Cancel adoption and return to the shop? No tokens will be charged.')); ?>);"
                    >
                        <i class="fas fa-ban"></i> <?php echo htmlspecialchars(translate('adopt_a_bot_cancel_adoption', 'Cancel Adoption')); ?>
                    </button>
                <?php endif; ?>
                <?php if ($search !== ''): ?>
                    <a href="<?php echo htmlspecialchars($base_path . 'adopt-a-bot', ENT_QUOTES, 'UTF-8'); ?>" class="reset-btn px-5 py-2.5 rounded-lg transition-all duration-300 flex items-center justify-center gap-2">
                        <i class="fas fa-times"></i> <?php echo translate('adopt_a_bot_reset_btn', 'Reset'); ?>
                    </a>
                <?php endif; ?>
            </form>
            <?php if ($canCancelDraft): ?>
                <form id="adopt-cancel-draft-form" method="post" action="<?php echo htmlspecialchars($base_path . 'adopt-a-bot-abandon', ENT_QUOTES, 'UTF-8'); ?>" class="hidden">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                </form>
            <?php endif; ?>

            <div class="table-container overflow-x-auto border border-[rgba(201,162,39,0.15)] shadow-2xl">
                <table class="w-full text-sm md:text-base text-center min-w-[640px]">
                    <thead class="bg-gradient-to-r from-[rgba(201,162,39,0.9)] to-[rgba(160,130,30,0.9)] text-amber-100 uppercase text-xs md:text-sm">
                        <tr>
                            <th class="py-4 px-3 md:px-6 font-bold text-left whitespace-nowrap"><?php echo translate('solo_pvp_name', 'Name'); ?></th>
                            <th class="py-4 px-3 md:px-6 font-bold whitespace-nowrap"><?php echo translate('solo_pvp_faction', 'Faction'); ?></th>
                            <th class="py-4 px-3 md:px-6 font-bold hidden md:table-cell whitespace-nowrap"><?php echo translate('solo_pvp_race', 'Race'); ?></th>
                            <th class="py-4 px-3 md:px-6 font-bold hidden md:table-cell whitespace-nowrap"><?php echo translate('solo_pvp_class', 'Class'); ?></th>
                            <th class="py-4 px-3 md:px-6 font-bold whitespace-nowrap"><?php echo translate('solo_pvp_level', 'Level'); ?></th>
                            <th class="py-4 px-3 md:px-6 font-bold whitespace-nowrap"><?php echo translate('adopt_a_bot_col_action', 'Adopt'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$poolConfigured): ?>
                            <tr>
                                <td colspan="6" class="py-8 px-4 text-lg text-amber-200 font-bold text-center">
                                    <?php echo translate('adopt_a_bot_error_pool', 'No bot pool accounts are configured for adoption.'); ?>
                                </td>
                            </tr>
                        <?php elseif (empty($bots)): ?>
                            <tr>
                                <td colspan="6" class="py-8 px-4 text-lg text-[#f2cf5b] font-bold text-center">
                                    <i class="fas fa-robot text-3xl block mb-3 text-[rgba(201,162,39,0.3)]"></i>
                                    <?php echo translate('adopt_a_bot_empty', 'No matching characters in the bot pool at level %d or below.', $maxBotLevel); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bots as $bot):
                                $guid = (int) $bot['guid'];
                                $botName = htmlspecialchars($bot['name'], ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="player-row border-b border-gray-700/30 last:border-0">
                                    <td class="py-3.5 px-3 md:px-6 text-left whitespace-nowrap">
                                        <span class="player-link font-semibold"><?php echo $botName; ?></span>
                                    </td>
                                    <td class="py-3.5 px-3 md:px-6 whitespace-nowrap">
                                        <img src="<?php echo htmlspecialchars(sahtout_adopt_a_bot_faction_icon((int) $bot['race']), ENT_QUOTES, 'UTF-8'); ?>" alt="" class="inline-block w-6 h-6 rounded-full shadow-md">
                                    </td>
                                    <td class="py-3.5 px-3 md:px-6 hidden md:table-cell whitespace-nowrap">
                                        <img src="<?php echo htmlspecialchars(sahtout_adopt_a_bot_race_icon((int) $bot['race'], (int) $bot['gender']), ENT_QUOTES, 'UTF-8'); ?>" alt="" class="inline-block w-6 h-6 rounded-full shadow-md">
                                    </td>
                                    <td class="py-3.5 px-3 md:px-6 hidden md:table-cell whitespace-nowrap">
                                        <img src="<?php echo htmlspecialchars(sahtout_adopt_a_bot_class_icon((int) $bot['class']), ENT_QUOTES, 'UTF-8'); ?>" alt="" class="inline-block w-6 h-6 rounded-full shadow-md">
                                    </td>
                                    <td class="py-3.5 px-3 md:px-6 font-bold text-[#f2cf5b] whitespace-nowrap"><?php echo (int) $bot['level']; ?></td>
                                    <td class="py-3.5 px-3 md:px-6 whitespace-nowrap">
                                        <form method="post" class="inline-block">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="character_guid" value="<?php echo $guid; ?>">
                                            <button type="submit" class="adopt-btn rounded-lg shadow-md">
                                                <?php echo translate('adopt_a_bot_select_button', 'Select'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($poolConfigured && $totalBots > 0): ?>
                <nav class="adopt-pagination" aria-label="<?php echo htmlspecialchars(translate('adopt_a_bot_pagination', 'Bot list pages')); ?>">
                    <p class="adopt-pagination-summary">
                        <?php echo htmlspecialchars(sprintf(
                            translate(
                                'adopt_a_bot_pagination_summary',
                                'Showing %1$d–%2$d of %3$d bots (page %4$d of %5$d).'
                            ),
                            min($totalBots, $offset + 1),
                            min($totalBots, $offset + count($bots)),
                            $totalBots,
                            $page,
                            $totalPages
                        )); ?>
                    </p>
                    <?php if ($page > 1): ?>
                        <a href="<?php echo htmlspecialchars($adoptPickerPageUrl(1), ENT_QUOTES, 'UTF-8'); ?>"><?php echo translate('adopt_a_bot_page_first', 'First'); ?></a>
                        <a href="<?php echo htmlspecialchars($adoptPickerPageUrl($page - 1), ENT_QUOTES, 'UTF-8'); ?>"><?php echo translate('adopt_a_bot_page_prev', 'Prev'); ?></a>
                    <?php else: ?>
                        <span class="disabled"><?php echo translate('adopt_a_bot_page_first', 'First'); ?></span>
                        <span class="disabled"><?php echo translate('adopt_a_bot_page_prev', 'Prev'); ?></span>
                    <?php endif; ?>
                    <?php
                    $windowStart = max(1, $page - 2);
                    $windowEnd = min($totalPages, $page + 2);
                    for ($p = $windowStart; $p <= $windowEnd; $p++):
                        if ($p === $page): ?>
                            <span class="current" aria-current="page"><?php echo (int) $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($adoptPickerPageUrl($p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int) $p; ?></a>
                        <?php endif;
                    endfor;
                    ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo htmlspecialchars($adoptPickerPageUrl($page + 1), ENT_QUOTES, 'UTF-8'); ?>"><?php echo translate('adopt_a_bot_page_next', 'Next'); ?></a>
                        <a href="<?php echo htmlspecialchars($adoptPickerPageUrl($totalPages), ENT_QUOTES, 'UTF-8'); ?>"><?php echo translate('adopt_a_bot_page_last', 'Last'); ?></a>
                    <?php else: ?>
                        <span class="disabled"><?php echo translate('adopt_a_bot_page_next', 'Next'); ?></span>
                        <span class="disabled"><?php echo translate('adopt_a_bot_page_last', 'Last'); ?></span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>

            <div class="mt-6 text-center text-gray-400 text-xs md:text-sm">
                <i class="fas fa-user-plus mr-2 text-[rgba(201,162,39,0.4)]"></i>
                <?php echo translate('adopt_a_bot_table_footer', 'Click Select to preview the character, then confirm on the next screens.'); ?>
            </div>
        </div>
    </div>
</div>

<?php include_once $project_root . 'includes/footer.php'; ?>
