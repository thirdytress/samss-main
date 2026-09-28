<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "=== ALL DUTY SCHEDULES FOR THIRDY GARCIA (app 37) ===\n";
$stmt = $pdo->query('
    SELECT * FROM duty_schedules WHERE application_id = 37 ORDER BY day_of_week, start_time, duty_id
');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
