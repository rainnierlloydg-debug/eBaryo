<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Barangay Services</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/app.js" defer></script>
</head>
<body class="<?= $protected ? 'workspace' : 'public-page' ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php if ($protected): ?>
<aside class="sidebar">
    <a class="brand" href="index.php?page=dashboard"><span class="brand-mark">B</span><span>Barangay<span class="brand-small">RESIDENT SERVICES</span></span></a>
    <nav aria-label="Resident navigation">
        <?php foreach (['dashboard' => 'Dashboard', 'request' => 'Start Request', 'history' => 'Request History', 'notifications' => 'Notifications', 'profile' => 'My Profile', 'settings' => 'Settings'] as $target => $label): ?>
        <a href="index.php?page=<?= e($target) ?>" class="nav-link <?= $page === $target ? 'active' : '' ?>" <?= $page === $target ? 'aria-current="page"' : '' ?>><?= e($label) ?><span aria-hidden="true">↗</span></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom"><span class="avatar"><?= e(mb_substr($user['first_name'], 0, 1)) ?></span><div><strong><?= e($user['first_name'].' '.$user['last_name']) ?></strong><small>Resident</small></div></div>
    <button class="logout-link" type="button" data-open="logout-dialog">Logout <span aria-hidden="true">↗</span></button>
</aside>
<div class="main-shell"><header class="topbar"><span>RESIDENT PORTAL</span><span><?= e($user['barangay']) ?></span></header>
<main id="main" class="content"><div class="page-heading"><p class="eyebrow">BARANGAY DOCUMENT REQUEST SYSTEM</p><h1><?= e($title) ?></h1></div>
<?php else: ?>
<header class="public-header"><a class="brand" href="index.php"><span class="brand-mark">B</span><span>Barangay<span class="brand-small">DOCUMENT REQUEST SYSTEM</span></span></a><span class="header-note">Your community, connected.</span></header>
<main id="main" class="public-main">
<?php endif; ?>
