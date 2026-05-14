<?php
declare(strict_types=1);
require __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$email = 'martynjosephseloterio@gmail.com';
 $stmt = $pdo->prepare('SELECT user_id, email, first_name, last_name, role FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "NOT_FOUND\n";
    exit(0);
}
echo json_encode($row, JSON_PRETTY_PRINT) . PHP_EOL;
