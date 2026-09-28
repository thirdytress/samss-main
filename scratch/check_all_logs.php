<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "=== ALL ATTENDANCE LOGS (all time) ===\n";
$stmt = $pdo->query('SELECT log_id, application_id, duty_id, clock_in_time, clock_out_time, status, created_at FROM attendance_logs ORDER BY log_id ASC');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) {
    echo "log={$r['log_id']} app={$r['application_id']} duty={$r['duty_id']} in={$r['clock_in_time']} out={$r['clock_out_time']} status={$r['status']} created={$r['created_at']}\n";
}

echo "\n=== CHECK DUPLICATES (same app+duty+date) ===\n";
$stmt2 = $pdo->query("
    SELECT application_id, duty_id, DATE(created_at) AS log_date, COUNT(*) AS cnt
    FROM attendance_logs
    GROUP BY application_id, duty_id, DATE(created_at)
    HAVING cnt > 1
");
$dups = $stmt2->fetchAll(PDO::FETCH_ASSOC);
if (empty($dups)) {
    echo "No duplicates found.\n";
} else {
    foreach ($dups as $d) {
        echo "DUPLICATE: app={$d['application_id']} duty={$d['duty_id']} date={$d['log_date']} count={$d['cnt']}\n";
    }
}
