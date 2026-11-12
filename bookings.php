<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/paypal.php';
$user = require_roles(['admin', 'manager', 'receptionist', 'accountant']);
expire_online_booking_payments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'create') {
        if (!in_array($user['role'], ['admin', 'manager', 'receptionist'], true)) {
            http_response_code(403);
            exit('You do not have permission to create bookings.');
        }
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $identity = trim((string) ($_POST['id_passport'] ?? ''));
        $roomId = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
        $checkin = (string) ($_POST['checkin_date'] ?? '');
        $checkout = (string) ($_POST['checkout_date'] ?? '');
        $bookingStatus = (string) ($_POST['booking_status'] ?? 'confirmed');
        $validDates = valid_date($checkin)
            && valid_date($checkout)
            && $checkin >= date('Y-m-d')
            && $checkin < $checkout
            && (int) (new DateTime($checkin))->diff(new DateTime($checkout))->days <= 365;

        if ($fullName === '' || $phone === '' || !$roomId || !$validDates
            || !in_array($bookingStatus, ['pending', 'confirmed'], true)) {
            flash('Complete the guest, room, and valid stay details.', 'error');
        } else {
            try {
                $pdo->beginTransaction();
                $roomStatement = $pdo->prepare('SELECT id, price, status FROM rooms WHERE id = ? FOR UPDATE');
                $roomStatement->execute([$roomId]);
                $room = $roomStatement->fetch();
                $conflictStatement = $pdo->prepare(
                    "SELECT COUNT(*) FROM bookings
                     WHERE room_id = ? AND status IN ('pending', 'confirmed', 'checked_in')
                     AND checkin_date < ? AND checkout_date > ?
                     AND (booking_source <> 'online' OR payment_deadline_at IS NULL OR payment_deadline_at > NOW())"
                );
                $conflictStatement->execute([$roomId, $checkout, $checkin]);
                if (!$room || !in_array($room['status'], ['available', 'cleaned'], true) || (int) $conflictStatement->fetchColumn() > 0) {
                    $pdo->rollBack();
                    flash('That room is not available for the selected dates.', 'error');
                } else {
                    $guestStatement = $pdo->prepare(
                        'INSERT INTO guests (full_name, phone, id_passport) VALUES (?, ?, ?)'
                    );
                    $guestStatement->execute([$fullName, $phone, $identity !== '' ? $identity : null]);
                    $guestId = (int) $pdo->lastInsertId();
                    $nights = (int) (new DateTime($checkin))->diff(new DateTime($checkout))->days;
                    $total = $nights * (float) $room['price'];
                    $bookingStatement = $pdo->prepare(
                        "INSERT INTO bookings (guest_id, room_id, checkin_date, checkout_date, nightly_rate, total_amount, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $bookingStatement->execute([$guestId, $roomId, $checkin, $checkout, $room['price'], $total, $bookingStatus]);
                    create_notification((int) $user['id'], 'booking_created', 'Booking #' . $pdo->lastInsertId());
                    $pdo->commit();
                    flash(status_label($bookingStatus) . ' booking saved. Total: ' . money($total) . ' for ' . $nights . ' night(s).');
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
        redirect('bookings.php');
    }

    if (!$bookingId) {
        flash('Invalid booking.', 'error');
        redirect('bookings.php');
    }
    if (in_array($action, ['verify_mobile_payment', 'reject_mobile_payment'], true)) {
        if (!in_array($user['role'], ['admin', 'manager', 'receptionist'], true)) {
            http_response_code(403);
            exit('You do not have permission to review Mobile Money payments.');
        }
        try {
            $pdo->beginTransaction();
            $review = $pdo->prepare(
                'SELECT m.amount, m.transaction_reference, m.status AS payment_status,
                        b.status AS booking_status, b.booking_source
                 FROM manual_payment_submissions m
                 JOIN bookings b ON b.id = m.booking_id
                 WHERE m.booking_id = ? FOR UPDATE'
            );
            $review->execute([$bookingId]);
            $submission = $review->fetch();
            if (!$submission || $submission['payment_status'] !== 'pending'
                || $submission['booking_status'] !== 'pending'
                || $submission['booking_source'] !== 'online') {
                $pdo->rollBack();
                flash('This Mobile Money submission is no longer waiting for review.', 'error');
            } elseif ($action === 'verify_mobile_payment') {
                $payment = $pdo->prepare(
                    "INSERT INTO payments (booking_id, amount, payment_method, gateway_reference)
                     VALUES (?, ?, 'mobile_money', ?)"
                );
                $payment->execute([
                    $bookingId,
                    $submission['amount'],
                    'AIRTEL:' . $submission['transaction_reference'],
                ]);
                $markVerified = $pdo->prepare(
                    "UPDATE manual_payment_submissions
                     SET status = 'verified', reviewed_by = ?, reviewed_at = NOW()
                     WHERE booking_id = ? AND status = 'pending'"
                );
                $markVerified->execute([$user['id'], $bookingId]);
                $confirmBooking = $pdo->prepare(
                    "UPDATE bookings SET status = 'confirmed', payment_deadline_at = NULL
                     WHERE id = ? AND status = 'pending' AND booking_source = 'online'"
                );
                $confirmBooking->execute([$bookingId]);
                if ($markVerified->rowCount() !== 1 || $confirmBooking->rowCount() !== 1) {
                    throw new RuntimeException('The Mobile Money payment review could not be completed.');
                }
                create_notification((int) $user['id'], 'manual_payment_verified', 'Booking #' . $bookingId);
                $pdo->commit();
                flash('Mobile Money deposit verified and booking confirmed.');
            } else {
                $markRejected = $pdo->prepare(
                    "UPDATE manual_payment_submissions
                     SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW()
                     WHERE booking_id = ? AND status = 'pending'"
                );
                $markRejected->execute([$user['id'], $bookingId]);
                $cancelBooking = $pdo->prepare(
                    "UPDATE bookings SET status = 'cancelled'
                     WHERE id = ? AND status = 'pending' AND booking_source = 'online'"
                );
                $cancelBooking->execute([$bookingId]);
                if ($markRejected->rowCount() !== 1 || $cancelBooking->rowCount() !== 1) {
                    throw new RuntimeException('The Mobile Money payment review could not be completed.');
                }
                create_notification((int) $user['id'], 'manual_payment_rejected', 'Booking #' . $bookingId);
                $pdo->commit();
                flash('Mobile Money submission rejected and booking cancelled.');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    } elseif ($action === 'check_in') {
        if (!in_array($user['role'], ['admin', 'manager', 'receptionist'], true)) {
            http_response_code(403);
            exit('You do not have permission to check guests in.');
        }
        $statement = $pdo->prepare(
            "UPDATE bookings SET status = 'checked_in' WHERE id = ? AND status = 'confirmed'
             AND checkin_date <= CURDATE() AND checkout_date > CURDATE()
             AND EXISTS (SELECT 1 FROM rooms WHERE rooms.id = bookings.room_id AND rooms.status IN ('available', 'cleaned'))"
        );
        $statement->execute([$bookingId]);
        if ($statement->rowCount()) {
            create_notification((int) $user['id'], 'guest_checked_in', 'Booking #' . $bookingId);
        }
        flash($statement->rowCount() ? 'Guest checked in. Room status is now occupied.' : 'Check-in is not available for this booking today.', $statement->rowCount() ? 'success' : 'error');
    } elseif ($action === 'confirm') {
        if (!in_array($user['role'], ['admin', 'manager', 'receptionist'], true)) {
            http_response_code(403);
            exit('You do not have permission to confirm bookings.');
        }
        $statement = $pdo->prepare(
            "UPDATE bookings SET status = 'confirmed'
             WHERE id = ? AND status = 'pending' AND booking_source = 'staff'"
        );
        $statement->execute([$bookingId]);
        if ($statement->rowCount()) {
            create_notification((int) $user['id'], 'booking_confirmed', 'Booking #' . $bookingId);
        }
        flash($statement->rowCount() ? 'Booking confirmed.' : 'This booking cannot be confirmed.', $statement->rowCount() ? 'success' : 'error');
    } elseif ($action === 'check_out') {
        if (!in_array($user['role'], ['admin', 'manager', 'receptionist'], true)) {
            http_response_code(403);
            exit('You do not have permission to check guests out.');
        }
        $method = (string) ($_POST['payment_method'] ?? '');
        $methods = ['cash', 'mobile_money', 'card', 'bank'];
        if (!in_array($method, $methods, true)) {
            flash('Select a valid payment method.', 'error');
        } else {
            try {
                $pdo->beginTransaction();
                $statement = $pdo->prepare(
                    "SELECT b.total_amount - COALESCE((
                         SELECT SUM(p.amount) FROM payments p WHERE p.booking_id = b.id
                     ), 0) AS balance_due
                     FROM bookings b WHERE b.id = ? AND b.status = 'checked_in' FOR UPDATE"
                );
                $statement->execute([$bookingId]);
                $booking = $statement->fetch();
                if (!$booking) {
                    $pdo->rollBack();
                    flash('Only checked-in guests can check out.', 'error');
                } else {
                    $paymentStatement = $pdo->prepare(
                        'INSERT INTO payments (booking_id, amount, payment_method) VALUES (?, ?, ?)'
                    );
                    $balanceDue = max(0, (float) $booking['balance_due']);
                    if ($balanceDue > 0) {
                        $paymentStatement->execute([$bookingId, $balanceDue, $method]);
                    }
                    $updateStatement = $pdo->prepare("UPDATE bookings SET status = 'checked_out' WHERE id = ?");
                    $updateStatement->execute([$bookingId]);
                    $roomStatement = $pdo->prepare(
                        "UPDATE rooms SET status = 'cleaning'
                         WHERE id = (SELECT room_id FROM bookings WHERE id = ?)"
                    );
                    $roomStatement->execute([$bookingId]);
                    create_notification((int) $user['id'], 'checkout_payment', 'Booking #' . $bookingId);
                    $pdo->commit();
                    flash($balanceDue > 0
                        ? 'Guest checked out. Remaining payment of ' . money($balanceDue) . ' recorded.'
                        : 'Guest checked out. The booking was fully paid in advance.');
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } elseif ($action === 'cancel') {
        if (!in_array($user['role'], ['admin', 'manager', 'receptionist'], true)) {
            http_response_code(403);
            exit('You do not have permission to cancel bookings.');
        }
        $statement = $pdo->prepare(
            "UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status IN ('pending', 'confirmed')"
        );
        $statement->execute([$bookingId]);
        if ($statement->rowCount()) {
            create_notification((int) $user['id'], 'booking_cancelled', 'Booking #' . $bookingId);
        }
        flash($statement->rowCount() ? 'Booking cancelled.' : 'This booking cannot be cancelled.', $statement->rowCount() ? 'success' : 'error');
    }
    redirect('bookings.php');
}

$rooms = $user['role'] === 'accountant'
    ? []
    : $pdo->query("SELECT id, room_number, room_type, price FROM rooms WHERE status IN ('available', 'cleaned') ORDER BY room_number")->fetchAll();
$bookings = $user['role'] === 'accountant'
    ? $pdo->query(
        "SELECT b.*, r.room_number,
                COALESCE(paid.total_paid, 0) AS paid_amount,
                COALESCE(paid.test_paid, 0) AS test_paid_amount,
                manual.transaction_reference AS manual_payment_reference,
                manual.status AS manual_payment_status
         FROM bookings b
         JOIN rooms r ON r.id = b.room_id
         LEFT JOIN (
             SELECT booking_id, SUM(amount) AS total_paid,
                    SUM(CASE WHEN payment_method = 'test' THEN amount ELSE 0 END) AS test_paid
             FROM payments GROUP BY booking_id
         ) paid
           ON paid.booking_id = b.id
         LEFT JOIN manual_payment_submissions manual ON manual.booking_id = b.id
         ORDER BY b.created_at DESC"
    )->fetchAll()
    : $pdo->query(
        "SELECT b.*, g.full_name, g.phone, r.room_number,
                COALESCE(paid.total_paid, 0) AS paid_amount,
                COALESCE(paid.test_paid, 0) AS test_paid_amount,
                manual.transaction_reference AS manual_payment_reference,
                manual.status AS manual_payment_status
         FROM bookings b
         JOIN guests g ON g.id = b.guest_id
         JOIN rooms r ON r.id = b.room_id
         LEFT JOIN (
             SELECT booking_id, SUM(amount) AS total_paid,
                    SUM(CASE WHEN payment_method = 'test' THEN amount ELSE 0 END) AS test_paid
             FROM payments GROUP BY booking_id
         ) paid
           ON paid.booking_id = b.id
         LEFT JOIN manual_payment_submissions manual ON manual.booking_id = b.id
         ORDER BY b.created_at DESC"
    )->fetchAll();
$pageTitle = 'Bookings';
require __DIR__ . '/includes/layout.php';
?>
<div class="welcome-row">
    <div><h2>Bookings & stays</h2><p>Reserve rooms, check guests in, and record checkout payments.</p></div>
    <?php if ($user['role'] !== 'accountant'): ?><a class="button button-primary" href="#new-booking">＋ New booking</a><?php endif; ?>
</div>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>All bookings <span class="count-pill"><?= count($bookings) ?></span></h2><p>Room rates are saved with each booking.</p></div></div>
    <?php if (!$bookings): ?>
        <div class="empty-state"><strong>No bookings yet</strong><p>Create your first guest reservation below.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>GUEST</th><th>ROOM</th><th>STAY</th><th>TOTAL</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
        <?php foreach ($bookings as $booking): ?>
            <tr>
                <td>
                    <strong><?= $user['role'] === 'accountant' ? 'Booking #' . (int) $booking['id'] : e($booking['full_name']) ?></strong>
                    <?php if ($user['role'] !== 'accountant'): ?><small class="cell-sub"><?= e($booking['phone']) ?></small><?php endif; ?>
                </td>
                <td>Room <?= e($booking['room_number']) ?></td>
                <td><time datetime="<?= e($booking['checkin_date']) ?>" data-localized-date data-date-format="long"><?= e(date('d M Y', strtotime($booking['checkin_date']))) ?></time><small class="cell-sub">to <time datetime="<?= e($booking['checkout_date']) ?>" data-localized-date data-date-format="long"><?= e(date('d M Y', strtotime($booking['checkout_date']))) ?></time></small></td>
                <td><?= e(money($booking['total_amount'])) ?><small class="cell-sub">Paid: <?= e(money($booking['paid_amount'])) ?> · Due: <?= e(money(max(0, (float) $booking['total_amount'] - (float) $booking['paid_amount']))) ?></small><?php if ((float) $booking['test_paid_amount'] > 0): ?><small class="cell-sub">Includes simulated TEST deposit: <?= e(money($booking['test_paid_amount'])) ?></small><?php endif; ?></td>
                <td><span class="badge badge-<?= e($booking['status']) ?>"><?= e(status_label($booking['status'])) ?></span></td>
                <td>
                    <?php if ($booking['booking_source'] === 'online' && $booking['status'] === 'pending'
                        && $booking['manual_payment_status'] === 'pending'): ?>
                        <small class="cell-sub">Airtel Money reference: <?= e($booking['manual_payment_reference']) ?></small>
                        <?php if (in_array($user['role'], ['admin', 'manager', 'receptionist'], true)): ?>
                        <form class="action-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <button class="button button-small button-primary" name="action" value="verify_mobile_payment" data-confirm="Verify this transaction in the Airtel merchant account before marking it received. Continue?">Verify received</button>
                            <button class="button button-small button-quiet" name="action" value="reject_mobile_payment" data-confirm="Reject this payment reference and cancel the booking?">Reject</button>
                        </form>
                        <?php else: ?><span class="muted">Awaiting staff verification</span><?php endif; ?>
                    <?php elseif ($booking['booking_source'] === 'online' && $booking['status'] === 'pending'): ?>
                        <span class="muted">Awaiting advance payment</span>
                    <?php elseif (in_array($booking['status'], ['pending', 'confirmed'], true)): ?>
                        <?php if ($user['role'] === 'accountant'): ?><span class="muted">—</span><?php else: ?>
                        <form class="action-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <?php if ($booking['status'] === 'pending'): ?>
                                <button class="button button-small button-secondary" name="action" value="confirm">Confirm</button>
                            <?php else: ?>
                                <button class="button button-small button-secondary" name="action" value="check_in">Check in</button>
                            <?php endif; ?>
                            <button class="button button-small button-quiet" name="action" value="cancel" data-confirm="Cancel this booking?">Cancel</button>
                        </form>
                        <?php endif; ?>
                    <?php elseif ($booking['status'] === 'checked_in'): ?>
                        <?php if ($user['role'] === 'accountant'): ?><span class="muted">Read only</span><?php else: ?>
                        <form class="action-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                            <select name="payment_method" aria-label="Payment method">
                                <option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="card">Card</option><option value="bank">Bank</option>
                            </select>
                            <button class="button button-small button-primary" name="action" value="check_out">Check out & pay</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php if ($user['role'] !== 'accountant'): ?>
<section class="panel form-panel" id="new-booking">
    <div class="panel-heading"><div><h2>Create a booking</h2><p>Enter the guest and stay details. Price is calculated automatically.</p></div></div>
    <?php if (!$rooms): ?><div class="alert alert-error">No available rooms. Add a room or mark one as available before booking.</div><?php endif; ?>
    <form method="post" class="form-grid" id="booking-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
        <label>Guest full name<input name="full_name" maxlength="160" placeholder="Guest name" required></label>
        <label>Telephone<input name="phone" type="tel" maxlength="40" placeholder="+250 7xx xxx xxx" required></label>
        <label>ID or passport <span class="optional">(optional)</span><input name="id_passport" maxlength="80" placeholder="Document number"></label>
        <label>Room<select name="room_id" id="room-id" required <?= !$rooms ? 'disabled' : '' ?>><option value="">Choose room</option><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>" data-price="<?= e($room['price']) ?>">Room <?= e($room['room_number']) ?> · <?= e($room['room_type']) ?> (<?= e(money($room['price'])) ?>/night)</option><?php endforeach; ?></select></label>
        <label>Check-in date<input type="date" name="checkin_date" id="checkin-date" min="<?= e(date('Y-m-d')) ?>" required></label>
        <label>Check-out date<input type="date" name="checkout_date" id="checkout-date" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required></label>
        <label>Booking status<select name="booking_status"><option value="confirmed">Confirm now</option><option value="pending">Leave pending</option></select></label>
        <div class="price-preview"><span>Estimated total <small id="stay-nights">Select dates and room</small></span><strong id="booking-total">— RWF</strong></div>
        <div class="form-actions"><button class="button button-primary" type="submit" <?= !$rooms ? 'disabled' : '' ?>>Confirm booking</button></div>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
