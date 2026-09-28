<?php
declare(strict_types=1);
require __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$appId = 1;
$checks = [
    'availability' => 'application_id',
    'duty_schedules' => 'application_id',
    'attendance_logs' => 'application_id',
    'audit_logs' => 'application_id',
    'student_hours_summary' => 'application_id',
    'evaluations' => 'application_id',
];
$result = [];
foreach ($checks as $table => $column) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :id");
        $stmt->execute(['id' => $appId]);
        $result[$table] = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $result[$table] = 'ERR';
    }
}
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
