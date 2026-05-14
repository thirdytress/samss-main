<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();

echo "Finding a test student/application/duty...\n";
$stmt = $pdo->query(
    "SELECT s.student_id AS student_db_id, u.email, a.application_id, ds.duty_id, ds.start_time
     FROM students s
     JOIN users u ON u.user_id = s.user_id
     JOIN applications a ON a.student_id = s.student_id
     JOIN duty_schedules ds ON ds.application_id = a.application_id
     WHERE ds.status = 'accepted'
     LIMIT 1"
);
$one = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$one) {
    echo "No accepted schedule found. Aborting.\n";
    exit(1);
}

$applicationId = (int) $one['application_id'];
$dutyId = (int) $one['duty_id'];
$startTime = $one['start_time'] ?? null;

// get term_id for application to satisfy FK on attendance_logs
$termStmt = $pdo->prepare('SELECT term_id FROM applications WHERE application_id = :app LIMIT 1');
$termStmt->execute(['app' => $applicationId]);
$termRow = $termStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$termId = (int) ($termRow['term_id'] ?? 0);
echo "Using term_id={$termId}\n";

echo "Found application_id={$applicationId}, duty_id={$dutyId}, start_time={$startTime}\n";

function insertLog(PDO $pdo, int $appId, int $dutyId, int $termId, string $clockIn, string $clockOut, string $note): int {
    $stmt = $pdo->prepare('INSERT INTO attendance_logs (application_id, duty_id, term_id, clock_in_time, clock_out_time, status, late_minutes, notes, created_at) VALUES (:app, :duty, :term, :in, :out, :status, :late, :notes, NOW())');
    // status will be normalized by logic; set to 'present' as default
    // compute late_minutes trivially from timestamps
    $late = 0;
    $startTs = strtotime($clockIn);
    $inTs = strtotime($clockIn);
    if ($inTs && $startTs) $late = max(0, (int) floor(($inTs - $startTs) / 60));
    $stmt->execute([
        'app' => $appId,
        'duty' => $dutyId,
        'term' => $termId,
        'in' => $clockIn,
        'out' => $clockOut,
        'status' => 'present',
        'late' => $late,
        'notes' => $note,
    ]);
    return (int) $pdo->lastInsertId();
}

$baseDate = date('Y-m-d');
// ensure start time available
if (!$startTime) {
    // fallback to 09:00
    $startTime = '09:00:00';
}
$startDateTime = $baseDate . ' ' . $startTime;

// Insert present (within 5 minutes)
$presentIn = date('Y-m-d H:i:s', strtotime($startDateTime) + 5 * 60);
$presentOut = date('Y-m-d H:i:s', strtotime($startDateTime) + 3 * 3600);
$presentId = insertLog($pdo, $applicationId, $dutyId, $termId, $presentIn, $presentOut, 'TEST: present');
echo "Inserted present log id={$presentId} clock_in={$presentIn}\n";

$logs = sams_attendance_normalize_student_logs($pdo, $applicationId, null);
echo "Snapshot after present (first 5):\n";
print_r(array_slice($logs,0,5));

// Insert late (15 minutes)
$lateIn = date('Y-m-d H:i:s', strtotime($startDateTime) + 15 * 60);
$lateOut = date('Y-m-d H:i:s', strtotime($startDateTime) + 3 * 3600);
$lateId = insertLog($pdo, $applicationId, $dutyId, $termId, $lateIn, $lateOut, 'TEST: late');
echo "Inserted late log id={$lateId} clock_in={$lateIn}\n";

$logs2 = sams_attendance_normalize_student_logs($pdo, $applicationId, null);
echo "Snapshot after late (first 5):\n";
print_r(array_slice($logs2,0,5));

// Cleanup test rows
$deleteStmt = $pdo->prepare('DELETE FROM attendance_logs WHERE notes IN (:n1,:n2) AND application_id = :app');
$deleteStmt->execute(['n1' => 'TEST: present', 'n2' => 'TEST: late', 'app' => $applicationId]);
echo "Cleaned up test rows.\n";

echo "Done.\n";
