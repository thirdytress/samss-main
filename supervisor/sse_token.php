<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$pdo = sams_pdo();
$office = (string) ($user['office_name'] ?? '');
$userId = (int) ($user['user_id'] ?? 0);

// create tokens table if missing
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS sse_tokens (
        token VARCHAR(64) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        office_name VARCHAR(128) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$token = bin2hex(random_bytes(16));
$expires = (new DateTime('+120 seconds'))->format('Y-m-d H:i:s');
$ins = $pdo->prepare('INSERT INTO sse_tokens (token, user_id, office_name, expires_at) VALUES (:token, :uid, :office, :exp)');
$ins->execute(['token' => $token, 'uid' => $userId, 'office' => $office, 'exp' => $expires]);

echo json_encode(['success' => true, 'token' => $token, 'expires_at' => $expires]);
