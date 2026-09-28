<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "=== ATTENDANCE LOGS FOR MARTYN JOSEPH SELOTERIO ===\n";
$stmt = $pdo->query('
    SELECT al.*, a.student_id, ds.start_time as sched_start, ds.end_time as sched_end
    FROM attendance_logs al
    JOIN applications a ON a.application_id = al.application_id
    JOIN students s ON s.student_id = a.student_id
    JOIN users u ON u.user_id = s.user_id
    LEFT JOIN duty_schedules ds ON ds.duty_id = al.duty_id
    WHERE u.email = "martynjosephseloterio@gmail.com" OR s.student_id_number = "2021-1"
    ORDER BY al.log_id DESC
');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "=== STUDENT HOURS SUMMARY ===\n";
$stmt2 = $pdo->query('
    SELECT shs.*
    FROM student_hours_summary shs
    JOIN applications a ON a.application_id = shs.application_id
    JOIN students s ON s.student_id = a.student_id
    JOIN users u ON u.user_id = s.user_id
    WHERE u.email = "martynjosephseloterio@gmail.com" OR s.student_id_number = "2021-1"
');
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
