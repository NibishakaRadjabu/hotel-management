<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $statement->execute([$user['id']]);
    $passwordHash = $statement->fetchColumn();

    if (strlen($currentPassword) > 200 || !is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 12 || strlen($newPassword) > 200) {
        $error = 'Choose a password between 12 and 200 characters.';
    } elseif ($newPassword !== $confirmation) {
        $error = 'The new passwords do not match.';
    } elseif (password_verify($newPassword, $passwordHash)) {
        $error = 'Choose a new password that is different from your current one.';
    } else {
        $update = $pdo->prepare(
            'UPDATE users
             SET password_hash = ?, must_change_password = 0, session_version = session_version + 1
             WHERE id = ?'
        );
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        $_SESSION['session_version']++;
        $_SESSION['last_activity'] = time();
        audit_event('auth.password_changed', (int) $user['id']);
        flash('Password updated securely.');
        redirect('index.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change password | SmartHotel</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <label class="language-control auth-language-control"><span>Language</span><select id="language-select">
            <option value="en">English</option>
            <option value="fr">Français</option>
            <option value="sw">Kiswahili</option>
        </select></label>
        <a class="brand auth-brand" href="index.php"><span class="brand-mark">S</span><span>SmartHotel<small>MANAGEMENT</small></span></a>
        <p class="eyebrow">ACCOUNT SECURITY</p>
        <h1>Set your password</h1>
        <p class="auth-intro">For your security, choose a new password before continuing.</p>
        <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Temporary or current password<input name="current_password" type="password" autocomplete="current-password" required></label>
            <label>New password<input name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required></label>
            <label>Confirm new password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="200" required></label>
            <p class="password-hint">Use at least 12 characters. Avoid reusing a password from another account.</p>
            <button type="submit" class="button button-primary">Save new password</button>
        </form>
    </main>
<script src="assets/i18n.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
