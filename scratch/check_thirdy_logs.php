<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "=== ATTENDANCE LOGS FOR THIRDY GARCIA ===\n";
$stmt = $pdo->query('
    SELECT al.*, a.student_id, u.first_name, u.last_name
    FROM attendance_logs al
    JOIN applications a ON a.application_id = al.application_id
    JOIN students s ON s.student_id = a.student_id
    JOIN users u ON u.user_id = s.user_id
    WHERE u.first_name LIKE "%thirdy%" OR u.last_name LIKE "%garcia%"
    ORDER BY al.log_id DESC
');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
