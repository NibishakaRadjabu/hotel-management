<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$currentPage = basename($_SERVER['PHP_SELF']);
$notice = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$user = current_user();
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
    </nav>
    <div class="sidebar-account">
        <span class="account-avatar"><?= e(strtoupper(substr($user['full_name'], 0, 1))) ?></span>
        <span class="account-details"><strong><?= e($user['full_name']) ?></strong><small><?= e(status_label($user['role'])) ?></small></span>
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
        <div class="topbar-date"><?= e(date('D, d M Y')) ?></div>
    </header>
    <section class="content">
        <?php if ($notice): ?>
            <div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div>
        <?php endif; ?>
