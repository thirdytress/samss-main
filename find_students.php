<?php
require_once __DIR__ . '/config/bootstrap.php';
$pdo = sams_pdo();

$stmt = $pdo->query("SELECT u.email, u.first_name, u.last_name FROM users u JOIN students s ON u.user_id = s.user_id LIMIT 3");
$students = $stmt->fetchAll();

echo "Available student accounts:\n";
foreach ($students as $s) {
    echo "Email: " . $s['email'] . " (Name: " . $s['first_name'] . " " . $s['last_name'] . ")\n";
}
echo "\nNote: Password is typically 'password123' or check seed.sql\n";
?>
