<?php
// Ensure this file is not accessed directly
if (!defined('ALLOWED_ACCESS')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access not allowed.');
}
?>

<!-- Installer Footer -->
<footer class="glass-footer w-full border-t border-[rgba(160,130,60,0.3)] py-6 px-4 text-center shadow-[0_-2px_20px_rgba(139,115,60,0.3)] font-['Cinzel'] mt-auto">
    <p class="text-[#8fbc8f] text-xs md:text-sm m-0">
        <i class="far fa-copyright mr-1"></i>
        <?= (int) date('Y') ?> Hearthstone CMS. <?= translate('footer_all_rights', 'All rights reserved.') ?>
    </p>
</footer>

<style>
    .glass-footer {
        background: rgba(60, 40, 25, 0.75);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        position: relative;
        margin-top: auto;
    }
</style>
