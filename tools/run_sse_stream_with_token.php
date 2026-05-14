<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();

// pick first supervisor office and user_id
$row = $pdo->query('SELECT office_name FROM supervisors LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
$office = (string) ($row['office_name'] ?? '');
$uid = 0;

if ($office === '') {
    fwrite(STDERR, "No supervisor office found for testing\n");
    exit(1);
}

// ensure tokens table exists
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
$ins->execute(['token' => $token, 'uid' => $uid, 'office' => $office, 'exp' => $expires]);

fwrite(STDOUT, "Created token for office '{$office}': {$token}\n\nConnecting to attendance_stream (CLI wrapper) — output below:\n\n");

// set GET param and include the stream script
$_GET['token'] = $token;

// include the stream; it will run until killed
require __DIR__ . '/../supervisor/attendance_stream.php';

exit(0);
