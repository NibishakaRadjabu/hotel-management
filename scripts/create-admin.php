<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require __DIR__ . '/../config.php';
$email = strtolower(trim((string) ($argv[1] ?? '')));
$fullName = trim(implode(' ', array_slice($argv, 2)));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $fullName === '' || strlen($fullName) > 160) {
    fwrite(STDERR, "Usage: php scripts\\create-admin.php admin@example.com Full Name\n");
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']),
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $existingAdmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($existingAdmin > 0) {
        fwrite(STDERR, "An administrator already exists. Create additional accounts from Staff Management.\n");
        exit(1);
    }

    $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $statement = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, role, must_change_password)
         VALUES (?, ?, ?, 'admin', 1)"
    );
    $statement->execute([$fullName, $email, password_hash($temporaryPassword, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Administrator created. Initial temporary password (shown once):\n{$temporaryPassword}\nChange it at first sign-in.\n");
} catch (PDOException $exception) {
    fwrite(STDERR, "Could not create the administrator: {$exception->getMessage()}\n");
    exit(1);
}
