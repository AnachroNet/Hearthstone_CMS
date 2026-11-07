<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/page_backgrounds.php';
require_once $project_root . 'includes/adopt_a_bot.php';

global $site_db, $char_db, $auth_db, $db_char_name, $db_auth_name, $base_path;

$page_class = 'adopt-a-bot-confirm';

if (empty($_SESSION['user_id'])) {
    header('Location: ' . $base_path . 'login?return=' . rawurlencode('adopt-a-bot'));
    exit;
}

$accountId = (int) $_SESSION['user_id'];
$guid = (int) ($_GET['guid'] ?? 0);
$adoptShopItemId = sahtout_adopt_a_bot_resolve_shop_item_id($site_db);
$adoptTokenCost = 0;
if ($adoptShopItemId > 0) {
    $costStmt = $site_db->prepare('SELECT token_cost FROM shop_items WHERE item_id = ? LIMIT 1');
    if ($costStmt) {
        $costStmt->bind_param('i', $adoptShopItemId);
        $costStmt->execute();
        $costRes = $costStmt->get_result();
        $costRow = $costRes ? $costRes->fetch_assoc() : null;
        $costStmt->close();
        $adoptTokenCost = (int) ($costRow['token_cost'] ?? 0);
    }
}

sahtout_adopt_a_bot_claims_ensure_schema($site_db);
$pending = sahtout_adopt_a_bot_get_pending_claim($site_db, $accountId);
if ($pending === null || $guid <= 0) {
    header('Location: ' . $base_path . 'adopt-a-bot');
    exit;
}

$bot = sahtout_adopt_a_bot_fetch_eligible_pool_character($char_db, $auth_db, $site_db, $accountId, $guid);
if ($bot === null) {
    $_SESSION['error'] = translate('adopt_a_bot_error_bot_missing', 'That character is no longer available.');
    header('Location: ' . $base_path . 'adopt-a-bot');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $_POST['csrf_token'])) {
        $error = translate('adopt_a_bot_error_csrf', 'Invalid form token. Please try again.');
    } elseif (empty($_POST['adopt_complete'])) {
        $error = translate('adopt_a_bot_error_generic', 'Adoption failed. Please try again or contact staff.');
    } else {
        $claimId = (int) $pending['claim_id'];
        if (!sahtout_adopt_a_bot_claim_has_purchase($pending)) {
            if ($adoptShopItemId <= 0) {
                $error = translate('adopt_a_bot_error_generic', 'Adoption failed. Please try again or contact staff.');
            } else {
                $charge = sahtout_adopt_a_bot_charge_claim_purchase($site_db, $accountId, $claimId, $adoptShopItemId);
                if (empty($charge['ok'])) {
                    $chargeErr = (string) ($charge['error'] ?? '');
                    if ($chargeErr === 'insufficient_funds') {
                        $_SESSION['error'] = translate('shop_insufficient_funds', 'Insufficient tokens for this purchase.');
                        header('Location: ' . $base_path . 'shop');
                        exit;
                    }
                    if ($chargeErr === 'out_of_stock') {
                        $_SESSION['error'] = translate('shop_out_of_stock', 'Out of Stock');
                        header('Location: ' . $base_path . 'shop');
                        exit;
                    }
                    $error = translate('adopt_a_bot_error_generic', 'Adoption failed. Please try again or contact staff.');
                }
            }
        }
        if ($error === '') {
            $pending = sahtout_adopt_a_bot_get_pending_claim($site_db, $accountId) ?? $pending;
        }
        if ($error !== '') {
            // fall through to form with error
        } else {
        $started = sahtout_adopt_a_bot_start_processing(
            $site_db,
            $claimId,
            $accountId,
            $guid,
            (string) $bot['name']
        );
        if ($started) {
            header('Location: ' . $base_path . 'adopt-a-bot-character?guid=' . $guid . '&step=processing');
            exit;
        }
        if (!sahtout_adopt_a_bot_claims_extended_schema($site_db)) {
            $result = sahtout_adopt_a_bot_complete_claim(
                $site_db,
                $char_db,
                $auth_db,
                $accountId,
                $claimId,
                $guid,
                $db_char_name,
                $db_auth_name
            );
            if (!empty($result['ok'])) {
                $_SESSION['message'] = translate(
                    'adopt_a_bot_success',
                    'You adopted %s! Log in to the game with that character on your account.',
                    (string) ($result['character_name'] ?? $bot['name'])
                );
                header('Location: ' . $base_path . 'account');
                exit;
            }
            $syncErr = (string) ($result['error'] ?? '');
            if ($syncErr === 'bot_online') {
                $_SESSION['error'] = translate('adopt_a_bot_error_bot_online', 'That character could not be taken offline. Wait a minute and try again, or pick another bot.');
            } else {
                $_SESSION['error'] = translate('adopt_a_bot_error_generic', 'Adoption failed. Please try again or contact staff.');
            }
            header('Location: ' . $base_path . 'adopt-a-bot-confirm?guid=' . $guid);
            exit;
        }
        $_SESSION['error'] = translate('adopt_a_bot_error_generic', 'Adoption failed. Please try again or contact staff.');
        header('Location: ' . $base_path . 'adopt-a-bot-confirm?guid=' . $guid);
        exit;
    }
        }
    }
}

