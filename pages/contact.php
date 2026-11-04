<?php
define('ALLOWED_ACCESS', true);

require_once __DIR__ . '/../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/redirect_helpers.php';
require_once $project_root . 'includes/user_inquiries.php';

global $site_db;

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $base_path . 'login?return=' . rawurlencode('contact'));
    exit;
}

$page_class = 'contact page-contact';
$page_title = translate('contact_page_title', 'Contact Us');
$page_meta_description = translate('contact_meta_description', 'Send a message to server staff');
$page_meta_robots = 'noindex';

$accountId = (int) $_SESSION['user_id'];
$accountUsername = (string) ($_SESSION['username'] ?? '');
$message = '';
$error = '';
$nowDisplay = date('Y-m-d H:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $error = translate('contact_csrf_error', 'Security token validation failed. Please try again.');
    } elseif (!isset($site_db) || !($site_db instanceof mysqli) || $site_db->connect_error) {
        $error = translate('contact_unavailable', 'Contact submissions are not available right now.');
    } else {
        $subject = (string) ($_POST['subject'] ?? '');
        $body = (string) ($_POST['message'] ?? '');
        $result = sahtout_user_inquiry_create($site_db, $accountId, $accountUsername, $subject, $body);

        if (!$result['ok']) {
            $error = match ($result['message'] ?? '') {
                'table_missing' => translate('contact_table_missing', 'Contact storage is not set up yet. Please try again later or use in-game support.'),
                'invalid_subject' => translate('contact_invalid_subject', 'Enter a subject (255 characters or fewer).'),
                'invalid_message' => translate('contact_invalid_message', 'Enter a message (20,000 characters or fewer).'),
                default => translate('contact_submit_failed', 'Could not submit your message. Please try again.'),
            };
        } else {
            $message = translate('contact_submit_success', 'Your message was sent. Staff will reply on your Account Dashboard under Inquiries.');
        }
    }
}

