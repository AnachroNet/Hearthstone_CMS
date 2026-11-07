<?php
define('ALLOWED_ACCESS', true);
require_once __DIR__ . '/../../../includes/paths.php';
require_once $project_root . 'includes/session.php';
require_once $project_root . 'languages/language.php';
require_once $project_root . 'includes/config.settings.php';
require_once $project_root . 'includes/token_gift_settings.php';
require_once $project_root . 'includes/token_gift_world.php';
require_once $project_root . 'includes/adopt_a_bot.php';
require_once $project_root . 'includes/site_release.php';

global $world_db, $site_db;
$cmsVersionDisplay = '';
$cmsReleaseDisplay = 0;
if (isset($site_db) && $site_db instanceof mysqli && !$site_db->connect_error) {
    $cmsVersionDisplay = sahtout_site_version_applied($site_db);
    $cmsReleaseDisplay = sahtout_site_release_applied($site_db);
    if ($cmsVersionDisplay === '') {
        $cmsVersionDisplay = SAHTOUT_CODE_VERSION;
    }
    if ($cmsReleaseDisplay === 0) {
        $cmsReleaseDisplay = SAHTOUT_CODE_RELEASE;
    }
}
$tokenGiftWorldIdSlots = [];
$tokenGiftsFeatureEnabled = sahtout_site_token_gifts_enabled();
if ($tokenGiftsFeatureEnabled
    && isset($world_db, $site_db)
    && $world_db instanceof mysqli
    && $site_db instanceof mysqli
    && !$world_db->connect_error) {
    $tokenGiftWorldIdSlots = sahtout_token_gift_world_id_availability($site_db, $world_db);
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    header("Location: {$base_path}login");
    exit;
}

$page_class = 'general';
$page_title = translate('page_title_general', 'General Settings');
$page_meta_description = translate('page_description_general', 'General Settings for Sahtout WoW Server');
$page_meta_robots = 'noindex';
$page_body_class = 'min-h-screen text-[#d8d8d8] bg-[#05070b] bg-fixed';

ob_start();
?>
    <style>
        /* Only keep what Tailwind CANNOT do */

        /* Page background (was inline on <body>) */
        body {
            background-image:
                radial-gradient(1000px 700px at -10% 35%, rgba(59,130,246,.14), transparent 65%),
                radial-gradient(800px 600px at -5% 85%, rgba(124,58,237,.10), transparent 70%),
                linear-gradient(180deg, #0a0e16 0%, #060810 45%, #03040a 100%);
        }

        /* Font families - Tailwind can't handle font-family well inline */
        * { font-family: 'Inter', sans-serif; }
        .wow-title, .section-title, .form-label { font-family: 'Cinzel', serif; }
        
        /* Panel with gold corners AND inner border */
        .panel-gold-corners {
            position: relative;
        }
        
        /* Outer gold corner decorations */
        .panel-gold-corners::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
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
        
        /* Inner border inset */
        .panel-gold-corners::before {
            content: '';
            position: absolute;
            inset: 5px;
            border: 1px solid rgba(201,162,39,.14);
            pointer-events: none;
        }
        
        /* Custom clip-path for buttons - Tailwind doesn't support this */
        .btn-clip {
            clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px);
        }
    </style>
<?php
$page_head = ob_get_clean();

