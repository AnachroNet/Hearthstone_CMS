<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/adopt_a_bot.php';

global $site_db, $char_db, $auth_db, $db_char_name, $db_auth_name;

$page_class = 'adopt-a-bot';

if (empty($_SESSION['user_id'])) {
    header("Location: {$base_path}login?return=" . rawurlencode('adopt-a-bot'));
    exit;
}

$accountId = (int) $_SESSION['user_id'];
sahtout_adopt_a_bot_claims_ensure_schema($site_db);

$claim = sahtout_adopt_a_bot_get_pending_claim($site_db, $accountId);
if (!$claim) {
    $_SESSION['error'] = translate('adopt_a_bot_error_no_claim', 'You do not have an Adopt a Bot purchase ready to redeem.');
    header("Location: {$base_path}account");
    exit;
}

$message = '';
$error = '';
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        $error = translate('adopt_a_bot_error_csrf', 'Invalid form token. Please try again.');
    } else {
        $botGuid = (int) ($_POST['character_guid'] ?? 0);
        $result = sahtout_adopt_a_bot_complete_claim(
            $site_db,
            $char_db,
            $auth_db,
            $accountId,
            (int) $claim['claim_id'],
            $botGuid,
            $db_char_name,
            $db_auth_name
        );
        if (!empty($result['ok'])) {
            $_SESSION['message'] = translate(
                'adopt_a_bot_success',
                'You adopted %s! Log in to the game with that character on your account.',
                (string) ($result['character_name'] ?? '')
            );
            header("Location: {$base_path}account");
            exit;
        }
        $errKey = (string) ($result['error'] ?? 'error');
        $errorsMap = [
            'no_slot' => translate('adopt_a_bot_error_no_slot', 'You no longer have a free character slot.'),
            'invalid_claim' => translate('adopt_a_bot_error_invalid_claim', 'This adoption session is no longer valid.'),
            'pool_missing' => translate('adopt_a_bot_error_pool', 'Bot pool account is missing.'),
            'bot_not_found' => translate('adopt_a_bot_error_bot_missing', 'That character is no longer available.'),
            'bot_online' => translate('adopt_a_bot_error_bot_online', 'That character must be offline. Try again in a moment.'),
            'transfer_failed' => translate('adopt_a_bot_error_transfer', 'Could not transfer the character. Contact staff if this persists.'),
            'level_too_high' => translate('adopt_a_bot_error_level', 'That character is above your allowed level (%d max).', sahtout_adopt_a_bot_max_adoptable_bot_level($char_db, $accountId)),
        ];
        $error = $errorsMap[$errKey] ?? translate('adopt_a_bot_error_generic', 'Adoption failed. Please try again or contact staff.');
    }
}

$poolId = sahtout_adopt_a_bot_pool_account_id($auth_db, $site_db);
$buyerHighest = sahtout_adopt_a_bot_highest_character_level($char_db, $accountId);
$maxBotLevel = sahtout_adopt_a_bot_max_adoptable_bot_level($char_db, $accountId);
$bots = $poolId > 0
    ? sahtout_adopt_a_bot_list_pool_characters($char_db, $poolId, $search, 80, $maxBotLevel)
    : [];

$page_title = $site_title_name . ' — ' . translate('adopt_a_bot_page_title', 'Adopt a Bot');
$page_meta_description = translate('adopt_a_bot_meta', 'Choose a bot character to add to your account.');
$page_meta_robots = 'noindex';

ob_start();
?>
<style>
    body {
        background: url('<?php echo sahtout_page_background_url('shop'); ?>') no-repeat center center fixed;
        background-size: cover;
    }
</style>
<?php
$page_head = ob_get_clean();
include_once $project_root . 'includes/header.php';
?>

<div class="relative z-10 max-w-4xl mx-auto px-4 py-10">
    <h1 class="text-3xl font-['Cinzel'] text-[#f2cf5b] mb-2"><?php echo translate('adopt_a_bot_heading', 'Adopt a Bot'); ?></h1>
    <p class="text-gray-300 mb-6"><?php echo translate(
        'adopt_a_bot_intro',
        'Select a character from the bot pool (level %d or below; your highest character is level %d). Adoption is permanent.',
        $maxBotLevel,
        $buyerHighest
    ); ?></p>

    <?php if ($error !== ''): ?>
        <div class="mb-4 p-4 border border-red-600/40 bg-red-900/30 text-red-100"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="get" class="mb-6 flex gap-2">
        <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo translate('adopt_a_bot_search_placeholder', 'Search by name…'); ?>"
               class="flex-1 px-4 py-2 bg-black/40 border border-[#c9a227]/30 text-gray-200 rounded-sm focus:border-[#f2cf5b] outline-none">
        <button type="submit" class="px-4 py-2 border border-[#c9a227]/40 text-[#f2cf5b] hover:bg-[#f2cf5b]/10"><?php echo translate('adopt_a_bot_search', 'Search'); ?></button>
    </form>

    <?php if ($poolId <= 0): ?>
        <p class="text-amber-200"><?php echo translate('adopt_a_bot_error_pool', 'Bot pool account is missing.'); ?></p>
    <?php elseif (empty($bots)): ?>
        <p class="text-gray-400"><?php echo translate('adopt_a_bot_empty', 'No matching characters in the bot pool at level %d or below.', $maxBotLevel); ?></p>
    <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($bots as $bot): ?>
                <form method="post" class="flex flex-wrap items-center justify-between gap-3 p-4 bg-black/40 border border-[#c9a227]/20">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="character_guid" value="<?php echo (int) $bot['guid']; ?>">
                    <div>
                        <span class="text-[#f2cf5b] font-semibold"><?php echo htmlspecialchars($bot['name']); ?></span>
                        <span class="text-gray-400 text-sm ml-2"><?php echo translate('adopt_a_bot_level', 'Level'); ?> <?php echo (int) $bot['level']; ?></span>
                    </div>
                    <button type="submit" class="px-4 py-2 text-sm border border-emerald-600/40 text-emerald-200 hover:bg-emerald-900/30"
                            onclick="return confirm(<?php echo json_encode(translate('adopt_a_bot_confirm', 'Adopt this character? This cannot be undone.')); ?>);">
                        <?php echo translate('adopt_a_bot_adopt_button', 'Adopt'); ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="mt-8 text-sm text-gray-500">
        <a href="<?php echo $base_path; ?>account" class="text-[#f2cf5b] hover:underline"><?php echo translate('adopt_a_bot_back_account', 'Back to account'); ?></a>
    </p>
</div>

<?php include_once $project_root . 'includes/footer.php'; ?>
