<?php

if (!defined('ALLOWED_ACCESS')) {
    exit('Direct access not allowed.');
}

/** @var string $tc_active_slug */
$tc_active_slug = $tc_active_slug ?? '';

?>
<nav class="tc-class-nav" aria-label="<?php echo htmlspecialchars(translate('tc_choose_class', 'Choose a class'), ENT_QUOTES, 'UTF-8'); ?>">
    <?php foreach (sahtout_talent_calculator_classes() as $class):
        $href = htmlspecialchars($tc_web_base . $class['slug'] . '.php', ENT_QUOTES, 'UTF-8');
        $label = translate($class['class_key'], $class['dir']);
        $active = ($class['slug'] === $tc_active_slug) ? ' is-active' : '';
        $icon = htmlspecialchars($tc_web_base . 'class-images/class/' . $class['slug'] . '.jpg', ENT_QUOTES, 'UTF-8');
        ?>
        <a href="<?php echo $href; ?>" class="tc-class-nav-item<?php echo $active; ?>" title="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>">
            <img src="<?php echo $icon; ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
            <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
    <?php endforeach; ?>
</nav>
