<?php
declare(strict_types=1);
// Quick test script: create a temporary duty_schedule assigned now for an application/term
// Usage: php test_schedule_acceptance.php <application_id> <term_id> <day_of_week>

require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

if ($argc < 4) {
    echo "Usage: php test_schedule_acceptance.php <application_id> <term_id> <day_of_week>\n";
    exit(1);
}
$appId = (int) $argv[1];
$termId = (int) $argv[2];
$day = $argv[3];

// Create a schedule for the given day with end_time set to earlier today to simulate past duty
$now = new DateTimeImmutable('now');
$marker = 'test_accept_' . bin2hex(random_bytes(6));
$endTime = $now->modify('-2 hours')->format('H:i:s');
$startTime = $now->modify('-3 hours')->format('H:i:s');

$insert = $pdo->prepare('INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, status, student_response_date, created_at, updated_at) VALUES (:appid, :office, :termid, :dow, :start, :end, :status, NOW(), NOW(), NOW())');
$insert->execute(['appid' => $appId, 'office' => $marker, 'termid' => $termId, 'dow' => $day, 'start' => $startTime, 'end' => $endTime, 'status' => 'accepted']);
$dutyId = (int) $pdo->lastInsertId();

echo "Inserted test duty_id={$dutyId} (marker={$marker})\n";

// Call normalize to see derived absences
$logs = sams_attendance_normalize_student_logs($pdo, $appId, $termId);

$found = false;
foreach ($logs as $r) {
    if (isset($r['duty_id']) && (int)$r['duty_id'] === $dutyId) {
        $found = true;
        echo "Found entry for duty {$dutyId}: status={$r['status']} created_at={$r['created_at']}" . PHP_EOL;
    }
}

if (!$found) {
    echo "No derived entry for duty {$dutyId} (as expected when assigned after scheduled end).\n";
}

// Clean up test row
$pdo->prepare('DELETE FROM duty_schedules WHERE duty_id = :id')->execute(['id' => $dutyId]);

exit(0);
