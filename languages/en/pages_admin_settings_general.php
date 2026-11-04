<?php
return [
    // Page meta
    'page_description_general' => 'General Settings for Sahtout WoW Server',
    'page_title_general' => 'General Settings',

    // Messages
    'msg_settings_saved' => 'Settings updated successfully!',
    'err_fix_errors' => 'Error:',

    // Section titles
    'settings_general' => 'General Settings',

    // Website Title
    'label_website_title' => 'Website Title',
    'placeholder_site_title' => 'e.g. My Awesome Site',
    'help_site_title' => 'This title appears in the browser tab, site header, and SEO.',

    'label_site_timezone' => 'Server Timezone',
    'help_site_timezone' => 'Used for ticket timestamps and other site dates. Choose a North American zone, or server default to keep config.local.php / PHP settings.',
    'help_site_timezone_active' => 'Currently active:',
    'error_invalid_timezone' => 'Please choose a timezone from the list.',
    'timezone_option_default' => 'Use server default (config.local / PHP)',
    'timezone_option_newfoundland' => 'Newfoundland — America/St_Johns',
    'timezone_option_atlantic' => 'Atlantic — America/Halifax',
    'timezone_option_eastern' => 'Eastern — America/New_York',
    'timezone_option_central' => 'Central — America/Chicago',
    'timezone_option_mountain' => 'Mountain — America/Denver',
    'timezone_option_arizona' => 'Arizona (no DST) — America/Phoenix',
    'timezone_option_pacific' => 'Pacific — America/Los_Angeles',
    'timezone_option_alaska' => 'Alaska — America/Anchorage',
    'timezone_option_hawaii' => 'Hawaii — Pacific/Honolulu',
    'timezone_option_legacy' => '%s (saved — pick a listed zone to change)',

    // Logo
    'label_website_logo' => 'Upload Logo',
    'help_logo' => 'Upload a new logo image. PNG, JPG or SVG formats supported (max 3MB).',

    // Social Media
    'label_social_media' => 'Social Media Links',
    'placeholder_facebook' => 'Facebook URL',
    'placeholder_twitter' => 'Twitter URL',
    'placeholder_tiktok' => 'TikTok URL',
    'placeholder_youtube' => 'YouTube URL',
    'placeholder_discord' => 'https://discord.gg/your-invite',
    'label_discord_widget' => 'Discord Server Widget',
    'placeholder_discord_guild_id' => 'Discord server ID (numeric)',
    'label_discord_widget_enabled' => 'Show Discord widget on the home page',
    'help_discord_guild_id' => 'Enable the Server Widget in Discord → Server Settings → Widget, then paste your server ID here.',
    'placeholder_twitch' => 'Twitch URL',
    'placeholder_kick' => 'Kick URL',
    'placeholder_instagram' => 'Instagram URL',
    'placeholder_github' => 'GitHub URL',
    'placeholder_linkedin' => 'LinkedIn URL',
    'help_social_links' => 'Enter full profile URLs. Leave blank to hide in the footer. The Discord invite link also powers the home page Discord button.',

    'label_registration' => 'Account registration',
    'label_registration_allow_shared_email' => 'Allow multiple accounts to use the same email address',
    'help_registration_allow_shared_email' => 'Usernames must still be unique. Useful for a GM test account or family sharing one inbox. Password reset and resend activation should use the account username when several accounts share an email.',
    'label_registration_signup_tokens_enabled' => 'Grant shop tokens to new accounts at registration',
    'label_registration_signup_tokens_amount' => 'Token amount',
    'help_registration_signup_tokens' => 'When enabled, each new game account receives this many website shop tokens after signup (email activation or instant registration). Uncheck to leave new accounts at zero tokens.',
    'error_shared_email_index' => 'Could not update pending signups table for shared emails. Run SQL/SINGLE UPDATE/registration_allow_shared_email.sql on sahtout_site.',

    'label_shop_token_gifts' => 'Token gift letters (shop)',
    'label_token_gift_loot_ids' => 'Token Gift Loot IDs',
    'help_token_gift_loot_ids' => 'Gameobject loot template Entry values (chest data1 / LootId) where published letter items may drop. Comma-separated.',
    'label_token_gift_item_ref' => 'Item clone reference (world entry, default 2223 The Collector\'s Schedule)',
    'label_token_gift_quest_ref' => 'Quest clone reference (world ID, default 123; 0 = auto)',
    'label_token_gift_id_base' => 'Custom item/quest ID base',

    // Buttons
    'btn_save_settings' => 'Save All Settings',
];
?>