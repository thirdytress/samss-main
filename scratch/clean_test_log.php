<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$pdo->exec('DELETE FROM attendance_logs WHERE application_id = 32 AND DATE(created_at) = CURDATE()');
echo "Cleaned today test logs for application 32\n";
