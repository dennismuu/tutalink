<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
$name = trim((string) readline('Full name: '));
$phone = preg_replace('/[\s()+-]/', '', (string) readline('Phone number: '));
$email = strtolower(trim((string) readline('Email address: ')));
$password = (string) readline('Password (at least 12 characters): ');

if ($name === '' || !preg_match('/^\d{7,15}$/', (string) $phone) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Invalid details. Check the name, phone, email and password length.\n");
    exit(1);
}

try {
    $pdo = db();
    $roleId = $pdo->query("SELECT id FROM roles WHERE slug = 'super_admin'")->fetchColumn();
    if (!$roleId) {
        fwrite(STDERR, "Import database/tutalink.sql before creating an administrator.\n");
        exit(1);
    }
    $existing = $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super_admin'")->fetchColumn();
    if ($existing) {
        fwrite(STDERR, "A Super Admin already exists. Use that account to manage administrators.\n");
        exit(1);
    }
    $insert = $pdo->prepare('INSERT INTO users (role_id, `role`, full_name, phone, email, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $insert->execute([(int) $roleId, 'super_admin', $name, $phone, $email, password_hash($password, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Super Admin created. Sign in at /Admin/login.php.\n");
} catch (PDOException $exception) {
    fwrite(STDERR, "Could not create the Super Admin. Confirm the database connection and that the email is unique.\n");
    exit(1);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    unset($password);
}
