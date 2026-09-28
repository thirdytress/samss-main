<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "Altering attendance_logs table...\n";
$pdo->exec('
    ALTER TABLE attendance_logs 
    MODIFY COLUMN clock_in_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY COLUMN clock_out_time DATETIME NULL DEFAULT NULL
');

echo "Table altered successfully.\n";

// Restore any existing logs where clock_in_time equals clock_out_time and created_at < clock_in_time
$fixStmt = $pdo->exec('
    UPDATE attendance_logs 
    SET clock_in_time = created_at 
    WHERE clock_out_time IS NOT NULL 
      AND clock_in_time = clock_out_time 
      AND created_at < clock_out_time
');
echo "Fixed $fixStmt corrupted attendance record(s).\n";

// Check the schema again
$stmt = $pdo->query('
    SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, EXTRA
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "attendance_logs"
      AND COLUMN_NAME IN ("clock_in_time", "clock_out_time")
');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// Check Martyn Joseph Seloterio log
$logStmt = $pdo->query('
    SELECT log_id, clock_in_time, clock_out_time, TIMEDIFF(clock_out_time, clock_in_time) AS duration_diff,
           TIMESTAMPDIFF(SECOND, clock_in_time, clock_out_time) / 3600 AS rendered_hours
    FROM attendance_logs
    ORDER BY log_id DESC
    LIMIT 5
');
print_r($logStmt->fetchAll(PDO::FETCH_ASSOC));
