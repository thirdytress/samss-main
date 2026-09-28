<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

$tables = ['users', 'students', 'applications', 'duty_schedules', 'student_reports'];
foreach ($tables as $table) {
    echo "== {$table} columns ==\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            echo $col['Field'] . ' | ' . $col['Type'] . ' | ' . $col['Null'] . ' | ' . $col['Key'] . ' | ' . $col['Default'] . "\n";
        }
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . "\n";
    }
    echo "\n";
}

echo "== live office-linked applications ==\n";
try {
    $stmt = $pdo->query(
        'SELECT a.application_id, a.student_id, a.preferred_office, s.student_id_number, s.is_enrolled
         FROM applications a
         LEFT JOIN students s ON s.student_id = a.student_id
         WHERE a.preferred_office IS NOT NULL
         ORDER BY a.application_id DESC
         LIMIT 20'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo json_encode($row) . "\n";
    }
} catch (Throwable $e) {
    echo 'ERR: ' . $e->getMessage() . "\n";
}

echo "\n== live duty-schedule links ==\n";
try {
    $stmt = $pdo->query(
        'SELECT ds.duty_id, ds.application_id, ds.term_id, a.preferred_office, a.student_id
         FROM duty_schedules ds
         LEFT JOIN applications a ON a.application_id = ds.application_id
         ORDER BY ds.duty_id DESC
         LIMIT 20'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo json_encode($row) . "\n";
    }
} catch (Throwable $e) {
    echo 'ERR: ' . $e->getMessage() . "\n";
}
