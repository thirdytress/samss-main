<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$applicationId = (int) ($_GET['application_id'] ?? 0);
if ($applicationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid application']);
    exit;
}

$pdo = sams_pdo();

$supervisorStatement = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorOffice = trim((string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? '')));

$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);

$accessStmt = $pdo->prepare(
    'SELECT a.application_id
     FROM applications a
     WHERE a.application_id = :application_id
       AND EXISTS (
            SELECT 1
            FROM duty_schedules ds
            WHERE ds.application_id = a.application_id
              AND ds.status = "deployed"
              AND (ds.office_name = :office_ds OR a.preferred_office = :office_app)
              AND (:term_id_guard = 0 OR ds.term_id = :term_id_ds)
       )
     LIMIT 1'
);
$accessStmt->execute([
    'application_id' => $applicationId,
    'office_ds' => $supervisorOffice,
    'office_app' => $supervisorOffice,
    'term_id_guard' => $activeTermId,
    'term_id_ds' => $activeTermId,
]);
if (!$accessStmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit;
}

$schedulesStmt = $pdo->prepare(
    'SELECT ds.duty_id
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     WHERE ds.application_id = :application_id
       AND ds.status = "deployed"
       AND (ds.office_name = :office_ds OR a.preferred_office = :office_app)
       AND (:term_id_guard = 0 OR ds.term_id = :term_id_ds)'
);
$schedulesStmt->execute([
    'application_id' => $applicationId,
    'office_ds' => $supervisorOffice,
    'office_app' => $supervisorOffice,
    'term_id_guard' => $activeTermId,
    'term_id_ds' => $activeTermId,
]);
$allowedDutyIds = [];
foreach ($schedulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $schedule) {
    $dutyId = (int) ($schedule['duty_id'] ?? 0);
    if ($dutyId > 0) {
        $allowedDutyIds[$dutyId] = true;
    }
}

$normalizedLogsAll = sams_attendance_normalize_student_logs($pdo, $applicationId, $activeTermId > 0 ? $activeTermId : null);
$normalizedLogs = array_values(array_filter(
    $normalizedLogsAll,
    static function (array $log) use ($allowedDutyIds): bool {
        $dutyId = (int) ($log['duty_id'] ?? 0);
        return $dutyId > 0 && isset($allowedDutyIds[$dutyId]);
    }
));
$signature = 0;
$absentSchedules = [];
foreach ($normalizedLogs as $log) {
    $signature = max($signature, (int) ($log['log_id'] ?? 0));
    if (!empty($log['__derived_absent'])) {
        $absentSchedules[] = [
            'duty_id' => (int) ($log['duty_id'] ?? 0),
            'day_of_week' => (string) ($log['day_of_week'] ?? ''),
            'start_time' => (string) ($log['start_time'] ?? ''),
            'end_time' => (string) ($log['end_time'] ?? ''),
            'office_name' => (string) ($log['office_name'] ?? 'Unassigned'),
        ];
    }
}

$summary = ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0, 'total' => 0, 'hours' => 0.0];
$rows = [];
foreach (array_slice($normalizedLogs, 0, 50) as $log) {
    $status = sams_attendance_display_status((string) ($log['status'] ?? 'absent'));
    if ($status === 'present' || $status === 'completed') {
        $summary['present']++;
    } elseif ($status === 'late') {
        $summary['late']++;
    } elseif ($status === 'excused') {
        $summary['excused']++;
    } else {
        $summary['absent']++;
    }
    $summary['total']++;

    $timeIn = (string) ($log['time_in'] ?? '-');
    $timeOut = (string) ($log['time_out'] ?? '-');
    if (trim($timeIn) === '') {
        $timeIn = '-';
    }
    if (trim($timeOut) === '') {
        $timeOut = '-';
    }

    $inTs = $timeIn !== '-' ? strtotime($timeIn) : false;
    $outTs = $timeOut !== '-' ? strtotime($timeOut) : false;
    if ($inTs && $outTs && $outTs > $inTs) {
        $summary['hours'] += ($outTs - $inTs) / 3600;
    }

    $rows[] = [
        'created_at' => (string) ($log['created_at'] ?? ''),
        'clock_in_time' => $timeIn,
        'clock_out_time' => $timeOut,
        'late_minutes' => (int) ($log['late_minutes'] ?? 0),
        'status_label' => $status === 'late' ? 'Late' : (($status === 'present' || $status === 'completed') ? 'Present' : (($status === 'excused') ? 'Excused' : 'Absent')),
        'badge_class' => $status === 'late' ? 'badge--late' : (($status === 'present' || $status === 'completed') ? 'badge--present' : (($status === 'excused') ? 'badge--excused' : 'badge--absent')),
    ];
}

$summary['absent'] = count($absentSchedules);
$summary['total'] = $summary['present'] + $summary['late'] + $summary['excused'] + $summary['absent'];
$summary['hours'] = (float) $summary['hours'];

echo json_encode([
    'success' => true,
    'signature' => $signature,
    'summary' => $summary,
    'attendance_rows' => $rows,
    'absent_schedules' => $absentSchedules,
]);
