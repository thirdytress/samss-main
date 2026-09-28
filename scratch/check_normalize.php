<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

// Find application IDs for the students
echo "=== APPLICATIONS ===\n";
$stmt = $pdo->query("
    SELECT a.application_id, a.student_id, a.term_id, a.status, u.first_name, u.last_name
    FROM applications a
    INNER JOIN students s ON s.student_id = a.student_id
    INNER JOIN users u ON u.user_id = s.user_id
    WHERE u.first_name LIKE '%martyn%' OR u.last_name LIKE '%seloterio%' OR u.first_name LIKE '%thirdy%'
    ORDER BY a.application_id DESC
    LIMIT 10
");
$apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($apps as $app) {
    echo "app_id={$app['application_id']} student_id={$app['student_id']} term_id={$app['term_id']} status={$app['status']} name={$app['first_name']} {$app['last_name']}\n";
}

echo "\n=== NORMALIZED LOGS (Seloterio, app 32) ===\n";
$logs = sams_attendance_normalize_student_logs($pdo, 32, null);
foreach ($logs as $log) {
    $inTs = !empty($log['time_in']) ? strtotime($log['time_in']) : false;
    $outTs = !empty($log['time_out']) ? strtotime($log['time_out']) : false;
    $dur = 0.0;
    if ($inTs && $outTs && $outTs > $inTs) {
        $dur = ($outTs - $inTs) / 3600;
    }
    echo "log_id={$log['log_id']} status={$log['status']} time_in={$log['time_in']} time_out={$log['time_out']} computed_hrs=" . number_format($dur, 2) . "\n";
}

echo "\n=== NORMALIZED LOGS (Thirdy, app 37) ===\n";
$logs2 = sams_attendance_normalize_student_logs($pdo, 37, null);
foreach ($logs2 as $log) {
    $inTs = !empty($log['time_in']) ? strtotime($log['time_in']) : false;
    $outTs = !empty($log['time_out']) ? strtotime($log['time_out']) : false;
    $dur = 0.0;
    if ($inTs && $outTs && $outTs > $inTs) {
        $dur = ($outTs - $inTs) / 3600;
    }
    echo "log_id={$log['log_id']} status={$log['status']} time_in={$log['time_in']} time_out={$log['time_out']} computed_hrs=" . number_format($dur, 2) . "\n";
}
