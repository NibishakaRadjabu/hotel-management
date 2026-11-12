<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/paypal.php';
$user = require_login();
expire_online_booking_payments();
$role = $user['role'];
$canSeeOperations = in_array($role, ['admin', 'manager', 'receptionist'], true);
$canSeeFinancials = in_array($role, ['admin', 'manager', 'accountant'], true);
$canSeeRoomMap = in_array($role, ['admin', 'manager', 'receptionist', 'housekeeper', 'maintenance_officer'], true);

$today = date('Y-m-d');
$totalRooms = 0;
$availableRooms = 0;
$occupied = 0;
$pending = 0;
$guestsInHouse = 0;
$revenueToday = 0.0;
$arrivalsToday = 0;
$roomRows = [];
$recentBookings = [];

if ($canSeeRoomMap) {
    $roomQuery = "SELECT r.id, r.room_number, r.status,
        CASE WHEN b.id IS NOT NULL THEN 'occupied' ELSE r.status END AS display_status,
        b.id AS active_booking_id
     FROM rooms r
     LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'checked_in'";
    $roomParameters = [];
    if ($role === 'housekeeper') {
        $roomQuery .= ' WHERE EXISTS (SELECT 1 FROM room_assignments ra WHERE ra.room_id = r.id AND ra.user_id = ?)';
        $roomParameters[] = $user['id'];
    }
    $roomQuery .= ' ORDER BY r.floor, r.room_number';
    $roomStatement = $pdo->prepare($roomQuery);
    $roomStatement->execute($roomParameters);
    $roomRows = $roomStatement->fetchAll();
    $totalRooms = count($roomRows);
    foreach ($roomRows as $room) {
        if (in_array($room['display_status'], ['available', 'cleaned'], true)) {
            $availableRooms++;
        }
        if ($room['display_status'] === 'occupied') {
            $occupied++;
        }
    }
    $guestsInHouse = $occupied;
}

if ($canSeeOperations) {
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
    $arrivalStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM bookings WHERE checkin_date = ? AND status IN ('pending', 'confirmed')"
    );
    $arrivalStatement->execute([$today]);
    $arrivalsToday = (int) $arrivalStatement->fetchColumn();
    $recentBookings = $pdo->query(
    'SELECT b.id, b.checkin_date, b.checkout_date, b.total_amount, b.status,
            g.full_name, r.room_number
     FROM bookings b
     JOIN guests g ON g.id = b.guest_id
     JOIN rooms r ON r.id = b.room_id
     ORDER BY b.created_at DESC
     LIMIT 6'
)->fetchAll();
}

if ($canSeeFinancials) {
    $revenueStatement = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM payments
         WHERE DATE(payment_date) = ? AND payment_method <> 'test'"
    );
    $revenueStatement->execute([$today]);
    $revenueToday = (float) $revenueStatement->fetchColumn();
}
$pageTitle = 'Dashboard';
require __DIR__ . '/includes/layout.php';
?>
<div class="welcome-row">
    <div><h2>Good day, <?= e(status_label($role)) ?> <span class="wave">✦</span></h2><p><?= $role === 'housekeeper' ? 'Your assigned room tasks.' : ($role === 'maintenance_officer' ? 'Room readiness and maintenance status.' : "Here is what's happening at your hotel today.") ?></p></div>
    <?php if (in_array($role, ['admin', 'manager', 'receptionist'], true)): ?><a class="button button-primary" href="bookings.php#new-booking">＋ New booking</a><?php endif; ?>
</div>
<div class="stats-grid">
    <?php if ($canSeeRoomMap): ?>
    <article class="stat-card"><div class="stat-top"><span><?= $role === 'housekeeper' ? 'Assigned rooms' : 'Total rooms' ?></span><span class="stat-icon violet">▤</span></div><strong><?= $totalRooms ?></strong><small><?= $role === 'housekeeper' ? 'Assigned to your team' : 'Across hotel floors' ?></small></article>
    <?php endif; ?>
    <?php if ($canSeeOperations): ?>
    <article class="stat-card"><div class="stat-top"><span>Occupied</span><span class="stat-icon coral">▣</span></div><strong><?= $occupied ?></strong><small>Guests staying now</small></article>
    <article class="stat-card"><div class="stat-top"><span>Available</span><span class="stat-icon green">✓</span></div><strong><?= $availableRooms ?></strong><small>Ready to check in</small></article>
    <?php endif; ?>
    <?php if ($canSeeFinancials): ?>
    <article class="stat-card"><div class="stat-top"><span>Revenue today</span><span class="stat-icon gold">↗</span></div><strong class="stat-money"><?= e(money($revenueToday)) ?></strong><small>Recorded payments</small></article>
    <?php endif; ?>
