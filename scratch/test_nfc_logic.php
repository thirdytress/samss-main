<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

$nfcUid = '1144250055'; // Martyn Joseph Seloterio NFC UID
$dayOfWeek = date('l');

echo "Testing NFC scan logic for NFC UID: $nfcUid on $dayOfWeek at " . date('Y-m-d H:i:s') . "\n";

// 1. Find student matching NFC UID
$studentStmt = $pdo->prepare('
    SELECT s.student_id, s.student_id_number, u.first_name, u.last_name, u.is_active
    FROM students s
    INNER JOIN users u ON u.user_id = s.user_id
    WHERE s.nfc_uid = :nfc_uid
    LIMIT 1
');
$studentStmt->execute(['nfc_uid' => $nfcUid]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student not found\n");
}

// 2. Term
$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);

// 3. Application
$appStmt = $pdo->prepare('
    SELECT application_id, preferred_office, status
    FROM applications
    WHERE student_id = :student_id AND term_id = :term_id AND status = "approved"
    LIMIT 1
');
$appStmt->execute([
    'student_id' => (int) $student['student_id'],
    'term_id' => $activeTermId
]);
$app = $appStmt->fetch(PDO::FETCH_ASSOC);

// 4. Fetch all deployed schedules for today
$schedStmt = $pdo->prepare('
    SELECT duty_id, start_time, end_time, status, COALESCE(NULLIF(TRIM(office_name), ""), :pref_office) AS office_name
    FROM duty_schedules
    WHERE application_id = :app_id AND day_of_week = :day AND status = "deployed"
    ORDER BY start_time ASC
');
$schedStmt->execute([
    'app_id' => (int) $app['application_id'],
    'day' => $dayOfWeek,
    'pref_office' => $app['preferred_office'] ?: 'Unassigned'
]);
$schedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($schedules) . " deployed schedule(s) for today:\n";
print_r($schedules);

$now = new DateTimeImmutable('now');
$targetSchedule = null;
$targetAction = null; // 'in' or 'out'
$targetLog = null;
$allCompleted = true;
$latestEndTime = null;

// Check each schedule's attendance logs for today
foreach ($schedules as $sched) {
    $endTime = new DateTimeImmutable(date('Y-m-d') . ' ' . $sched['end_time']);
    if ($latestEndTime === null || $endTime > $latestEndTime) {
        $latestEndTime = $endTime;
    }

    $logStmt = $pdo->prepare('
        SELECT log_id, duty_id, clock_in_time, clock_out_time, created_at
        FROM attendance_logs
        WHERE application_id = :app_id AND duty_id = :duty_id AND DATE(created_at) = CURDATE()
        ORDER BY log_id DESC
        LIMIT 1
    ');
    $logStmt->execute([
        'app_id' => (int) $app['application_id'],
        'duty_id' => (int) $sched['duty_id']
    ]);
    $log = $logStmt->fetch(PDO::FETCH_ASSOC);

    // Priority 1: Shift currently clocked IN (needs Clock Out)
    if ($log && !empty($log['clock_in_time']) && empty($log['clock_out_time'])) {
        $targetSchedule = $sched;
        $targetAction = 'out';
        $targetLog = $log;
        break; // Active shift takes top priority
    }

    // If there's an uncompleted shift that has not ended yet, and we haven't picked a clock-in target yet
    if (!$log && $now <= $endTime && $targetSchedule === null) {
        $targetSchedule = $sched;
        $targetAction = 'in';
        $targetLog = null;
    }

    if (!$log || empty($log['clock_out_time'])) {
        $allCompleted = false;
    }
}

echo "\n--- RESOLUTION ---\n";
if ($targetSchedule !== null && $targetAction === 'in') {
    echo "Action: CLOCK IN to duty_id {$targetSchedule['duty_id']} ({$targetSchedule['start_time']} - {$targetSchedule['end_time']})\n";
} elseif ($targetSchedule !== null && $targetAction === 'out') {
    echo "Action: CLOCK OUT of duty_id {$targetSchedule['duty_id']} ({$targetSchedule['start_time']} - {$targetSchedule['end_time']})\n";
} elseif ($allCompleted && !empty($schedules)) {
    echo "All shifts completed today.\n";
} else {
    echo "Unable to Clock In. Shift ended at " . ($latestEndTime ? $latestEndTime->format('g:i A') : 'N/A') . "\n";
}