ob_start();
?>
<style>
        body {
            background: url('<?php echo $base_path; ?>img/backgrounds/bg-login.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            padding-top: 112px;
            margin: 0;
            position: relative;
        }
        body.page-contact::before {
            display: none;
        }

        .contact-content .glass-container {
            position: relative;
            background: rgba(5, 7, 11, 0.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(201,162,39,.22);
            box-shadow: 0 20px 40px -12px rgba(0,0,0,.8), inset 0 0 60px rgba(0,0,0,.25);
            width: 100%;
        }
        .contact-content .glass-container::before {
            content: '';
            position: absolute;
            inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
            z-index: 0;
        }
        .contact-content .glass-container::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background:
                linear-gradient(#e8c552, #e8c552) left top / 18px 2px,
                linear-gradient(#e8c552, #e8c552) left top / 2px 18px,
                linear-gradient(#e8c552, #e8c552) right top / 18px 2px,
                linear-gradient(#e8c552, #e8c552) right top / 2px 18px,
                linear-gradient(#e8c552, #e8c552) left bottom / 18px 2px,
                linear-gradient(#e8c552, #e8c552) left bottom / 2px 18px,
                linear-gradient(#e8c552, #e8c552) right bottom / 18px 2px,
                linear-gradient(#e8c552, #e8c552) right bottom / 2px 18px;
            background-repeat: no-repeat;
        }
        .contact-content .contact-panel-body {
            position: relative;
            z-index: 1;
        }

        .contact-content .contact-framed-panel {
            position: relative;
            margin-top: 0.25rem;
            padding: 1.35rem 1.25rem;
            background: rgba(0, 0, 0, 0.28);
            border: 1px solid rgba(201, 162, 39, 0.16);
            box-shadow: inset 0 0 40px rgba(0, 0, 0, 0.35);
        }
        .contact-content .contact-framed-panel::before {
            content: '';
            position: absolute;
            inset: 4px;
            border: 1px solid rgba(201, 162, 39, 0.1);
            pointer-events: none;
        }
        .contact-content .contact-framed-panel::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(#c9a227, #c9a227) left top / 12px 2px,
                linear-gradient(#c9a227, #c9a227) left top / 2px 12px,
                linear-gradient(#c9a227, #c9a227) right top / 12px 2px,
                linear-gradient(#c9a227, #c9a227) right top / 2px 12px,
                linear-gradient(#c9a227, #c9a227) left bottom / 12px 2px,
                linear-gradient(#c9a227, #c9a227) left bottom / 2px 12px,
                linear-gradient(#c9a227, #c9a227) right bottom / 12px 2px,
                linear-gradient(#c9a227, #c9a227) right bottom / 2px 12px;
            background-repeat: no-repeat;
        }
        .contact-content .contact-framed-panel > * {
            position: relative;
            z-index: 1;
        }

        .contact-content .contact-response-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        @media (max-width: 767px) {
            body.page-contact {
                padding-top: 96px;
            }
        }
        .contact-field-readonly {
            background: rgba(0,0,0,.45);
            border: 1px solid rgba(201,162,39,.15);
            color: #d1d5db;
        }
        .contact-input {
            background: rgba(0,0,0,.55);
            border: 1px solid rgba(201,162,39,.25);
            color: #f3f4f6;
            width: 100%;
            padding: 0.75rem 1rem;
        }
        .contact-input:focus {
            outline: none;
            border-color: rgba(242,207,82,.55);
            box-shadow: 0 0 0 1px rgba(242,207,82,.25);
        }
        textarea.contact-input { min-height: 10rem; resize: vertical; }

        .contact-content .wow-title {
            font-family: 'Cinzel', serif;
            font-weight: 900;
            background: linear-gradient(180deg, #fff7d6 0%, #f2cf5b 35%, #c9a227 62%, #8a6a14 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.9));
            letter-spacing: 0.02em;
        }

        .contact-content .btn-gold {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            transition: transform 0.2s ease, filter 0.2s ease;
            background: linear-gradient(180deg, #f6d478 0%, #c9a227 48%, #8a6a14 100%) !important;
            color: #1a1200 !important;
            text-shadow: 0 1px 0 rgba(255, 255, 255, 0.35);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.28), inset 0 -8px 14px rgba(0, 0, 0, 0.25);
            border: none;
            cursor: pointer;
        }
        .contact-content .btn-gold:hover {
            transform: translateY(-2px) scale(1.02);
            filter: drop-shadow(0 0 12px rgba(242, 207, 82, 0.5));
        }

        .contact-content .btn-iron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            font-weight: 800;
            font-size: 0.85rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
            transition: transform 0.2s ease, filter 0.2s ease;
            background: linear-gradient(180deg, #3a404d 0%, #232833 55%, #141821 100%) !important;
            color: #cfe1ff !important;
            box-shadow: inset 0 0 0 1px rgba(120, 160, 255, 0.25), inset 0 -8px 14px rgba(0, 0, 0, 0.4);
            border: none;
            text-decoration: none;
        }
        .contact-content .btn-iron:hover {
            transform: translateY(-2px) scale(1.02);
            filter: drop-shadow(0 0 12px rgba(59, 130, 246, 0.4));
            color: #e8f0ff !important;
        }

        .contact-content .contact-form-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 0.75rem;
            padding-top: 0.5rem;
        }
</style>
<?php
$page_head = ob_get_clean();
require_once $project_root . 'includes/header.php';
?>

<div class="contact-content relative z-10 min-h-screen flex items-start justify-center px-4 py-8">
    <div class="container mx-auto max-w-2xl">
        <div class="glass-container p-6 md:p-10">
            <div class="contact-panel-body">
            <div class="flex items-center gap-3 mb-2">
                <i class="fa-solid fa-envelope-open-text text-3xl text-[#f2cf5b]"></i>
                <h1 class="wow-title text-3xl md:text-4xl font-bold"><?php echo translate('contact_title', 'Contact Us'); ?></h1>
            </div>
            <p class="text-gray-400 text-sm mb-6 max-w-xl">
                <?php echo translate('contact_intro', 'Report website issues, ask account questions, or reach staff without logging into the game. Replies appear on your Account Dashboard.'); ?>
            </p>

            <?php if ($message !== ''): ?>
                <div class="contact-framed-panel">
                    <div class="bg-green-900/40 border border-green-600/40 text-green-200 px-5 py-3 flex items-center gap-3">
                        <i class="fa-solid fa-circle-check text-green-400 shrink-0"></i>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                    <div class="contact-response-actions">
                        <a href="<?php echo htmlspecialchars($base_path . 'account', ENT_QUOTES, 'UTF-8'); ?>" class="btn-iron px-6 py-2.5">
                            <?php echo translate('contact_go_account', 'View your inquiries on the Account Dashboard'); ?>
                        </a>
                        <a href="<?php echo htmlspecialchars($base_path . 'contact', ENT_QUOTES, 'UTF-8'); ?>" class="btn-gold px-6 py-2.5">
                            <?php echo translate('contact_send_another', 'Send another message'); ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="contact-framed-panel mb-4">
                    <div class="bg-red-900/40 border border-red-600/40 text-red-200 px-5 py-3 flex items-center gap-3">
                        <i class="fa-solid fa-circle-exclamation text-red-400 shrink-0"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($message === ''): ?>
            <div class="contact-framed-panel">
            <form method="post" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-1.5"><?php echo translate('contact_label_datetime', 'Date / time'); ?></label>
                    <input type="text" readonly class="contact-field-readonly w-full px-4 py-2.5 font-mono text-sm" value="<?php echo htmlspecialchars($nowDisplay); ?>">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-1.5"><?php echo translate('contact_label_account', 'Account'); ?></label>
                    <input type="text" readonly class="contact-field-readonly w-full px-4 py-2.5 text-sm" value="<?php echo htmlspecialchars($accountUsername); ?>">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-1.5" for="contact-subject"><?php echo translate('contact_label_subject', 'Subject'); ?></label>
                    <input id="contact-subject" name="subject" type="text" maxlength="255" required class="contact-input" value="<?php echo htmlspecialchars((string) ($_POST['subject'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[#f2cf5b] mb-1.5" for="contact-message"><?php echo translate('contact_label_message', 'Message'); ?></label>
                    <textarea id="contact-message" name="message" maxlength="20000" required class="contact-input"><?php echo htmlspecialchars((string) ($_POST['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="contact-form-actions">
                    <button type="submit" class="btn-gold px-6 py-2.5">
                        <?php echo translate('contact_submit', 'Send message'); ?>
                    </button>
                    <a href="<?php echo htmlspecialchars($base_path . 'account', ENT_QUOTES, 'UTF-8'); ?>" class="btn-iron px-6 py-2.5">
                        <?php echo translate('contact_cancel', 'Account Dashboard'); ?>
                    </a>
                </div>
            </form>
            </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once $project_root . 'includes/footer.php';
