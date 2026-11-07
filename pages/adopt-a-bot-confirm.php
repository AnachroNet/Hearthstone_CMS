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
    .confirm-btn-secondary {
        display: inline-flex; align-items: center; justify-content: center;
        padding: 0.75rem 1rem; font-size: 0.875rem; font-weight: 700;
        color: #e5e7eb; background: rgba(0, 0, 0, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.22); border-radius: 0.5rem;
        text-decoration: none; transition: border-color 0.2s, color 0.2s, background 0.2s;
    }
    .confirm-btn-secondary:hover { color: #fff; border-color: rgba(242, 207, 82, 0.45); background: rgba(255, 255, 255, 0.05); }
    .cancel-adoption-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem;
        padding: 0.75rem 1rem; font-size: 0.875rem; font-weight: 700; width: 100%;
        background: rgba(10, 14, 22, 0.75); border: 2px solid rgba(239, 68, 68, 0.45);
        color: #fecaca; border-radius: 0.5rem; cursor: pointer;
        transition: transform 0.2s, border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .cancel-adoption-btn:hover {
        border-color: rgba(248, 113, 113, 0.85);
        background: rgba(127, 29, 29, 0.35);
        color: #fff;
        box-shadow: 0 0 18px rgba(239, 68, 68, 0.25);
    }
    .confirm-status-ready { color: #6ee7b7; }
    .confirm-status-ready i { color: #34d399; }
    .confirm-status-online { color: #fde68a; }
    .confirm-status-online i { color: #fbbf24; }
    .confirm-status-tokens { color: #fef3c7; }
    .confirm-actions { display: flex; flex-direction: column; gap: 0.75rem; }
    @media (min-width: 640px) {
        .confirm-actions-primary { display: flex; flex-direction: row; gap: 0.75rem; }
        .confirm-actions-primary > * { flex: 1; }
        .cancel-adoption-btn { width: auto; flex: 1; }
    }
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
            <p class="confirm-status-online text-sm mb-4">
                <i class="fas fa-circle-notch fa-spin mr-1"></i>
                <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_online_notice', 'This character is in the world. We will disconnect them when you confirm; that can take up to half a minute.')); ?>
            </p>
        <?php else: ?>
            <p class="confirm-status-ready text-sm mb-4">
                <i class="fas fa-check-circle mr-1"></i>
                <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_offline_ready', 'Character is offline and ready to transfer.')); ?>
            </p>
        <?php endif; ?>

        <?php if ($adoptTokenCost > 0 && !sahtout_adopt_a_bot_claim_has_purchase($pending)): ?>
            <p class="confirm-status-tokens text-sm mb-4 text-center">
                <?php echo htmlspecialchars(sprintf(
                    translate('adopt_a_bot_confirm_token_charge', 'Completing adoption will spend %s shop tokens. This cannot be undone or cancelled.'),
                    number_format($adoptTokenCost)
                )); ?>
            </p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="bg-red-900/40 border border-red-600/40 text-red-200 px-4 py-2 mb-4 text-sm"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="confirm-actions">
            <div class="confirm-actions-primary">
                <form method="post" class="flex flex-1 flex-col sm:flex-row gap-3 sm:contents">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                    <input type="hidden" name="adopt_complete" value="1">
                    <a href="<?php echo htmlspecialchars($base_path . 'adopt-a-bot', ENT_QUOTES, 'UTF-8'); ?>" class="confirm-btn-secondary">
                        <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_back', 'Choose another')); ?>
                    </a>
                    <button type="submit" class="confirm-btn-gold px-4 py-3 rounded-lg text-sm">
                        <?php echo htmlspecialchars(translate('adopt_a_bot_confirm_submit', 'Complete adoption')); ?>
                    </button>
                </form>
            </div>
            <?php if (sahtout_adopt_a_bot_may_cancel_unpaid_adoption($pending)): ?>
                <form method="post" action="<?php echo htmlspecialchars($base_path . 'adopt-a-bot-abandon', ENT_QUOTES, 'UTF-8'); ?>" class="confirm-actions-primary" onsubmit="return confirm(<?php echo json_encode(translate('adopt_a_bot_cancel_confirm', 'Cancel adoption and return to the shop? No tokens will be charged.')); ?>);">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')); ?>">
                    <button type="submit" class="cancel-adoption-btn">
                        <i class="fas fa-ban"></i>
                        <?php echo htmlspecialchars(translate('adopt_a_bot_cancel_adoption', 'Cancel Adoption')); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
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
