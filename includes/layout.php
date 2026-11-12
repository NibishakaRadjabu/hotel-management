<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$currentPage = basename($_SERVER['PHP_SELF']);
$notice = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$user = current_user();
$unreadNotifications = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadNotifications->execute([$user['id']]);
$unreadNotificationCount = (int) $unreadNotifications->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | SmartHotel</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<aside class="sidebar">
    <a class="brand" href="index.php"><span class="brand-mark">S</span><span>SmartHotel<small>MANAGEMENT</small></span></a>
    <div class="nav-caption">WORKSPACE</div>
    <nav>
        <a class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php"><span>▦</span> Dashboard</a>
        <?php if (in_array($user['role'], ['admin', 'manager', 'housekeeper', 'maintenance_officer'], true)): ?>
            <a class="<?= $currentPage === 'rooms.php' ? 'active' : '' ?>" href="rooms.php"><span>▤</span> Rooms</a>
        <?php endif; ?>
        <?php if (in_array($user['role'], ['admin', 'manager', 'receptionist', 'accountant'], true)): ?>
            <a class="<?= $currentPage === 'bookings.php' ? 'active' : '' ?>" href="bookings.php"><span>▣</span> Bookings</a>
        <?php endif; ?>
        <?php if (in_array($user['role'], ['admin', 'manager', 'receptionist'], true)): ?>
            <a class="<?= $currentPage === 'guests.php' ? 'active' : '' ?>" href="guests.php"><span>♙</span> Guests</a>
        <?php endif; ?>
        <?php if (in_array($user['role'], ['admin', 'manager'], true)): ?>
            <a class="<?= $currentPage === 'staff.php' ? 'active' : '' ?>" href="staff.php"><span>♧</span> Staff</a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
            <a class="<?= $currentPage === 'website-media.php' ? 'active' : '' ?>" href="website-media.php"><span>▧</span> Website media</a>
            <a class="<?= $currentPage === 'paypal-settings.php' ? 'active' : '' ?>" href="paypal-settings.php"><span>¤</span> PayPal settings</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-account">
        <a class="account-avatar" href="profile.php" aria-label="My profile">
            <?php if ($user['profile_image']): ?><img src="<?= e($user['profile_image']) ?>" alt=""><?php else: ?><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?><?php endif; ?>
        </a>
        <a class="account-details" href="profile.php"><strong><?= e($user['full_name']) ?></strong><small><?= e(status_label($user['role'])) ?></small></a>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="logout-button" aria-label="Sign out" title="Sign out">↪</button>
        </form>
    </div>
    <div class="sidebar-bottom"><span class="online-dot"></span> Secure staff workspace</div>
</aside>
<main class="main">
    <header class="topbar">
        <div><p class="eyebrow">HOTEL OPERATIONS</p><h1><?= e($pageTitle) ?></h1></div>
        <div class="topbar-tools">
            <a class="notification-bell" href="notifications.php" aria-label="Notifications" title="Notifications">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                <?php if ($unreadNotificationCount > 0): ?><span class="notification-count"><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?>
            </a>
            <label class="language-control"><span>Language</span><select id="language-select">
                <option value="en">English</option>
                <option value="fr">Français</option>
                <option value="sw">Kiswahili</option>
            </select></label>
            <time class="topbar-date" datetime="<?= e(date('Y-m-d')) ?>" data-localized-date><?= e(date('D, d M Y')) ?></time>
        </div>
    </header>
    <section class="content">
        <?php if ($notice): ?>
            <div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div>
        <?php endif; ?>
