<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'receptionist']);

$guests = $pdo->query(
    'SELECT g.id, g.full_name, g.phone, g.id_passport, g.created_at,
            COUNT(b.id) AS booking_count,
            MAX(b.checkout_date) AS last_checkout
     FROM guests g
     LEFT JOIN bookings b ON b.guest_id = g.id
     GROUP BY g.id, g.full_name, g.phone, g.id_passport, g.created_at
     ORDER BY g.created_at DESC'
)->fetchAll();
$pageTitle = 'Guests';
require __DIR__ . '/includes/layout.php';
?>
<div class="welcome-row"><div><h2>Guest directory</h2><p>Guest details are saved when a booking is created.</p></div><a class="button button-primary" href="bookings.php#new-booking">＋ New booking</a></div>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Guests <span class="count-pill"><?= count($guests) ?></span></h2><p>Contact details and stay history</p></div></div>
    <?php if (!$guests): ?>
        <div class="empty-state"><strong>No guests yet</strong><p>Guest profiles will appear here after the first booking.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>GUEST</th><th>TELEPHONE</th><th>ID / PASSPORT</th><th>BOOKINGS</th><th>LAST CHECKOUT</th></tr></thead><tbody>
        <?php foreach ($guests as $guest): ?>
            <tr><td><strong><?= e($guest['full_name']) ?></strong></td><td><?= e($guest['phone']) ?></td><td><?= e($guest['id_passport'] ?: '—') ?></td><td><?= (int) $guest['booking_count'] ?></td><td><?= $guest['last_checkout'] ? e(date('d M Y', strtotime($guest['last_checkout']))) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
