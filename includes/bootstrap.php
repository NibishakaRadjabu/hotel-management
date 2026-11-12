<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_httponly', '1');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'");
header('Cache-Control: no-store, private');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000');
}

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_port'],
            $config['db_name']
        ),
        $config['db_user'],
        $config['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('Hotel Management database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Database setup required</title>';
    echo '<body>';
    echo '<h1>Database connection failed</h1><p>Create the database and import <code>database/schema.sql</code>, ';
    echo 'then verify the settings in <code>config.php</code>.</p></body></html>';
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Invalid form token. Reload the page and try again.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function money(int|float|string $amount): string
{
    return number_format((float) $amount, 0, '.', ',') . ' RWF';
}

function valid_date(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function status_label(string $status): string
{
    $labels = [
        'admin' => 'Administrator · Owner',
        'manager' => 'Manager',
        'receptionist' => 'Receptionist',
        'housekeeper' => 'Housekeeper',
        'chef' => 'Chef',
        'restaurant_cashier' => 'Restaurant Cashier',
        'concierge' => 'Concierge',
        'accountant' => 'Accountant',
        'maintenance_officer' => 'Maintenance Officer',
        'security_guard' => 'Security Guard',
        'cleaned' => 'Cleaned',
    ];

    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function current_user(): ?array
{
    global $pdo;
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        return $user = null;
    }

    $statement = $pdo->prepare(
        'SELECT id, full_name, email, role, profile_image, is_active, must_change_password, session_version
         FROM users WHERE id = ?'
    );
    $statement->execute([$userId]);
    $account = $statement->fetch();
    if (!$account || !(bool) $account['is_active']
        || (int) $account['session_version'] !== (int) ($_SESSION['session_version'] ?? 0)) {
        return $user = null;
    }

    return $user = $account;
}

function require_login(): array
{
    $idleLimit = 1200;
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && time() - $lastActivity > $idleLimit) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Your session expired. Please sign in again.', 'error');
        redirect('login.php');
    }

    $user = current_user();
    if ($user === null) {
        unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['last_activity']);
        redirect('login.php');
    }

    $_SESSION['last_activity'] = time();
    if ((bool) $user['must_change_password'] && basename($_SERVER['PHP_SELF']) !== 'password_change.php') {
        redirect('password_change.php');
    }

    return $user;
}

function require_roles(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }

    return $user;
}

function audit_event(string $eventType, ?int $targetUserId = null): void
{
    global $pdo;
    $actorId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, target_user_id, event_type, ip_address)
         VALUES (?, ?, ?, ?)'
    );
    $statement->execute([
        $actorId ?: null,
        $targetUserId,
        $eventType,
        is_string($ipAddress) ? substr($ipAddress, 0, 45) : null,
    ]);
}

function create_notification(int $userId, string $eventType, ?string $reference = null): void
{
    global $pdo;
    $allowedEvents = [
        'booking_created',
        'booking_confirmed',
        'guest_checked_in',
        'checkout_payment',
        'booking_cancelled',
        'room_added',
        'room_updated',
        'staff_account_created',
        'staff_invitation_created',
        'staff_invitation_revoked',
        'staff_password_reset',
        'room_assigned',
        'staff_account_enabled',
        'staff_account_disabled',
        'profile_updated',
        'password_changed',
        'public_booking_paid',
        'public_payment_review',
        'manual_payment_verified',
        'manual_payment_rejected',
    ];
    if (!in_array($eventType, $allowedEvents, true)) {
        throw new InvalidArgumentException('Unsupported notification event.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO notifications (user_id, event_type, reference_text) VALUES (?, ?, ?)'
    );
    $statement->execute([$userId, $eventType, $reference !== null ? substr($reference, 0, 120) : null]);
}

function notification_label(string $eventType): string
{
    $labels = [
        'booking_created' => 'New booking created',
        'booking_confirmed' => 'Booking confirmed',
        'guest_checked_in' => 'Guest checked in',
        'checkout_payment' => 'Checkout completed and payment recorded',
        'booking_cancelled' => 'Booking cancelled',
        'room_added' => 'Room added',
        'room_updated' => 'Room details updated',
        'staff_account_created' => 'Staff account created',
        'staff_invitation_created' => 'Staff invitation created',
        'staff_invitation_revoked' => 'Staff invitation revoked',
        'staff_password_reset' => 'Staff password reset',
        'room_assigned' => 'Room assigned',
        'staff_account_enabled' => 'Staff account enabled',
        'staff_account_disabled' => 'Staff account disabled',
        'profile_updated' => 'Profile updated',
        'password_changed' => 'Password changed',
        'public_booking_paid' => 'Guest completed advance payment for a booking',
        'public_payment_review' => 'Paid booking needs staff review',
        'manual_payment_verified' => 'Mobile Money deposit verified',
        'manual_payment_rejected' => 'Mobile Money deposit rejected',
    ];

    return $labels[$eventType] ?? 'Hotel activity';
}
