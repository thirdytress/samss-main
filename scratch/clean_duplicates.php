<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "=== FINDING ALL DUPLICATE DUTY SCHEDULES ===\n";
$stmt = $pdo->query('
    SELECT application_id, term_id, day_of_week, start_time, end_time, COUNT(*) as cnt, GROUP_CONCAT(duty_id) as duty_ids
    FROM duty_schedules
    GROUP BY application_id, term_id, day_of_week, start_time, end_time
    HAVING COUNT(*) > 1
');
$dups = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($dups);

echo "=== DELETING DUPLICATE DUTY SCHEDULES ===\n";
$deletedCount = 0;
foreach ($dups as $dup) {
    $ids = explode(',', (string) $dup['duty_ids']);
    // Keep the first duty_id, delete the rest
    $keepId = (int) array_shift($ids);
    foreach ($ids as $delId) {
        $delId = (int) $delId;
        // Check if attendance_logs references this duty_id
        $attCheck = $pdo->prepare('SELECT COUNT(*) FROM attendance_logs WHERE duty_id = :id');
        $attCheck->execute(['id' => $delId]);
        if ((int)$attCheck->fetchColumn() > 0) {
            // Re-point attendance logs to the kept duty_id
            $pdo->prepare('UPDATE attendance_logs SET duty_id = :keep WHERE duty_id = :del')->execute([
                'keep' => $keepId,
                'del' => $delId
            ]);
        }
        $pdo->prepare('DELETE FROM duty_schedules WHERE duty_id = :id')->execute(['id' => $delId]);
        $deletedCount++;
        echo "Deleted duplicate duty_id $delId (kept $keepId)\n";
    }
}
echo "Total duplicate duty schedules deleted: $deletedCount\n";
