<?php
$accountPage = basename($_SERVER['PHP_SELF']);
$accountLinks = [
    'profile.php' => ['fa-solid fa-id-card', 'My Profile'],
    'change_password.php' => ['fa-solid fa-key', 'Change Password'],
    'orders.php' => ['fa-solid fa-receipt', 'My Orders'],
    'messages.php' => ['fa-solid fa-comments', 'My Messages'],
];
?>
<nav aria-label="My Account" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 1.25rem;">
    <?php foreach ($accountLinks as $href => [$icon, $label]): ?>
        <?php $isCurrent = $accountPage === $href; ?>
        <a href="<?= htmlspecialchars($href) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?> style="display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.55rem 1rem; border-radius: 12px; font-size: 0.88rem; font-weight: 700; text-decoration: none; transition: all 0.2s ease; <?= $isCurrent ? 'background: #15803d; color: #ffffff; border: 1px solid #4ade80; box-shadow: 0 0 12px rgba(74, 222, 128, 0.5);' : 'background: rgba(255, 255, 255, 0.7); color: #15803d; border: 1px solid rgba(74, 222, 128, 0.6);' ?>">
            <i class="<?= $icon ?>"></i> <?= htmlspecialchars($label) ?>
        </a>
    <?php endforeach; ?>
</nav>
