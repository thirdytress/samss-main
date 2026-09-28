<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

// 1. Insert a test log with a known clock_in_time
$nowStr = date('Y-m-d H:i:s', strtotime('-15 minutes'));
$ins = $pdo->prepare('
    INSERT INTO attendance_logs (application_id, term_id, duty_id, clock_in_time, status, late_minutes, created_at)
    VALUES (32, 1, 225, :in_time, "late", 120, :created_at)
');
$ins->execute(['in_time' => $nowStr, 'created_at' => $nowStr]);
$testLogId = (int) $pdo->lastInsertId();

echo "Created test log ID $testLogId with clock_in_time: $nowStr\n";

// 2. Perform clock out update
sleep(1);
$upd = $pdo->prepare('UPDATE attendance_logs SET clock_out_time = NOW() WHERE log_id = :id');
$upd->execute(['id' => $testLogId]);

// 3. Verify clock_in_time was preserved
$check = $pdo->prepare('SELECT log_id, clock_in_time, clock_out_time, TIMEDIFF(clock_out_time, clock_in_time) as diff FROM attendance_logs WHERE log_id = :id');
$check->execute(['id' => $testLogId]);
$result = $check->fetch(PDO::FETCH_ASSOC);

echo "Verification result:\n";
print_r($result);

// Clean up test log
$pdo->prepare('DELETE FROM attendance_logs WHERE log_id = :id')->execute(['id' => $testLogId]);
echo "Cleaned up test log ID $testLogId\n";
