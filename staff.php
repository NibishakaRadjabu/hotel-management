<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['admin', 'manager']);

$roles = [
    'receptionist' => 'Receptionist',
    'housekeeper' => 'Housekeeper',
    'chef' => 'Chef',
    'restaurant_cashier' => 'Restaurant Cashier',
    'concierge' => 'Concierge',
    'accountant' => 'Accountant',
    'maintenance_officer' => 'Maintenance Officer',
    'security_guard' => 'Security Guard',
    'manager' => 'Manager',
    'admin' => 'Administrator',
];
$operationalRoles = [
    'receptionist',
    'housekeeper',
    'chef',
    'restaurant_cashier',
    'concierge',
    'maintenance_officer',
    'security_guard',
];
function temporary_password(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = (string) ($_POST['role'] ?? '');
        $roleAllowed = isset($roles[$role])
            && ($user['role'] === 'admin' || in_array($role, $operationalRoles, true));
        if ($fullName === '' || strlen($fullName) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 190 || !$roleAllowed) {
            flash('Enter a valid name, email address, and staff role.', 'error');
        } else {
            $password = temporary_password();
            try {
                $statement = $pdo->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role, must_change_password, created_by)
                     VALUES (?, ?, ?, ?, 1, ?)'
                );
                $statement->execute([
                    $fullName,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                    $user['id'],
                ]);
                audit_event('staff.created', (int) $pdo->lastInsertId());
                flash('Staff account created. Share this temporary password once: ' . $password);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    flash('An account already uses that email address.', 'error');
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'create_invitation') {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Only administrators can create staff registration links.');
        }
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = (string) ($_POST['role'] ?? '');
        $roleAllowed = isset($roles[$role]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !$roleAllowed) {
            flash('Enter a valid email address and staff role.', 'error');
        } else {
            $existingAccount = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
            $existingAccount->execute([$email]);
            if ($existingAccount->fetchColumn()) {
                flash('An account already uses that email address.', 'error');
            } else {
                $token = bin2hex(random_bytes(32));
                try {
                    $pdo->beginTransaction();
                    $revokePrevious = $pdo->prepare(
                        'UPDATE staff_invitations
                         SET consumed_at = NOW()
                         WHERE email = ? AND consumed_at IS NULL AND expires_at > NOW()'
                    );
                    $revokePrevious->execute([$email]);
                    $invitation = $pdo->prepare(
                        'INSERT INTO staff_invitations (token_hash, email, role, created_by, expires_at)
                         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR))'
                    );
                    $invitation->execute([hash('sha256', $token), $email, $role, $user['id']]);
                    audit_event('staff.invitation_created');
                    $pdo->commit();

                    $_SESSION['new_invitation'] = [
                        'url' => $config['app_url'] . '/register.php?token=' . rawurlencode($token),
                        'email' => $email,
                        'role' => $role,
                    ];
                    flash('Registration link created. Send it only to the intended staff member; it works once and expires in 48 hours.');
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'revoke_invitation') {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Only administrators can revoke staff registration links.');
        }
        $invitationId = filter_var($_POST['invitation_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$invitationId) {
            flash('Choose a valid registration link.', 'error');
        } else {
            $revoke = $pdo->prepare(
                'UPDATE staff_invitations SET consumed_at = NOW()
                 WHERE id = ? AND consumed_at IS NULL AND expires_at > NOW()'
            );
            $revoke->execute([$invitationId]);
            if ($revoke->rowCount()) {
                audit_event('staff.invitation_revoked');
                flash('Registration link revoked.');
            } else {
                flash('That link is already expired, used, or revoked.', 'error');
            }
        }
    } elseif ($action === 'reset_password') {
        $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$staffId || $staffId === (int) $user['id']) {
            flash('Choose another staff account to reset.', 'error');
        } else {
            $target = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $target->execute([$staffId]);
            $targetRole = $target->fetchColumn();
            if ($targetRole === false) {
                flash('Staff account was not found.', 'error');
            } elseif ($user['role'] !== 'admin' && !in_array($targetRole, $operationalRoles, true)) {
                flash('Managers can only reset operational staff passwords.', 'error');
            } else {
                $password = temporary_password();
                $statement = $pdo->prepare(
                    'UPDATE users
                     SET password_hash = ?, must_change_password = 1, session_version = session_version + 1
                     WHERE id = ?'
                );
                $statement->execute([password_hash($password, PASSWORD_DEFAULT), $staffId]);
                audit_event('staff.password_reset', $staffId);
                flash('Password reset. All prior sessions were revoked. Share this temporary password once: ' . $password);
            }
        }
    } elseif ($action === 'assign_room') {
        $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
        $roomId = filter_var($_POST['room_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$staffId || !$roomId) {
            flash('Choose a housekeeper and a room.', 'error');
        } else {
            $assignment = $pdo->prepare(
                "INSERT INTO room_assignments (user_id, room_id, assigned_by)
                 SELECT u.id, r.id, ?
                 FROM users u CROSS JOIN rooms r
                 WHERE u.id = ? AND u.role = 'housekeeper' AND u.is_active = 1 AND r.id = ?
                 ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), assigned_at = CURRENT_TIMESTAMP"
            );
            $assignment->execute([$user['id'], $staffId, $roomId]);
            flash($assignment->rowCount() ? 'Room assigned to the housekeeper.' : 'Choose an active housekeeper and a valid room.', $assignment->rowCount() ? 'success' : 'error');
        }
    } elseif ($action === 'set_active') {
        $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
        $active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
        if (!$staffId || !in_array($active, [0, 1], true) || $staffId === (int) $user['id']) {
            flash('Choose a valid staff account.', 'error');
        } else {
            try {
                $pdo->beginTransaction();
                if ($active === 0) {
                    $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE")->fetchAll();
                }
                $target = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ? FOR UPDATE');
                $target->execute([$staffId]);
                $targetUser = $target->fetch();
                if (!$targetUser) {
                    $pdo->rollBack();
                    flash('Staff account was not found.', 'error');
                } elseif ($user['role'] !== 'admin' && !in_array($targetUser['role'], $operationalRoles, true)) {
                    $pdo->rollBack();
                    flash('Managers can only manage operational staff accounts.', 'error');
                } elseif ($active === 0 && $targetUser['role'] === 'admin' && (bool) $targetUser['is_active']
                    && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn() <= 1) {
                    $pdo->rollBack();
                    flash('The last active administrator cannot be disabled.', 'error');
                } else {
                    $change = $pdo->prepare(
                        'UPDATE users SET is_active = ?, session_version = session_version + 1 WHERE id = ?'
                    );
                    $change->execute([$active, $staffId]);
                    $pdo->commit();
                    if ($change->rowCount()) {
                        audit_event($active ? 'staff.enabled' : 'staff.disabled', $staffId);
                        flash($active ? 'Staff account reactivated. Ask them to sign in again.' : 'Staff account disabled; existing sessions were revoked.');
                    } else {
                        flash('Staff account status was unchanged.');
                    }
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    } else {
        flash('Unknown staff action.', 'error');
    }
    redirect('staff.php');
}

$staff = $pdo->query(
    'SELECT u.id, u.full_name, u.email, u.role, u.is_active, u.created_at,
            GROUP_CONCAT(r.room_number ORDER BY r.room_number SEPARATOR ", ") AS assigned_rooms
     FROM users u
     LEFT JOIN room_assignments ra ON ra.user_id = u.id
     LEFT JOIN rooms r ON r.id = ra.room_id
     GROUP BY u.id, u.full_name, u.email, u.role, u.is_active, u.created_at
     ORDER BY u.full_name'
)->fetchAll();
$housekeepers = $pdo->query(
    "SELECT id, full_name FROM users WHERE role = 'housekeeper' AND is_active = 1 ORDER BY full_name"
)->fetchAll();
$rooms = $pdo->query('SELECT id, room_number FROM rooms ORDER BY room_number')->fetchAll();
$activeInvitations = [];
if ($user['role'] === 'admin') {
    $activeInvitations = $pdo->query(
        'SELECT id, email, role, expires_at
         FROM staff_invitations
         WHERE consumed_at IS NULL AND expires_at > NOW()
         ORDER BY created_at DESC'
    )->fetchAll();
}
$newInvitation = $_SESSION['new_invitation'] ?? null;
unset($_SESSION['new_invitation']);
$pageTitle = 'Staff';
require __DIR__ . '/includes/layout.php';
?>
<?php if ($newInvitation): ?>
<section class="panel invitation-panel" aria-live="polite">
    <div class="panel-heading"><div><h2>Share this registration link</h2><p><?= e($newInvitation['email']) ?> · <?= e($roles[$newInvitation['role']] ?? status_label($newInvitation['role'])) ?></p></div></div>
    <p class="password-hint">This secret link can be used once and expires in 48 hours. Send it only to the intended staff member using WhatsApp or another trusted channel.</p>
    <div class="copy-link-row"><input id="new-invitation-link" type="text" readonly value="<?= e($newInvitation['url']) ?>"><button class="button button-secondary" type="button" data-copy-link="#new-invitation-link">Copy link</button></div>
    <span class="password-hint" data-copy-status role="status"></span>
</section>
<?php endif; ?>
<div class="welcome-row">
    <div><h2>Staff accounts</h2><p>Give each team member their own account and least-privilege role.</p></div>
    <a class="button button-primary" href="<?= $user['role'] === 'admin' ? '#invite-staff' : '#add-staff' ?>"><?= $user['role'] === 'admin' ? '＋ Invite staff' : '＋ Add staff' ?></a>
</div>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Hotel team <span class="count-pill"><?= count($staff) ?></span></h2><p>Disabled accounts lose access immediately. Password resets revoke existing sessions.</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>STAFF MEMBER</th><th>ROLE</th><th>ROOMS</th><th>STATUS</th><th>ADDED</th><th>SECURITY</th></tr></thead><tbody>
    <?php foreach ($staff as $member): ?>
        <tr>
            <td><strong><?= e($member['full_name']) ?></strong><small class="cell-sub"><?= e($member['email']) ?></small></td>
            <td><?= e($roles[$member['role']] ?? status_label($member['role'])) ?></td>
            <td><?= e($member['assigned_rooms'] ?: '—') ?></td>
            <td><span class="badge <?= $member['is_active'] ? 'badge-checked_out' : 'badge-cancelled' ?>"><?= $member['is_active'] ? 'Active' : 'Disabled' ?></span></td>
            <td><?= e(date('d M Y', strtotime($member['created_at']))) ?></td>
            <td>
                <?php if ((int) $member['id'] !== (int) $user['id']): ?>
                    <div class="action-form">
                        <?php if ($user['role'] === 'admin' || in_array($member['role'], $operationalRoles, true)): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="staff_id" value="<?= (int) $member['id'] ?>">
                            <button class="button button-small button-secondary" name="action" value="reset_password" data-confirm="Reset this staff member's password and revoke their sessions?">Reset password</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="staff_id" value="<?= (int) $member['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= $member['is_active'] ? 0 : 1 ?>">
                            <button class="button button-small <?= $member['is_active'] ? 'button-quiet' : 'button-secondary' ?>" name="action" value="set_active" data-confirm="<?= $member['is_active'] ? 'Disable this account and revoke its sessions?' : 'Reactivate this account?' ?>"><?= $member['is_active'] ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <?php else: ?><span class="muted">Admin only</span><?php endif; ?>
                    </div>
                <?php else: ?><span class="muted">Current account</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php if ($user['role'] === 'admin'): ?>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Pending registration links <span class="count-pill"><?= count($activeInvitations) ?></span></h2><p>Unused links expire automatically after 48 hours.</p></div></div>
    <?php if (!$activeInvitations): ?>
        <div class="empty-state compact"><strong>No pending links</strong><p>Create a link when a staff member needs to register.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>EMAIL</th><th>ROLE</th><th>EXPIRES</th><th>ACTION</th></tr></thead><tbody>
        <?php foreach ($activeInvitations as $invitation): ?>
            <tr>
                <td><?= e($invitation['email']) ?></td>
                <td><?= e($roles[$invitation['role']] ?? status_label($invitation['role'])) ?></td>
                <td><?= e(date('d M Y H:i', strtotime($invitation['expires_at']))) ?></td>
                <td><form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="revoke_invitation">
                    <input type="hidden" name="invitation_id" value="<?= (int) $invitation['id'] ?>">
                    <button class="button button-small button-quiet" type="submit" data-confirm="Revoke this registration link?">Revoke link</button>
                </form></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($housekeepers && $rooms): ?>
<section class="panel form-panel">
    <div class="panel-heading"><div><h2>Assign rooms to housekeeping</h2><p>Housekeepers will only see rooms assigned to them.</p></div></div>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="assign_room">
        <label>Housekeeper<select name="staff_id" required><?php foreach ($housekeepers as $housekeeper): ?><option value="<?= (int) $housekeeper['id'] ?>"><?= e($housekeeper['full_name']) ?></option><?php endforeach; ?></select></label>
        <label>Room<select name="room_id" required><?php foreach ($rooms as $room): ?><option value="<?= (int) $room['id'] ?>">Room <?= e($room['room_number']) ?></option><?php endforeach; ?></select></label>
        <div class="form-actions"><button class="button button-primary" type="submit">Assign room</button></div>
    </form>
</section>
<?php endif; ?>
<?php if ($user['role'] === 'admin'): ?>
<section class="panel form-panel" id="invite-staff">
    <div class="panel-heading"><div><h2>Invite staff to register</h2><p>Create a role-bound, single-use link to send directly to a staff member.</p></div></div>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create_invitation">
        <label>Staff email address<input name="email" type="email" maxlength="190" autocomplete="email" required></label>
        <label>Role<select name="role" required><?php foreach ($roles as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
        <div class="form-actions"><button class="button button-primary" type="submit">Create registration link</button></div>
    </form>
</section>
<?php endif; ?>
<section class="panel form-panel" id="add-staff">
    <div class="panel-heading"><div><h2>Create a staff account</h2><p>A strong temporary password is generated; the staff member must change it at first sign-in.</p></div></div>
    <?php if ($user['role'] === 'manager'): ?><p class="password-hint">Managers can create and manage operational staff, but cannot create or manage administrators, managers, or accountant accounts.</p><?php endif; ?>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <label>Full name<input name="full_name" maxlength="160" autocomplete="name" required></label>
        <label>Email address<input name="email" type="email" maxlength="190" autocomplete="email" required></label>
        <label>Role<select name="role" required><?php foreach ($roles as $value => $label): ?><?php if ($user['role'] === 'admin' || in_array($value, $operationalRoles, true)): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endif; ?><?php endforeach; ?></select></label>
        <div class="form-actions"><button class="button button-primary" type="submit">Create account</button></div>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
