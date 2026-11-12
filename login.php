<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (current_user() !== null) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = substr(strtolower(trim((string) ($_POST['email'] ?? ''))), 0, 190);
    $password = (string) ($_POST['password'] ?? '');
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $attemptKey = hash('sha256', $email . "\0" . $ipAddress);
    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM login_attempts WHERE window_started_at < NOW() - INTERVAL 1 DAY');
    }
    $recordAttempt = $pdo->prepare(
        'INSERT INTO login_attempts (attempt_key, email, ip_address, window_started_at, attempt_count)
         VALUES (?, ?, ?, NOW(), 1)
         ON DUPLICATE KEY UPDATE
            attempt_count = IF(window_started_at < NOW() - INTERVAL 15 MINUTE, 1, attempt_count + 1),
            window_started_at = IF(window_started_at < NOW() - INTERVAL 15 MINUTE, NOW(), window_started_at)'
    );
    $recordAttempt->execute([$attemptKey, $email, $ipAddress]);
    $attemptLimit = $pdo->prepare(
        'SELECT attempt_count FROM login_attempts WHERE attempt_key = ?'
    );
    $attemptLimit->execute([$attemptKey]);

    if ((int) $attemptLimit->fetchColumn() > 5) {
        $error = 'Too many sign-in attempts. Wait 15 minutes and try again.';
    } else {
        $account = null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $statement = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $statement->execute([$email]);
            $account = $statement->fetch();
        }
        $dummyPasswordHash = '$2y$10$SWmk5Z30hJu2ZleWpBkt8u6J1zv56z5O6fosXj3FNDAqbh5q5HVy.';
        $passwordMatches = password_verify(
            substr($password, 0, 200),
            $account ? $account['password_hash'] : $dummyPasswordHash
        );
        $validLogin = strlen($password) <= 200
            && $account
            && (bool) $account['is_active']
            && $passwordMatches;

        if (!$validLogin) {
            $error = 'Email or password is incorrect.';
        } else {
            $pdo->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')->execute([$attemptKey]);
            if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
                $rehash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
            }
            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['user_id'] = (int) $account['id'];
            $_SESSION['session_version'] = (int) $account['session_version'];
            $_SESSION['last_activity'] = time();
            audit_event('auth.login', (int) $account['id']);
            redirect((bool) $account['must_change_password'] ? 'password_change.php' : 'index.php');
        }
    }
}
$notice = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | SmartHotel</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <label class="language-control auth-language-control"><span>Language</span><select id="language-select">
            <option value="en">English</option>
            <option value="fr">Français</option>
            <option value="sw">Kiswahili</option>
        </select></label>
        <a class="brand auth-brand" href="login.php"><span class="brand-mark">S</span><span>SmartHotel<small>MANAGEMENT</small></span></a>
        <p class="eyebrow">STAFF ACCESS</p>
        <h1>Welcome back</h1>
        <p class="auth-intro">Sign in to manage your hotel's daily operations.</p>
        <?php if ($notice): ?><div class="alert alert-<?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Email address<input name="email" type="email" autocomplete="username" maxlength="190" required autofocus value="<?= e($_POST['email'] ?? '') ?>"></label>
            <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
            <button type="submit" class="button button-primary">Sign in securely</button>
        </form>
        <p class="auth-security">Staff accounts only · Sessions expire after 20 minutes of inactivity</p>
        <p class="public-booking-prompt">Planning a visit? <a href="stay.php">Browse rooms and book online</a>.</p>
    </main>
<script src="assets/i18n.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
