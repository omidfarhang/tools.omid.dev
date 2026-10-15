<?php

declare(strict_types=1);

/** @var string $active One of: overview, profile, security, cloud */
$active = $active ?? 'overview';
$items = [
    'overview' => ['label' => 'Overview', 'href' => '/user/'],
    'profile' => ['label' => 'Profile', 'href' => '/user/profile.php'],
    'security' => ['label' => 'Security', 'href' => '/user/security.php'],
    'cloud' => ['label' => 'Cloud', 'href' => '/user/cloud.php'],
];
?>
<nav class="mb-3" aria-label="Account sections">
    <ul class="nav nav-bordered">
        <?php foreach ($items as $key => $item): ?>
            <?php $isActive = $key === $active; ?>
            <li class="nav-item">
                <a class="nav-link<?= $isActive ? ' active' : '' ?>"
                   href="<?= e($item['href']) ?>"
                    <?= $isActive ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
