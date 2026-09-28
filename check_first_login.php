<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$identifier = trim($_GET['identifier'] ?? $_POST['identifier'] ?? '');

if ($identifier === '') {
    echo json_encode(['is_first_login' => false]);
    exit;
}

try {
    $pdo = sams_pdo();
    
    // Find column names
    $mustChangePasswordColumn = sams_first_existing_column($pdo, 'users', ['must_change_password']);
    
    if ($mustChangePasswordColumn === null) {
        echo json_encode(['is_first_login' => false]);
        exit;
    }
    
    // Query users table by email
    $stmt = $pdo->prepare("
        SELECT u.role, u.{$mustChangePasswordColumn} AS must_change_password
        FROM users u
        WHERE u.email = :email
        LIMIT 1
    ");
    $stmt->execute(['email' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['role'] === 'student' && (int)$user['must_change_password'] === 1) {
        echo json_encode(['is_first_login' => true]);
        exit;
    }
} catch (Throwable $e) {
    // Fail silently on DB errors
}

echo json_encode(['is_first_login' => false]);
