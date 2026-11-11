<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

if (current_user() !== null) {
    redirect('index.php');
}

$token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['token'] ?? '')
    : (string) ($_GET['token'] ?? '');
$tokenIsValid = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
$invitation = null;
if ($tokenIsValid) {
    $lookup = $pdo->prepare(
        'SELECT id, email, role FROM staff_invitations
         WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > NOW()'
    );
    $lookup->execute([hash('sha256', $token)]);
    $invitation = $lookup->fetch() ?: null;
}

$error = '';
$fullName = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!$invitation) {
        $error = 'This registration link is invalid, expired, or has already been used. Ask an administrator for a new link.';
    } elseif ($fullName === '' || strlen($fullName) > 160) {
        $error = 'Enter your name using no more than 160 characters.';
    } elseif (strlen($password) < 12 || strlen($password) > 200) {
        $error = 'Choose a password between 12 and 200 characters.';
    } elseif ($password !== $confirmation) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                'SELECT id, email, role, created_by FROM staff_invitations
                 WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > NOW()
                 FOR UPDATE'
            );
            $lock->execute([hash('sha256', $token)]);
            $lockedInvitation = $lock->fetch();
            if (!$lockedInvitation) {
                $pdo->rollBack();
                $error = 'This registration link is invalid, expired, or has already been used. Ask an administrator for a new link.';
            } else {
                $create = $pdo->prepare(
                    'INSERT INTO users (full_name, email, password_hash, role, must_change_password, created_by)
                     VALUES (?, ?, ?, ?, 0, ?)'
                );
                $create->execute([
                    $fullName,
                    $lockedInvitation['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $lockedInvitation['role'],
                    $lockedInvitation['created_by'],
                ]);
                $userId = (int) $pdo->lastInsertId();
                $consume = $pdo->prepare('UPDATE staff_invitations SET consumed_at = NOW() WHERE id = ?');
                $consume->execute([$lockedInvitation['id']]);
                audit_event('staff.invitation_accepted', $userId);
                $pdo->commit();
                flash('Your staff account is ready. Sign in with your invited email and new password.');
                redirect('login.php');
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $error = 'An account already uses this email. Contact an administrator for help.';
            } else {
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff registration | SmartHotel</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand auth-brand" href="login.php"><span class="brand-mark">S</span><span>SmartHotel<small>MANAGEMENT</small></span></a>
        <p class="eyebrow">INVITED STAFF REGISTRATION</p>
        <?php if (!$invitation): ?>
            <h1>Link unavailable</h1>
            <p class="auth-intro">This registration link is invalid, expired, or already used. Ask your hotel administrator to send you a new one.</p>
            <a class="button button-primary" href="login.php">Return to sign in</a>
        <?php else: ?>
            <h1>Complete your registration</h1>
            <p class="auth-intro">Your account is pre-approved for <?= e(status_label($invitation['role'])) ?>. Use this link only if it was sent to you by your hotel administrator.</p>
            <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>Invited email address<input type="email" value="<?= e($invitation['email']) ?>" readonly></label>
                <label>Full name<input name="full_name" maxlength="160" autocomplete="name" required value="<?= e($fullName) ?>"></label>
                <label>Create password<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required></label>
                <label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="200" required></label>
                <p class="password-hint">Use at least 12 characters. This link can be used once and expires 48 hours after it was created.</p>
                <button type="submit" class="button button-primary">Create staff account</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
