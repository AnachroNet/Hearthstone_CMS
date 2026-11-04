<?php

if (!defined('ALLOWED_ACCESS')) {
    exit;
}

$talent_calc_href = htmlspecialchars(rtrim($base_path, '/') . '/talent-calculator/', ENT_QUOTES, 'UTF-8');
$talent_calc_label = translate('armory_talent_calculator_btn', 'Talent Calculator');

if (!defined('ARMORY_TALENT_CALCULATOR_CTA_STYLES')) {
    define('ARMORY_TALENT_CALCULATOR_CTA_STYLES', true);
    ?>
    <style>
        .armory-talent-calc-cta {
            margin-top: 1.75rem;
            text-align: center;
        }
        .armory-talent-calc-cta .btn-game {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .95rem 2rem;
            font-weight: 800;
            font-size: .95rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            clip-path: polygon(12px 0, 100% 0, 100% calc(100% - 12px), calc(100% - 12px) 100%, 0 100%, 0 12px);
            transition: transform .2s ease, filter .2s ease;
            text-decoration: none;
        }
        .armory-talent-calc-cta .btn-game:hover {
            transform: translateY(-2px) scale(1.02);
        }
        .armory-talent-calc-cta .btn-gold {
            background: linear-gradient(180deg, #f6d478 0%, #c9a227 48%, #8a6a14 100%);
            color: #1a1200;
            text-shadow: 0 1px 0 rgba(255,255,255,.35);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.28), inset 0 -8px 14px rgba(0,0,0,.25);
        }
    </style>
    <?php
}
?>
<div class="armory-talent-calc-cta home-section-cta">
    <a href="<?php echo $talent_calc_href; ?>" class="btn-game btn-gold">
        <?php echo htmlspecialchars($talent_calc_label, ENT_QUOTES, 'UTF-8'); ?>
    </a>
</div>
