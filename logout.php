<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the sign-out form.');
}

verify_csrf();
if (isset($_SESSION['user_id'])) {
    audit_event('auth.logout', (int) $_SESSION['user_id']);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => 'Strict',
    ]);
}
session_destroy();
redirect('login.php');
