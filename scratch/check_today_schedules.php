<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

$day = date('l');
echo "=== DEPLOYED DUTY SCHEDULES FOR $day ===\n";
$stmt = $pdo->prepare('
    SELECT ds.duty_id, ds.application_id, ds.office_name, ds.day_of_week, ds.start_time, ds.end_time, ds.status,
           u.first_name, u.last_name, s.student_id_number
    FROM duty_schedules ds
    INNER JOIN applications a ON a.application_id = ds.application_id
    INNER JOIN students s ON s.student_id = a.student_id
    INNER JOIN users u ON u.user_id = s.user_id
    WHERE ds.day_of_week = :day AND ds.status = "deployed"
    ORDER BY u.last_name, u.first_name, ds.start_time
');
$stmt->execute(['day' => $day]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

echo "Total deployed duty schedules for $day: " . count($rows) . "\n";