$online = false;
$stmt = $char_db->prepare('SELECT online FROM characters WHERE guid = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('i', $guid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $online = !empty($row['online']);
}

$page_title = $site_title_name . ' — ' . translate('adopt_a_bot_confirm_page_title', 'Confirm adoption');
$page_meta_description = translate('adopt_a_bot_meta', 'Choose a bot character to add to your account.');
$page_meta_robots = 'noindex';

ob_start();
?>
<style>
    body {
        background: url('<?php echo sahtout_page_background_url('adopt-a-bot'); ?>') no-repeat center center fixed;
        background-size: cover;
    }
    .confirm-card {
        background: rgba(10, 8, 6, 0.92);
        border: 1px solid rgba(201, 162, 39, 0.35);
        box-shadow: 0 0 40px rgba(0, 0, 0, 0.45);
    }
    .confirm-btn-gold {
        background: linear-gradient(180deg, #f6d478 0%, #c9a227 48%, #8a6a14 100%);
        color: #1a1200;
        border: 2px solid #f2cf5b;
        font-weight: 800;
    }
    .confirm-btn-gold:hover { filter: brightness(1.05); }
</style>
<div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="confirm-card max-w-lg w-full rounded-lg p-6 md:p-8">
        <h1 class="text-2xl font-bold text-[#f2cf5b] mb-2" style="font-family: Cinzel, serif;">
            <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_heading', 'Confirm adoption')); ?>
        </h1>
        <p class="text-gray-300 text-sm mb-4">
            <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_body', 'This permanently moves the character to your account. This cannot be undone.')); ?>
        </p>

        <div class="bg-black/40 border border-gray-700/50 rounded-lg p-4 mb-4">
            <p class="text-lg font-semibold text-white"><?php echo htmlspecialchars($bot['name']); ?></p>
            <p class="text-sm text-gray-400 mt-1">
                <?php echo translate('adopt_a_bot_level', 'Level'); ?>
                <?php echo (int) $bot['level']; ?>
            </p>
        </div>

        <?php if ($online): ?>
            <p class="text-amber-200/90 text-sm mb-4">
                <i class="fas fa-circle-notch fa-spin mr-1"></i>
                <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_online_notice', 'This character is in the world. We will disconnect them when you confirm; that can take up to half a minute.')); ?>
            </p>
        <?php else: ?>
            <p class="text-emerald-300/90 text-sm mb-4">
                <i class="fas fa-check-circle mr-1"></i>
                <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_offline_ready', 'Character is offline and ready to transfer.')); ?>
            </p>
        <?php endif; ?>

        <?php if ($adoptTokenCost > 0 && !sahtout_adopt_a_bot_claim_has_purchase($pending)): ?>
            <p class="text-amber-100/90 text-sm mb-4 text-center">
                <?php echo htmlspecialchars(sprintf(
                    translate('adopt_a_bot_confirm_token_charge', 'Completing adoption will spend %s shop tokens. This cannot be undone or cancelled.'),
                    number_format($adoptTokenCost)
                )); ?>
            </p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="bg-red-900/40 border border-red-600/40 text-red-200 px-4 py-2 mb-4 text-sm"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" class="flex flex-col sm:flex-row gap-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
            <input type="hidden" name="adopt_complete" value="1">
            <a href="<?php echo htmlspecialchars($base_path . 'adopt-a-bot', ENT_QUOTES, 'UTF-8'); ?>" class="flex-1 text-center px-4 py-3 border border-gray-500/50 text-gray-200 hover:bg-white/5 rounded-lg font-semibold text-sm">
                <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_back', 'Choose another')); ?>
            </a>
            <button type="submit" class="confirm-btn-gold flex-1 px-4 py-3 rounded-lg text-sm">
                <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_submit', 'Complete adoption')); ?>
            </button>
        </form>
        <?php if ((string) ($pending['status'] ?? '') === 'draft'): ?>
            <form method="post" action="<?php echo htmlspecialchars($base_path . 'adopt-a-bot-abandon', ENT_QUOTES, 'UTF-8'); ?>" class="mt-3 text-center" onsubmit="return confirm(<?php echo json_encode(translate('adopt_a_bot_cancel_confirm', 'Cancel adoption and return to the shop? No tokens will be charged.')); ?>);">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                <button type="submit" class="text-gray-400 hover:text-gray-200 text-sm font-semibold underline-offset-2 hover:underline"><?php echo htmlspecialchars(translate('adopt_a_bot_cancel', 'Cancel adoption')); ?></button>
            </form>
        <?php endif; ?>
        <p class="text-center mt-4 text-xs text-gray-500">
            <a href="<?php echo htmlspecialchars($base_path . 'adopt-a-bot', ENT_QUOTES, 'UTF-8'); ?>" class="text-[#c9a227] hover:underline"><?php echo translate('adopt_a_bot_search', 'Search'); ?> / <?php echo translate('adopt_a_bot_heading', 'Adopt a Bot'); ?></a>
        </p>
    </div>
</div>
<?php
$content = ob_get_clean();
include $project_root . 'includes/header.php';
echo $content;
include $project_root . 'includes/footer.php';
