<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$passwordColumn = sams_first_existing_column($pdo,'users',['password','password_hash']);
$mustChangePasswordColumn = sams_first_existing_column($pdo,'users',['must_change_password']);
$userIdColumn = sams_first_existing_column($pdo,'users',['id','user_id']);
$mustChangePasswordSelect = $mustChangePasswordColumn !== null ? 'u.' . $mustChangePasswordColumn . ' AS must_change_password' : '0 AS must_change_password';
$sql = 'SELECT u.' . $userIdColumn . ' AS id, u.email, u.role, u.first_name, u.last_name, u.is_active, u.' . $passwordColumn . ' AS password_stored, ' . $mustChangePasswordSelect . ', s.student_id, s.student_id_number FROM users u LEFT JOIN students s ON u.' . $userIdColumn . ' = s.user_id WHERE u.email = :identifier OR s.student_id_number = :identifier LIMIT 1';
echo $sql . PHP_EOL;
$stmt = $pdo->prepare($sql);
$stmt->debugDumpParams();