include $project_root . 'includes/header.php';
?>

    <!-- Main Content Area with Sidebar -->
    <div class="flex relative min-h-screen">
        
        <!-- Sidebar -->
        <?php include $project_root . 'includes/admin_sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content-area flex-1 p-3 sm:p-4 md:p-6 lg:p-8 transition-all duration-300 lg:ml-70">
            <div class="max-w-350 mx-auto px-1 sm:px-4 md:px-6 lg:px-8 xl:px-10">
                <div class="space-y-4 md:space-y-6 lg:space-y-8">
                    
                    <h1 class="wow-title text-2xl md:text-3xl lg:text-4xl font-black 
                               bg-[linear-gradient(180deg,#fff7d6_0%,#f2cf5b_35%,#c9a227_62%,#8a6a14_100%)]
                               bg-clip-text text-transparent drop-shadow-[0_3px_6px_rgba(0,0,0,.85)]">
                        <?php echo translate('page_title_general', 'General Settings'); ?>
                    </h1>

                    <!-- Settings Navbar -->
                    <?php include $project_root . 'pages/admin/settings/settings_navbar.php'; ?>

                    <!-- Success / Error Messages -->
                    <?php if (isset($_GET['status']) && $_GET['status'] === 'success'): ?>
                        <div class="bg-wow-success/15 border border-wow-success/40 text-wow-success 
                                    p-4 rounded-sm flex items-center gap-3">
                            <i class="fas fa-check-circle text-xl"></i>
                            <span><?php echo translate('msg_settings_saved', 'Settings updated successfully!'); ?></span>
                        </div>
                    <?php elseif (isset($_GET['status']) && $_GET['status'] === 'error'): ?>
                        <div class="bg-wow-error/15 border border-wow-error/40 text-wow-error 
                                    p-4 rounded-sm flex items-center gap-3">
                            <i class="fas fa-exclamation-circle text-xl"></i>
                            <div>
                                <strong><?php echo translate('err_fix_errors', 'Error:'); ?></strong>
                                <span><?php echo htmlspecialchars(urldecode($_GET['message'])); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- General Settings Form -->
                    <div class="relative bg-linear-to-b from-[#161920]/92 to-[#080a0e]/90 
                                border border-[#c9a227]/22 
                                shadow-[0_12px_32px_rgba(0,0,0,.55),inset_0_0_60px_rgba(0,0,0,.45)]
                                p-4 md:p-6 lg:p-8 panel-gold-corners">
                        
                        <h2 class="section-title text-lg md:text-xl mb-4 md:mb-6 flex items-center gap-3 
                                   text-[#f2cf5b] font-bold drop-shadow-[0_0_12px_rgba(201,162,39,.35),0_2px_4px_rgba(0,0,0,.8)]">
                            <i class="fas fa-cogs text-[#f2cf5b]"></i>
                            <?php echo translate('settings_general', 'General Settings'); ?>
                        </h2>

                        <form action="<?php echo $base_path; ?>pages/admin/settings/save_general.php" 
                              method="POST" 
                              enctype="multipart/form-data" 
                              class="space-y-4 md:space-y-6 max-w-3xl mx-auto">
                            
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="MAX_FILE_SIZE" value="3145728">

                            <!-- CMS version / release (read-only) -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-6">
                                <div>
                                    <span class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider block mb-2 drop-shadow-[0_0_12px_rgba(201,162,39,.15),0_2px_4px_rgba(0,0,0,.8)]">
                                        <?php echo translate('label_cms_version', 'CMS Version'); ?>
                                    </span>
                                    <div class="w-full px-4 py-3 text-[0.95rem] text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm">
                                        <?php echo htmlspecialchars($cmsVersionDisplay !== '' ? $cmsVersionDisplay : '—'); ?>
                                    </div>
                                </div>
                                <div>
                                    <span class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider block mb-2 drop-shadow-[0_0_12px_rgba(201,162,39,.15),0_2px_4px_rgba(0,0,0,.8)]">
                                        <?php echo translate('label_cms_release', 'CMS Release'); ?>
                                    </span>
                                    <div class="w-full px-4 py-3 text-[0.95rem] text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm font-mono tabular-nums">
                                        <?php echo $cmsReleaseDisplay > 0 ? htmlspecialchars((string) $cmsReleaseDisplay) : '—'; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Website Title -->
                            <div>
                                <label for="site_title_name" class="form-label text-[#f2cf5b] font-bold text-sm 
                                                                     tracking-wider block mb-2 
                                                                     drop-shadow-[0_0_12px_rgba(201,162,39,.15),0_2px_4px_rgba(0,0,0,.8)]">
                                    <?php echo translate('label_website_title', 'Website Title'); ?>
                                </label>
                                <input type="text"
                                       id="site_title_name"
                                       name="site_title_name"
                                       class="w-full px-4 py-3 text-[0.95rem] text-[#e5e7eb] 
                                              bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm 
                                              focus:border-[#f2cf5b] focus:shadow-[0_0_10px_rgba(242,207,82,.2)] 
                                              focus:bg-[#0f141e]/90 outline-none transition-all duration-200 
                                              placeholder:text-[#96aac8]/40"
                                       maxlength="40"
                                       value="<?php echo htmlspecialchars($site_title_name); ?>"
                                       placeholder="<?php echo translate('placeholder_site_title', 'e.g. My Awesome Site'); ?>"
                                       required>
                                <div class="text-[#6a7a8a] text-xs mt-1">
                                    <?php echo translate('help_site_title', 'This title appears in the browser tab, site header, and SEO.'); ?>
                                </div>
                            </div>

                            <!-- Server timezone -->
                            <div>
                                <label for="site_timezone" class="form-label text-[#f2cf5b] font-bold text-sm 
                                                                     tracking-wider block mb-2 
                                                                     drop-shadow-[0_0_12px_rgba(201,162,39,.15),0_2px_4px_rgba(0,0,0,.8)]">
                                    <?php echo translate('label_site_timezone', 'Server Timezone'); ?>
                                </label>
                                <?php
                                $tzStored = trim((string) ($sahtout_site_settings['site.timezone'] ?? ''));
                                $tzDisplay = $tzStored !== '' ? $tzStored : (isset($site_timezone) ? (string) $site_timezone : '');
                                $tzOptions = [
                                    '' => translate('timezone_option_default', 'Use server default (config.local / PHP)'),
                                    'America/St_Johns' => translate('timezone_option_newfoundland', 'Newfoundland — America/St_Johns'),
                                    'America/Halifax' => translate('timezone_option_atlantic', 'Atlantic — America/Halifax'),
                                    'America/New_York' => translate('timezone_option_eastern', 'Eastern — America/New_York'),
                                    'America/Chicago' => translate('timezone_option_central', 'Central — America/Chicago'),
                                    'America/Denver' => translate('timezone_option_mountain', 'Mountain — America/Denver'),
                                    'America/Phoenix' => translate('timezone_option_arizona', 'Arizona (no DST) — America/Phoenix'),
                                    'America/Los_Angeles' => translate('timezone_option_pacific', 'Pacific — America/Los_Angeles'),
                                    'America/Anchorage' => translate('timezone_option_alaska', 'Alaska — America/Anchorage'),
                                    'Pacific/Honolulu' => translate('timezone_option_hawaii', 'Hawaii — Pacific/Honolulu'),
                                ];
                                if ($tzStored !== '' && !array_key_exists($tzStored, $tzOptions)) {
                                    $tzOptions[$tzStored] = sprintf(
                                        translate('timezone_option_legacy', '%s (saved — pick a listed zone to change)'),
                                        $tzStored
                                    );
                                }
                                ?>
                                <select id="site_timezone"
                                        name="site_timezone"
                                        class="w-full px-4 py-3 text-[0.95rem] text-[#e5e7eb] 
                                               bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm 
                                               focus:border-[#f2cf5b] focus:shadow-[0_0_10px_rgba(242,207,82,.2)] 
                                               focus:bg-[#0f141e]/90 outline-none transition-all duration-200">
                                    <?php foreach ($tzOptions as $tzId => $tzLabel): ?>
                                        <option value="<?php echo htmlspecialchars($tzId); ?>"
                                            <?php echo $tzStored === $tzId ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tzLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="text-[#6a7a8a] text-xs mt-1">
                                    <?php echo translate('help_site_timezone', 'IANA timezone for ticket timestamps and other site dates. Leave empty to use config.local.php or PHP default.'); ?>
                                    <?php if ($tzDisplay !== ''): ?>
                                        <span class="text-[#96aac8] block mt-1">
                                            <?php echo translate('help_site_timezone_active', 'Currently active:'); ?>
                                            <?php echo htmlspecialchars($tzDisplay); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Logo Upload - Centered -->
                            <div>
                                <label class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider 
                                               block mb-2 text-center drop-shadow-[0_0_12px_rgba(201,162,39,.15),0_2px_4px_rgba(0,0,0,.8)]">
                                    <?php echo translate('label_website_logo', 'Website Logo'); ?>
                                </label>
                                
                                <!-- Current Logo Preview - Centered -->
                                <div class="flex justify-center items-center p-2 min-h-35 
                                            bg-[#0a0e16]/50 border border-[#c9a227]/20 rounded-sm mb-3">
                                    <img src="<?php echo $base_path . htmlspecialchars($site_logo); ?>" 
                                         alt="Current Logo" 
                                         class="max-h-30 max-w-full object-contain">
                                </div>
                                
                                <!-- Upload Area - Centered -->
                                <div class="border-2 border-dashed border-[#c9a227]/20 
                                            bg-[#0a0e16]/50 hover:border-[#c9a227]/40 
                                            hover:bg-[#0f141e]/70 cursor-pointer transition-all duration-300 
                                            p-8 text-center rounded-sm max-w-md mx-auto" 
                                     id="uploadArea">
                                    <input type="file" id="logo" name="logo" class="absolute w-px h-px p-0 -m-px overflow-hidden clip-[rect(0,0,0,0)] border-0" accept=".png,.jpg,.jpeg,.svg">
                                    <div id="uploadPlaceholder">
                                        <i class="fas fa-cloud-upload-alt text-4xl text-[#c9a227]/40 block mb-3"></i>
                                        <p class="text-sm text-gray-400"><?php echo translate('label_website_logo', 'Upload Logo'); ?></p>
                                        <p class="text-xs text-gray-500 mt-1">PNG, JPG or SVG (max 3MB)</p>
                                    </div>
                                    <div id="file-name" class="text-sm text-[#f2cf5b] hidden mt-2 font-semibold"></div>
                                </div>
                                <div class="text-[#6a7a8a] text-xs mt-1 text-center">
                                    <?php echo translate('help_logo', 'Upload a new logo image. PNG, JPG or SVG formats supported (max 3MB).'); ?>
                                </div>
                            </div>

                            <!-- Social Links -->
                            <div>
                                <label class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider 
                                               block mb-2 drop-shadow-[0_0_12px_rgba(201,162,39,.15),0_2px_4px_rgba(0,0,0,.8)]">
                                    <?php echo translate('label_social_media', 'Social Media Links'); ?>
                                </label>
                                
                                <?php
                                $icons = [
                                    'facebook'  => 'fab fa-facebook-f',
                                    'twitter'   => 'fab fa-x-twitter',
                                    'tiktok'    => 'fab fa-tiktok',
                                    'youtube'   => 'fab fa-youtube',
                                    'discord'   => 'fab fa-discord',
                                    'twitch'    => 'fab fa-twitch',
                                    'kick'      => 'fab fa-kickstarter',
                                    'instagram' => 'fab fa-instagram',
                                    'github'    => 'fab fa-github',
                                    'linkedin'  => 'fab fa-linkedin-in',
                                ];

                                foreach ($icons as $platform => $icon): ?>
                                    <div class="flex items-stretch mb-2">
                                        <span class="flex items-center justify-center px-4 py-3 min-w-12 
                                                     bg-[#0a0e16]/90 border border-[#c9a227]/30 border-r-0 
                                                     text-[#f2cf5b] rounded-l-sm text-base">
                                          
                                                <i class="<?php echo $icon; ?>"></i>
                                        </span>
                                        <input type="url"
                                               name="<?php echo $platform; ?>"
                                               class="flex-1 px-4 py-3 text-[0.95rem] text-[#e5e7eb] 
                                                      bg-[#0a0e16]/80 border border-[#c9a227]/30 border-l-0 rounded-r-sm 
                                                      focus:border-[#f2cf5b] focus:shadow-[0_0_10px_rgba(242,207,82,.2)] 
                                                      focus:bg-[#0f141e]/90 outline-none transition-all duration-200 
                                                      placeholder:text-[#96aac8]/40"
                                               maxlength="90"
                                               placeholder="<?php echo translate("placeholder_{$platform}", ucfirst($platform) . ' URL'); ?>"
                                               value="<?php echo htmlspecialchars($social_links[$platform] ?? ''); ?>">
                                    </div>
                                <?php endforeach; ?>
                                <div class="text-[#6a7a8a] text-xs mt-1">
                                    <?php echo translate('help_social_links', 'Enter the full URLs for your social media profiles. Leave blank to hide.'); ?>
                                </div>
                            </div>

                            <!-- Registration -->
                            <div class="pt-2 border-t border-[rgba(201,162,39,.08)]">
                                <label class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider block mb-2">
                                    <?php echo translate('label_registration', 'Account registration'); ?>
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer mb-1">
                                    <input type="checkbox" name="registration_allow_shared_email" value="1" class="rounded border-[#c9a227]/40"
                                        <?php echo !empty($sahtout_site_settings['registration.allow_shared_email']) ? 'checked' : ''; ?>>
                                    <?php echo translate('label_registration_allow_shared_email', 'Allow multiple accounts to use the same email address'); ?>
                                </label>
                                <div class="text-[#6a7a8a] text-xs mt-1">
                                    <?php echo translate('help_registration_allow_shared_email', 'Usernames must still be unique. Useful for a GM test account or family sharing one inbox. Password reset and resend activation should use the account username when several accounts share an email.'); ?>
                                </div>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer mt-3 mb-1">
                                    <input type="checkbox" name="registration_signup_tokens_enabled" value="1" id="registration_signup_tokens_enabled" class="rounded border-[#c9a227]/40"
                                        <?php echo !empty($sahtout_site_settings['registration.signup_tokens_enabled']) ? 'checked' : ''; ?>>
                                    <?php echo translate('label_registration_signup_tokens_enabled', 'Grant shop tokens to new accounts at registration'); ?>
                                </label>
                                <div class="flex flex-wrap items-center gap-2 mt-2">
                                    <label for="registration_signup_tokens_amount" class="text-sm text-gray-300">
                                        <?php echo translate('label_registration_signup_tokens_amount', 'Token amount'); ?>
                                    </label>
                                    <input type="number"
                                           name="registration_signup_tokens_amount"
                                           id="registration_signup_tokens_amount"
                                           class="w-32 px-3 py-2 text-sm text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm focus:border-[#f2cf5b] outline-none"
                                           min="0"
                                           max="9999999"
                                           value="<?php echo (int) ($sahtout_site_settings['registration.signup_tokens_amount'] ?? 0); ?>">
                                </div>
                                <div class="text-[#6a7a8a] text-xs mt-1">
                                    <?php echo translate('help_registration_signup_tokens', 'When enabled, each new game account receives this many website shop tokens after signup (email activation or instant registration). Uncheck to leave new accounts at zero tokens.'); ?>
                                </div>
                            </div>

                            <!-- Adopt a Bot -->
                            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                            <div class="pt-2 border-t border-[rgba(201,162,39,.08)] mb-4">
                                <label class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider block mb-2">
                                    <?php echo translate('label_shop_adopt_a_bot', 'Adopt a Bot (shop)'); ?>
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer mb-3">
                                    <input type="checkbox" name="shop_adopt_a_bot_enabled" value="1" class="rounded border-[#c9a227]/40"
                                        <?php echo sahtout_adopt_a_bot_enabled($site_db) ? 'checked' : ''; ?>>
                                    <?php echo translate('label_shop_adopt_a_bot_enabled', 'Enable Adopt a Bot shop service'); ?>
                                </label>
                                <p class="text-[#6a7a8a] text-xs mb-3 max-w-3xl">
                                    <?php echo translate(
                                        'help_shop_adopt_a_bot_enabled',
                                        'When enabled, adds the Adopt a Bot item (default 100 tokens; change price in A-Shop). When disabled, removes the shop row. Pool characters live on auth accounts whose usernames start with the prefix below (e.g. RNDBOT123).'
                                    ); ?>
                                </p>
                                <label for="shop_adopt_a_bot_pool_username_prefix" class="text-sm text-gray-300 block mb-1">
                                    <?php echo translate('label_shop_adopt_a_bot_pool_prefix', 'Bot account names begin with'); ?>
                                </label>
                                <input type="text" name="shop_adopt_a_bot_pool_username_prefix" id="shop_adopt_a_bot_pool_username_prefix" maxlength="32"
                                       class="w-full max-w-xs px-4 py-3 text-sm text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm focus:border-[#f2cf5b] outline-none"
                                       value="<?php echo htmlspecialchars(sahtout_adopt_a_bot_pool_username_prefix($site_db)); ?>"
                                       placeholder="<?php echo htmlspecialchars(SAHTOUT_ADOPT_A_BOT_DEFAULT_POOL_USERNAME_PREFIX); ?>">
                            </div>
                            <?php endif; ?>

                            <!-- Shop / token gifts -->
                            <div class="pt-2 border-t border-[rgba(201,162,39,.08)]">
                                <label class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider block mb-2">
                                    <?php echo translate('label_shop_token_gifts', 'Token gift letters (shop)'); ?>
                                </label>
                                <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer mb-3">
                                    <input type="checkbox" name="shop_token_gifts_enabled" value="1" class="rounded border-[#c9a227]/40"
                                        <?php echo !empty($sahtout_site_settings['shop.token_gifts_enabled']) ? 'checked' : ''; ?>>
                                    <?php echo translate('label_shop_token_gifts_enabled', 'Enable token gift letter promos (A-Shop Rewards)'); ?>
                                </label>
                                <div class="text-[#6a7a8a] text-xs mb-3 max-w-3xl">
                                    <?php echo translate(
                                        'help_shop_token_gifts_enabled',
                                        'When disabled, the Token Gifts sub-tab is hidden for everyone (including administrators), login-time granting for sealed-letter promos is skipped, and these fields are not used. If you need to recover without the UI, set features.shop_token_gifts_enabled to true or false in config.local.php (overrides the database until removed).'
                                    ); ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!$tokenGiftsFeatureEnabled): ?>
                                <p class="text-amber-300/90 text-sm mb-0"><?php echo translate(
                                    'msg_shop_token_gifts_disabled',
                                    'Token gift letters are disabled. Enable the checkbox above (administrators only) to configure loot IDs, world ID slots, and the A-Shop builder.'
                                ); ?></p>
                                <?php else: ?>
                                <?php
                                $tokenGiftAccessCurrent = sahtout_token_gift_admin_access();
                                if (($_SESSION['role'] ?? '') === 'admin'):
                                    ?>
                                    <label for="shop_token_gift_admin_access" class="text-sm text-gray-300 block mb-1">
                                        <?php echo translate('label_token_gift_admin_access', 'Token gift builder access (A-Shop)'); ?>
                                    </label>
                                    <select name="shop_token_gift_admin_access"
                                            id="shop_token_gift_admin_access"
                                            class="w-full max-w-md px-4 py-3 text-sm text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm focus:border-[#f2cf5b] outline-none mb-3">
                                        <option value="<?php echo htmlspecialchars(SAHTOUT_TOKEN_GIFT_ACCESS_ADMIN_ONLY, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $tokenGiftAccessCurrent === SAHTOUT_TOKEN_GIFT_ACCESS_ADMIN_ONLY ? ' selected' : ''; ?>>
                                            <?php echo translate('token_gift_admin_access_admin_only', 'Administrators only'); ?>
                                        </option>
                                        <option value="<?php echo htmlspecialchars(SAHTOUT_TOKEN_GIFT_ACCESS_ADMIN_AND_MODERATOR, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $tokenGiftAccessCurrent === SAHTOUT_TOKEN_GIFT_ACCESS_ADMIN_AND_MODERATOR ? ' selected' : ''; ?>>
                                            <?php echo translate('token_gift_admin_access_admin_and_moderator', 'Administrators and moderators'); ?>
                                        </option>
                                    </select>
                                    <div class="text-[#6a7a8a] text-xs mt-1 mb-3 max-w-3xl">
                                        <?php echo translate(
                                            'help_token_gift_admin_access',
                                            'Controls who sees the Token Gifts sub-tab under Shop → Rewards. Publish and full world remove stay administrator-only either way.'
                                        ); ?>
                                    </div>
                                <?php endif; ?>
                                <label for="shop_token_gift_loot_ids" class="text-sm text-gray-300 block mb-1">
                                    <?php echo translate('label_token_gift_loot_ids', 'Token Gift Loot IDs'); ?>
                                </label>
                                <input type="text"
                                       name="shop_token_gift_loot_ids"
                                       id="shop_token_gift_loot_ids"
                                       class="w-full max-w-xl px-4 py-3 text-[0.95rem] text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm focus:border-[#f2cf5b] outline-none font-mono text-sm"
                                       maxlength="500"
                                       value="<?php echo htmlspecialchars(trim((string) ($sahtout_site_settings['shop.token_gift_loot_ids'] ?? '2276,2277,2278,2279,2280,2284'))); ?>">
                                <div class="text-[#6a7a8a] text-xs mt-1 mb-3">
                                    <?php echo translate('help_token_gift_loot_ids', 'Gameobject loot template Entry values (chest data1 / LootId) where published letter items may drop. Comma-separated.'); ?>
                                </div>
                                <div class="grid sm:grid-cols-2 gap-3 max-w-xl">
                                    <div>
                                        <label for="shop_token_gift_item_template_ref" class="text-xs text-gray-400 block mb-1">
                                            <?php echo translate('label_token_gift_item_ref', 'Item clone reference (world entry)'); ?>
                                        </label>
                                        <input type="number" name="shop_token_gift_item_template_ref" id="shop_token_gift_item_template_ref" min="1" max="9999999"
                                               class="w-full px-3 py-2 text-sm text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm"
                                               value="<?php echo (int) ($sahtout_site_settings['shop.token_gift_item_template_ref'] ?? 2223); ?>">
                                    </div>
                                    <div>
                                        <label for="shop_token_gift_quest_template_ref" class="text-xs text-gray-400 block mb-1">
                                            <?php echo translate('label_token_gift_quest_ref', 'Quest clone reference (world ID, 0 = auto)'); ?>
                                        </label>
                                        <input type="number" name="shop_token_gift_quest_template_ref" id="shop_token_gift_quest_template_ref" min="0" max="9999999"
                                               class="w-full px-3 py-2 text-sm text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm"
                                               value="<?php echo (int) ($sahtout_site_settings['shop.token_gift_quest_template_ref'] ?? 123); ?>">
                                    </div>
                                </div>
                                <label for="shop_token_gift_world_ids" class="text-sm text-gray-300 block mb-1 mt-3">
                                    <?php echo translate('label_token_gift_world_ids', 'Token gift world IDs'); ?>
                                </label>
                                <input type="text"
                                       name="shop_token_gift_world_ids"
                                       id="shop_token_gift_world_ids"
                                       class="w-full max-w-xl px-4 py-3 text-[0.95rem] text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 rounded-sm focus:border-[#f2cf5b] outline-none font-mono text-sm"
                                       maxlength="500"
                                       value="<?php echo htmlspecialchars(trim((string) ($sahtout_site_settings['shop.token_gift_world_ids'] ?? sahtout_token_gift_default_world_ids_csv()))); ?>">
                                <div class="text-[#6a7a8a] text-xs mt-1 mb-3 max-w-3xl">
                                    <?php echo translate(
                                        'help_token_gift_world_ids',
                                        'Item and quest entry IDs the CMS may assign when you publish a token gift (one ID used for both). Comma-separated. Check the table below against your world database before publishing.'
                                    ); ?>
                                </div>
                                <?php if ($tokenGiftWorldIdSlots !== []): ?>
                                    <div class="max-w-3xl overflow-x-auto border border-[#c9a227]/20 rounded-sm mb-2">
                                        <table class="w-full text-xs text-left">
                                            <thead class="bg-[#0a0e16]/90 text-[#f2cf5b]">
                                                <tr>
                                                    <th class="px-3 py-2 font-semibold"><?php echo translate('token_gift_world_id_col_id', 'ID'); ?></th>
                                                    <th class="px-3 py-2 font-semibold"><?php echo translate('token_gift_world_id_col_status', 'Status'); ?></th>
                                                    <th class="px-3 py-2 font-semibold"><?php echo translate('token_gift_world_id_col_world', 'World DB'); ?></th>
                                                    <th class="px-3 py-2 font-semibold"><?php echo translate('token_gift_world_id_col_site', 'Site promo'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody class="text-gray-300 divide-y divide-[#c9a227]/10">
                                                <?php foreach ($tokenGiftWorldIdSlots as $slot): ?>
                                                    <?php
                                                    $statusKey = (string) ($slot['status'] ?? 'free');
                                                    $statusLabel = match ($statusKey) {
                                                        'in_use' => translate('token_gift_world_id_status_in_use', 'In use'),
                                                        'world_only' => translate('token_gift_world_id_status_world_only', 'World rows only (orphan)'),
                                                        default => translate('token_gift_world_id_status_free', 'Free'),
                                                    };
                                                    $worldBits = [];
                                                    if (!empty($slot['has_item'])) {
                                                        $worldBits[] = translate('token_gift_world_id_has_item', 'item');
                                                    }
                                                    if (!empty($slot['has_quest'])) {
                                                        $worldBits[] = translate('token_gift_world_id_has_quest', 'quest');
                                                    }
                                                    $worldText = $worldBits === []
                                                        ? translate('token_gift_world_id_world_empty', '—')
                                                        : implode(', ', $worldBits);
                                                    $siteText = '—';
                                                    if (!empty($slot['gift_id'])) {
                                                        $siteText = '#' . (int) $slot['gift_id'];
                                                        if (!empty($slot['gift_name'])) {
                                                            $siteText .= ' ' . htmlspecialchars((string) $slot['gift_name'], ENT_QUOTES, 'UTF-8');
                                                        }
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td class="px-3 py-2 font-mono"><?php echo (int) ($slot['world_id'] ?? 0); ?></td>
                                                        <td class="px-3 py-2"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td class="px-3 py-2"><?php echo htmlspecialchars($worldText, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td class="px-3 py-2"><?php echo $siteText; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="text-[#6a7a8a] text-xs max-w-3xl mb-2">
                                        <?php echo translate(
                                            'token_gift_world_id_verify_hint',
                                            'World rows only means item_template and/or quest_template still exist without a site promo — delete the promo in A-Shop (full remove) or clear those rows manually to free the ID.'
                                        ); ?>
                                    </p>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Discord widget (home page) -->
                            <div class="pt-2 border-t border-[rgba(201,162,39,.08)]">
                                <label class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider block mb-2">
                                    <?php echo translate('label_discord_widget', 'Discord Server Widget'); ?>
                                </label>
                                <div class="flex items-stretch mb-2">
                                    <span class="flex items-center justify-center px-4 py-3 min-w-12 bg-[#0a0e16]/90 border border-[#c9a227]/30 border-r-0 text-[#f2cf5b] rounded-l-sm">
                                        <i class="fab fa-discord"></i>
                                    </span>
                                    <input type="text"
                                           name="discord_guild_id"
                                           inputmode="numeric"
                                           pattern="[0-9]*"
                                           class="flex-1 px-4 py-3 text-[0.95rem] text-[#e5e7eb] bg-[#0a0e16]/80 border border-[#c9a227]/30 border-l-0 rounded-r-sm focus:border-[#f2cf5b] outline-none"
                                           placeholder="<?php echo translate('placeholder_discord_guild_id', 'Discord server ID (numeric)'); ?>"
                                           value="<?php echo htmlspecialchars($discord_guild_id ?? ''); ?>">
                                </div>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer mb-1">
                                    <input type="checkbox" name="discord_widget_enabled" value="1" class="rounded border-[#c9a227]/40"
                                        <?php echo !empty($discord_widget_enabled) ? 'checked' : ''; ?>>
                                    <?php echo translate('label_discord_widget_enabled', 'Show Discord widget on the home page'); ?>
                                </label>
                                <div class="text-[#6a7a8a] text-xs mt-1">
                                    <?php echo translate('help_discord_guild_id', 'Enable the Server Widget in Discord → Server Settings → Widget, then paste your server ID here.'); ?>
                                </div>
                            </div>

                            <!-- In-game breaking news (site feature) -->
                            <div class="pt-2 border-t border-[rgba(201,162,39,.08)]">
                                <label class="form-label text-[#f2cf5b] font-bold text-sm tracking-wider block mb-2">
                                    <?php echo translate('label_site_breaking_news', 'In-game breaking news'); ?>
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer mb-1">
                                    <input type="checkbox" name="site_breaking_news_enabled" value="1" class="rounded border-[#c9a227]/40"
                                        <?php echo !empty($sahtout_site_settings['site.breaking_news_enabled']) ? 'checked' : ''; ?>>
                                    <?php echo translate('label_site_breaking_news_enabled', 'Show breaking news on the home page and enable the in-game editor (Admin → News)'); ?>
                                </label>
                                <div class="text-[#6a7a8a] text-xs mt-1">
                                    <?php echo translate('help_site_breaking_news', 'When enabled, the site reads and writes the mod_breaking_news table on acore_world. That table comes from the AzerothCore Breaking News module—install it on your core if you use this feature. Turn this off if you do not use breaking news so the site skips world DB lookups for it.'); ?>
                                </div>
                            </div>

                            <!-- Save Button -->
                            <div class="pt-4 border-t border-[rgba(201,162,39,.1)] flex justify-end">
                                <button type="submit" class="btn-clip inline-flex items-center gap-2 px-6 py-3 
                                                             font-extrabold text-xs uppercase tracking-wider
                                                             bg-linear-to-b from-[#f6d478] via-[#c9a227] to-[#8a6a14] 
                                                             text-[#1a1200] shadow-[inset_0_0_0_1px_rgba(255,255,255,.28),inset_0_-8px_14px_rgba(0,0,0,.25)]
                                                             hover:scale-105 transition-transform duration-200">
                                    <i class="fas fa-save"></i>
                                    <?php echo translate('btn_save_settings', 'Save All Settings'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Logo upload area
            const uploadArea = document.getElementById('uploadArea');
            const logoInput = document.getElementById('logo');
            const uploadPlaceholder = document.getElementById('uploadPlaceholder');
            const fileName = document.getElementById('file-name');

            if (uploadArea && logoInput) {
                uploadArea.addEventListener('click', () => logoInput.click());

                uploadArea.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    uploadArea.classList.add('border-[#f2cf5b]', 'bg-[#c9a227]/10');
                });

                uploadArea.addEventListener('dragleave', () => {
                    uploadArea.classList.remove('border-[#f2cf5b]', 'bg-[#c9a227]/10');
                });

                uploadArea.addEventListener('drop', (e) => {
                    e.preventDefault();
                    uploadArea.classList.remove('border-[#f2cf5b]', 'bg-[#c9a227]/10');
                    if (e.dataTransfer.files.length) {
                        logoInput.files = e.dataTransfer.files;
                        logoInput.dispatchEvent(new Event('change'));
                    }
                });

                logoInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        const file = this.files[0];
                        const maxSize = 3 * 1024 * 1024;
                        const allowedTypes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/svg'];

                        if (!allowedTypes.includes(file.type) && !file.name.match(/\.(png|jpg|jpeg|svg)$/i)) {
                            alert('Invalid file type. Please upload PNG, JPG, or SVG.');
                            this.value = '';
                            return;
                        }
                        if (file.size > maxSize) {
                            alert('File size exceeds 3MB limit.');
                            this.value = '';
                            return;
                        }

                        fileName.textContent = 'Selected: ' + file.name;
                        fileName.classList.remove('hidden');
                        uploadPlaceholder.style.display = 'none';
                    } else {
                        fileName.classList.add('hidden');
                        uploadPlaceholder.style.display = 'block';
                    }
                });
            }

            const signupTokensEnabled = document.getElementById('registration_signup_tokens_enabled');
            const signupTokensAmount = document.getElementById('registration_signup_tokens_amount');
            function syncSignupTokensAmountField() {
                if (!signupTokensEnabled || !signupTokensAmount) {
                    return;
                }
                const on = signupTokensEnabled.checked;
                signupTokensAmount.readOnly = !on;
                signupTokensAmount.classList.toggle('opacity-50', !on);
            }
            if (signupTokensEnabled) {
                signupTokensEnabled.addEventListener('change', syncSignupTokensAmountField);
                syncSignupTokensAmountField();
            }
        });
    </script>
</body>
</html>