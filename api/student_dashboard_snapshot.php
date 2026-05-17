<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function sams_snapshot_day_rank(string $day): int
{
  return match ($day) {
    'Monday' => 1,
    'Tuesday' => 2,
    'Wednesday' => 3,
    'Thursday' => 4,
    'Friday' => 5,
    'Saturday' => 6,
    default => 7,
  };
}

function sams_snapshot_time_label(string $time): string
{
  $timestamp = strtotime($time);
  return $timestamp ? date('g:i A', $timestamp) : $time;
}

function sams_snapshot_status_label(string $status): string
{
  return match ($status) {
    'accepted' => 'Accepted',
    'declined' => 'Declined',
    default => 'Pending',
  };
}

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'student')) {
  http_response_code(403);
  echo json_encode(['success' => false, 'message' => 'Forbidden']);
  exit;
}

$pdo = sams_pdo();

$studentInfoStmt = $pdo->prepare(
  'SELECT s.student_id AS student_db_id, u.first_name, u.last_name
   FROM students s
   INNER JOIN users u ON u.user_id = s.user_id
   WHERE s.user_id = :user_id
   LIMIT 1'
);
$studentInfoStmt->execute(['user_id' => (int) ($user['user_id'] ?? $user['id'] ?? 0)]);
$studentInfo = $studentInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$studentDbId = (int) ($studentInfo['student_db_id'] ?? 0);
$currentAssignment = null;
$acceptedSchedules = 0;
$pendingSchedules = 0;
$declinedSchedules = 0;
$totalSchedules = 0;

if ($studentDbId > 0) {
  $scheduleStmt = $pdo->prepare(
    "SELECT ds.duty_id AS id,
            COALESCE(NULLIF(TRIM(ds.office_name), ''), NULLIF(TRIM(a.preferred_office), ''), 'Unassigned') AS office_name,
            ds.day_of_week, ds.start_time AS time_start, ds.end_time AS time_end, ds.status,
            ds.term_id, t.term_name, t.term_year AS school_year
     FROM duty_schedules ds
     LEFT JOIN applications a ON a.application_id = ds.application_id
     LEFT JOIN terms t ON t.term_id = ds.term_id
     WHERE a.student_id = :student_id
     ORDER BY FIELD(ds.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'), ds.start_time ASC"
  );
  $scheduleStmt->execute(['student_id' => $studentDbId]);
  $studentSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $totalSchedules = count($studentSchedules);
  foreach ($studentSchedules as $schedule) {
    $status = (string) ($schedule['status'] ?? 'pending');
    if ($status === 'accepted') {
      $acceptedSchedules++;
    } elseif ($status === 'declined') {
      $declinedSchedules++;
    } else {
      $pendingSchedules++;
    }
  }

  if (!empty($studentSchedules)) {
    $todayRank = (int) date('N');
    $sortedSchedules = $studentSchedules;
    usort($sortedSchedules, static function (array $left, array $right) use ($todayRank): int {
      $leftOffset = sams_snapshot_day_rank((string) ($left['day_of_week'] ?? '')) - $todayRank;
      if ($leftOffset < 0) {
        $leftOffset += 7;
      }

      $rightOffset = sams_snapshot_day_rank((string) ($right['day_of_week'] ?? '')) - $todayRank;
      if ($rightOffset < 0) {
        $rightOffset += 7;
      }

      $offsetCompare = $leftOffset <=> $rightOffset;
      if ($offsetCompare !== 0) {
        return $offsetCompare;
      }

      return strcmp((string) ($left['time_start'] ?? ''), (string) ($right['time_start'] ?? ''));
    });

    $currentAssignment = null;
    foreach ($sortedSchedules as $schedule) {
      if ((string) ($schedule['status'] ?? '') === 'accepted') {
        $currentAssignment = $schedule;
        break;
      }
    }

    if ($currentAssignment === null) {
      $currentAssignment = $sortedSchedules[0] ?? null;
    }
  }
}

echo json_encode([
  'success' => true,
  'generated_at' => date('c'),
  'current_assignment' => $currentAssignment ? [
    'office_name' => (string) ($currentAssignment['office_name'] ?? ''),
    'day_of_week' => (string) ($currentAssignment['day_of_week'] ?? ''),
    'time_start' => (string) ($currentAssignment['time_start'] ?? ''),
    'time_end' => (string) ($currentAssignment['time_end'] ?? ''),
    'time_start_label' => sams_snapshot_time_label((string) ($currentAssignment['time_start'] ?? '')),
    'time_end_label' => sams_snapshot_time_label((string) ($currentAssignment['time_end'] ?? '')),
    'term_label' => trim((string) ($currentAssignment['term_name'] ?? '') . ' ' . (string) ($currentAssignment['school_year'] ?? '')),
    'status' => (string) ($currentAssignment['status'] ?? 'pending'),
    'status_label' => sams_snapshot_status_label((string) ($currentAssignment['status'] ?? 'pending')),
  ] : null,
  'summary' => [
    'total' => $totalSchedules,
    'accepted' => $acceptedSchedules,
    'pending' => $pendingSchedules,
    'declined' => $declinedSchedules,
  ],
]);