</div>
<?php if ($canSeeRoomMap || $canSeeOperations): ?>
<div class="dashboard-grid">
    <?php if ($canSeeRoomMap): ?>
    <section class="panel room-panel">
        <div class="panel-heading"><div><h2><?= $role === 'housekeeper' ? 'Your assigned rooms' : 'Room map' ?></h2><p>Live room status at a glance</p></div><?php if (in_array($role, ['admin', 'manager', 'housekeeper', 'maintenance_officer'], true)): ?><a class="text-link" href="rooms.php"><?= in_array($role, ['admin', 'manager'], true) ? 'Manage rooms →' : 'Update room status →' ?></a><?php endif; ?></div>
        <div class="legend">
            <span><i class="legend-dot available"></i> Available / Cleaned</span>
            <span><i class="legend-dot occupied"></i> Occupied</span>
            <span><i class="legend-dot cleaning"></i> Cleaning</span>
            <span><i class="legend-dot maintenance"></i> Maintenance</span>
        </div>
        <?php if (!$roomRows): ?>
            <div class="empty-state"><strong><?= $role === 'housekeeper' ? 'No rooms assigned yet' : 'No rooms added yet' ?></strong><p><?= $role === 'housekeeper' ? 'Ask your manager to assign rooms to you.' : 'Add your rooms to see the live map.' ?></p><?php if (in_array($role, ['admin', 'manager'], true)): ?><a class="button button-secondary" href="rooms.php">Add a room</a><?php endif; ?></div>
        <?php else: ?>
            <div class="room-map">
                <?php foreach ($roomRows as $room): ?>
                    <div class="room-tile status-<?= e($room['display_status']) ?>">
                        <strong><?= e($room['room_number']) ?></strong>
                        <small><?= e(status_label($room['display_status'])) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($canSeeOperations): ?>
    <section class="panel overview-panel">
        <div class="panel-heading"><div><h2>Today's overview</h2><p>Front desk activity</p></div><span class="today-badge">TODAY</span></div>
        <div class="overview-row"><span class="overview-icon blue">↘</span><span>Expected arrivals</span><strong><?= $arrivalsToday ?></strong></div>
        <div class="overview-row"><span class="overview-icon orange">◷</span><span>Pending bookings</span><strong><?= $pending ?></strong></div>
        <div class="overview-row"><span class="overview-icon pink">♙</span><span>Guests in-house</span><strong><?= $guestsInHouse ?></strong></div>
        <div class="occupancy">
            <?php $occupancy = $totalRooms > 0 ? min(100, (int) round($occupied * 100 / $totalRooms)) : 0; ?>
            <div><span>Room occupancy</span><strong><?= $occupancy ?>%</strong></div>
            <div class="progress-track"><span data-progress="<?= $occupancy ?>"></span></div>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($canSeeOperations): ?>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Recent bookings</h2><p>Latest guest reservations</p></div><a class="text-link" href="bookings.php">View all →</a></div>
    <?php if (!$recentBookings): ?>
        <div class="empty-state compact"><strong>No bookings yet</strong><p>Create a booking to get started.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>GUEST</th><th>ROOM</th><th>STAY</th><th>AMOUNT</th><th>STATUS</th></tr></thead><tbody>
        <?php foreach ($recentBookings as $booking): ?>
            <tr>
                <td><strong><?= e($booking['full_name']) ?></strong><small class="cell-sub">Booking #<?= (int) $booking['id'] ?></small></td>
                <td>Room <?= e($booking['room_number']) ?></td>
                <td><time datetime="<?= e($booking['checkin_date']) ?>" data-localized-date data-date-format="short"><?= e(date('d M', strtotime($booking['checkin_date']))) ?></time> – <time datetime="<?= e($booking['checkout_date']) ?>" data-localized-date data-date-format="long"><?= e(date('d M Y', strtotime($booking['checkout_date']))) ?></time></td>
                <td><?= e(money($booking['total_amount'])) ?></td>
                <td><span class="badge badge-<?= e($booking['status']) ?>"><?= e(status_label($booking['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php if (!$canSeeFinancials && !$canSeeRoomMap && !$canSeeOperations): ?>
<section class="panel empty-state">
    <strong><?= e(status_label($role)) ?> workspace is ready</strong>
    <p>The dedicated <?= e(strtolower(status_label($role))) ?> module has not been added yet. Your account is active and only shows the information currently permitted for this role.</p>
</section>
<?php elseif ($role === 'accountant'): ?>
<section class="panel empty-state">
    <strong>Financial reporting workspace</strong>
    <p>Today's recorded revenue is shown above. Detailed revenue, expense, and salary reports will be added in a later module.</p>
    <a class="button button-secondary" href="bookings.php">View booking totals</a>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
