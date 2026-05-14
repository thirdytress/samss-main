<?php
require_once 'config/database.php';
$pdo = sams_pdo();

echo "=== USERS ===\n";
foreach ($pdo->query('SELECT user_id, email, role, first_name, last_name, is_active, must_change_password FROM users ORDER BY user_id') as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
}

echo "\n=== STUDENTS ===\n";
foreach ($pdo->query('SELECT * FROM students ORDER BY student_id LIMIT 20') as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
}
