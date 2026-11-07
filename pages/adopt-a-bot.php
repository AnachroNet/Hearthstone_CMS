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
if ((string) ($openClaim['status'] ?? '') === 'processing') {
    header('Location: ' . sahtout_adopt_a_bot_resume_url($openClaim, $base_path));
    exit;
}
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
            header('Location: ' . $base_path . 'character?guid=' . $botGuid . '&adopt=1');
            exit;
        }
    }
}

$poolAccountIds = sahtout_adopt_a_bot_pool_account_ids($auth_db, $site_db);
$poolConfigured = $poolAccountIds !== [];
$buyerHighest = sahtout_adopt_a_bot_highest_character_level($char_db, $accountId);
$maxBotLevel = sahtout_adopt_a_bot_max_adoptable_bot_level($char_db, $accountId);
$bots = $poolConfigured
    ? sahtout_adopt_a_bot_list_pool_characters($char_db, $poolAccountIds, $search, 80, $maxBotLevel)
    : [];

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

        .player-link { color: #ffffff; text-decoration: none; transition: all 0.2s ease; }
        .player-link:hover { text-decoration: underline; color: #f2cf5b; }

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

            <form method="get" class="mb-8 flex flex-col sm:flex-row justify-center items-center gap-3">
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
                    class="px-6 py-2.5 rounded-lg transition-all duration-300 shadow-lg shadow-[rgba(242,207,82,0.3)] flex items-center gap-2"
                >
                    <i class="fas fa-search"></i> <?php echo translate('adopt_a_bot_search', 'Search'); ?>
                </button>
                <?php if ($search !== ''): ?>
                    <a href="<?php echo htmlspecialchars($base_path . 'adopt-a-bot', ENT_QUOTES, 'UTF-8'); ?>" class="reset-btn px-5 py-2.5 rounded-lg transition-all duration-300 flex items-center gap-2">
                        <i class="fas fa-times"></i> <?php echo translate('adopt_a_bot_reset_btn', 'Reset'); ?>
                    </a>
                <?php endif; ?>
            </form>

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
                                $charUrl = htmlspecialchars($base_path . 'character?guid=' . $guid, ENT_QUOTES, 'UTF-8');
                                $botName = htmlspecialchars($bot['name'], ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="player-row border-b border-gray-700/30 last:border-0">
                                    <td class="py-3.5 px-3 md:px-6 text-left whitespace-nowrap">
                                        <a href="<?php echo $charUrl; ?>" class="player-link font-semibold"><?php echo $botName; ?></a>
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

            <div class="mt-6 text-center text-gray-400 text-xs md:text-sm">
                <i class="fas fa-user-plus mr-2 text-[rgba(201,162,39,0.4)]"></i>
                <?php echo translate('adopt_a_bot_table_footer', 'Open a name to preview the character, then click Select to continue.'); ?>
            </div>

            <p class="mt-6 text-center text-sm">
                <a href="<?php echo $base_path; ?>account" class="text-[#f2cf5b] hover:underline font-semibold"><?php echo translate('adopt_a_bot_back_account', 'Back to account'); ?></a>
            </p>
        </div>
    </div>
</div>

<?php include_once $project_root . 'includes/footer.php'; ?>
