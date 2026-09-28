<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

$stmt = $pdo->query('SHOW CREATE TABLE attendance_logs');
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row['Create Table'] . "\n";
