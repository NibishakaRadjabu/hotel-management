<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['admin', 'manager', 'housekeeper', 'maintenance_officer']);

$canManageRooms = in_array($user['role'], ['admin', 'manager'], true);
$allowedStatuses = match ($user['role']) {
    'housekeeper' => ['cleaning', 'cleaned'],
    'maintenance_officer' => ['maintenance', 'cleaned'],
    default => ['available', 'cleaning', 'cleaned', 'maintenance'],
};
$editableCurrentStatuses = match ($user['role']) {
    'housekeeper' => ['available', 'cleaning', 'cleaned'],
    'maintenance_officer' => ['available', 'cleaned', 'maintenance'],
    default => ['available', 'cleaning', 'cleaned', 'maintenance'],
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $roomNumber = trim((string) ($_POST['room_number'] ?? ''));
    $roomType = trim((string) ($_POST['room_type'] ?? ''));
    $floor = filter_var($_POST['floor'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'create') {
        if (!$canManageRooms) {
            http_response_code(403);
            exit('You do not have permission to add rooms.');
        }
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $floor = filter_var($_POST['floor'] ?? null, FILTER_VALIDATE_INT);
        if ($roomNumber === '' || $roomType === '' || $floor === false || $price === false || $price < 0) {
            flash('Enter a room number, type, floor, and valid nightly price.', 'error');
        } else {
            try {
                $statement = $pdo->prepare('INSERT INTO rooms (room_number, room_type, floor, price) VALUES (?, ?, ?, ?)');
                $statement->execute([$roomNumber, $roomType, $floor, $price]);
                flash('Room added successfully.');
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    flash('That room number already exists.', 'error');
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'update') {
        $roomId = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!$roomId || !in_array($status, $allowedStatuses, true)
            || ($canManageRooms && ($price === false || $price < 0))) {
            flash('Choose a valid room status and nightly rate.', 'error');
        } else {
            $assignmentGuard = $user['role'] === 'housekeeper'
                ? ' AND EXISTS (SELECT 1 FROM room_assignments ra WHERE ra.room_id = rooms.id AND ra.user_id = ?)'
                : '';
            if ($canManageRooms) {
                $statement = $pdo->prepare(
                    "UPDATE rooms SET price = ?, status = ? WHERE id = ?
                     AND NOT EXISTS (SELECT 1 FROM bookings WHERE bookings.room_id = rooms.id AND status = 'checked_in')"
                );
                $statement->execute([$price, $status, $roomId]);
            } else {
                $stateGuard = '';
                if ($user['role'] === 'housekeeper') {
                    $stateGuard = $status === 'cleaned'
                        ? " AND status = 'cleaning'"
                        : " AND status IN ('available', 'cleaning', 'cleaned')";
                } elseif ($user['role'] === 'maintenance_officer') {
                    $stateGuard = $status === 'cleaned'
                        ? " AND status = 'maintenance'"
                        : " AND status IN ('available', 'cleaned', 'maintenance')";
                }
                $statement = $pdo->prepare(
                    "UPDATE rooms SET status = ? WHERE id = ?
                     AND NOT EXISTS (SELECT 1 FROM bookings WHERE bookings.room_id = rooms.id AND status = 'checked_in')"
                     . $stateGuard
                     . $assignmentGuard
                );
                $parameters = [$status, $roomId];
                if ($user['role'] === 'housekeeper') {
                    $parameters[] = $user['id'];
                }
                $statement->execute($parameters);
            }
            flash($statement->rowCount() ? 'Room details updated.' : 'Room is occupied or no changes were made.', $statement->rowCount() ? 'success' : 'error');
        }
    } else {
        http_response_code(400);
        exit('Unknown room action.');
    }
    redirect('rooms.php');
}

$roomQuery = "SELECT r.id, r.room_number, r.room_type, r.floor, r.status"
    . ($canManageRooms ? ', r.price' : '')
    . ",
        CASE WHEN b.id IS NOT NULL THEN 'occupied' ELSE r.status END AS display_status
     FROM rooms r
     LEFT JOIN bookings b ON b.room_id = r.id AND b.status = 'checked_in'
     WHERE 1 = 1";
$roomParameters = [];
if ($user['role'] === 'housekeeper') {
    $roomQuery .= ' AND EXISTS (SELECT 1 FROM room_assignments ra WHERE ra.room_id = r.id AND ra.user_id = ?)';
    $roomParameters[] = $user['id'];
}
$roomQuery .= ' ORDER BY r.floor, r.room_number';
$roomStatement = $pdo->prepare($roomQuery);
$roomStatement->execute($roomParameters);
$rooms = $roomStatement->fetchAll();
$pageTitle = 'Rooms';
require __DIR__ . '/includes/layout.php';
?>
<div class="welcome-row">
    <div><h2><?= $canManageRooms ? 'Room management' : 'Room tasks' ?></h2><p><?= $canManageRooms ? 'Keep room rates and readiness up to date.' : 'Update assigned room readiness without access to rates or financial details.' ?></p></div>
    <?php if ($canManageRooms): ?><a class="button button-primary" href="#add-room">＋ Add a room</a><?php endif; ?>
</div>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2><?= $user['role'] === 'housekeeper' ? 'Assigned rooms' : 'All rooms' ?> <span class="count-pill"><?= count($rooms) ?></span></h2><p>Occupied status is updated automatically at check-in.</p></div></div>
    <?php if (!$rooms): ?>
        <div class="empty-state"><strong><?= $user['role'] === 'housekeeper' ? 'No rooms assigned yet' : 'Your room list is empty' ?></strong><p><?= $user['role'] === 'housekeeper' ? 'Ask a manager to assign rooms to you.' : ($canManageRooms ? 'Add a room using the form below.' : 'No rooms are currently available to view.') ?></p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>ROOM</th><th>TYPE</th><th>FLOOR</th><th>STATUS</th><?php if ($canManageRooms): ?><th>NIGHTLY RATE</th><?php endif; ?><th>UPDATE</th></tr></thead><tbody>
        <?php foreach ($rooms as $room): ?>
            <?php
            $roomStatuses = $allowedStatuses;
            if ($user['role'] === 'housekeeper') {
                $roomStatuses = match ($room['status']) {
                    'available' => ['cleaning'],
                    'cleaning' => ['cleaning', 'cleaned'],
                    default => ['cleaning'],
                };
            } elseif ($user['role'] === 'maintenance_officer') {
                $roomStatuses = match ($room['status']) {
                    'available' => ['maintenance'],
                    'maintenance' => ['maintenance', 'cleaned'],
                    default => ['maintenance'],
                };
            }
            ?>
            <tr>
                <td><strong><?= e($room['room_number']) ?></strong></td>
                <td><?= e($room['room_type']) ?></td>
                <td><?= (int) $room['floor'] ?></td>
                <td><span class="badge badge-room-<?= e($room['display_status']) ?>"><?= e(status_label($room['display_status'])) ?></span></td>
                <?php if ($canManageRooms): ?>
                <td><?= e(money($room['price'])) ?></td>
                <?php endif; ?>
                <td>
                    <?php if ($room['display_status'] !== 'occupied' && ($canManageRooms || in_array($room['status'], $editableCurrentStatuses, true))): ?>
                    <form class="inline-edit" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="room_id" value="<?= (int) $room['id'] ?>">
                        <?php if ($canManageRooms): ?><input aria-label="Nightly rate" type="number" min="0" step="500" name="price" value="<?= e($room['price']) ?>" required><?php endif; ?>
                        <select aria-label="Room status" name="status">
                            <?php foreach ($roomStatuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= $room['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button button-small button-secondary" type="submit">Save</button>
                    </form>
                    <?php else: ?><span class="muted"><?= $room['display_status'] === 'occupied' ? 'In use' : '—' ?></span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php if ($canManageRooms): ?>
<section class="panel form-panel" id="add-room">
    <div class="panel-heading"><div><h2>Add a room</h2><p>New rooms are available for bookings by default.</p></div></div>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <label>Room number<input name="room_number" maxlength="20" placeholder="e.g. 203" required></label>
        <label>Room type<input name="room_type" maxlength="80" placeholder="e.g. Deluxe King" required></label>
        <label>Floor<input type="number" name="floor" min="0" value="1" required></label>
        <label>Nightly rate (RWF)<input type="number" name="price" min="0" step="500" placeholder="45000" required></label>
        <div class="form-actions"><button class="button button-primary" type="submit">Add room</button></div>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
