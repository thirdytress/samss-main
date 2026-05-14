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

$termStatement = $pdo->query(
    'SELECT term_id
     FROM terms
     WHERE start_date <= CURDATE() AND end_date >= CURDATE()
     ORDER BY term_id DESC
     LIMIT 1'
);
$activeTermId = (int) ($termStatement->fetchColumn() ?: 0);

$accessStmt = $pdo->prepare(
    'SELECT a.application_id
     FROM applications a
     WHERE a.application_id = :application_id
       AND EXISTS (
            SELECT 1
            FROM duty_schedules ds
            WHERE ds.application_id = a.application_id
              AND ds.status = "accepted"
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

$normalizedLogs = sams_attendance_normalize_student_logs($pdo, $applicationId, $activeTermId > 0 ? $activeTermId : null);
$signature = 0;
foreach ($normalizedLogs as $log) {
    $signature = max($signature, (int) ($log['log_id'] ?? 0));
}

$summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0, 'hours' => 0.0];
$rows = [];
foreach (array_slice($normalizedLogs, 0, 50) as $log) {
    $status = sams_attendance_display_status((string) ($log['status'] ?? 'absent'));
    if ($status === 'present' || $status === 'completed') {
        $summary['present']++;
    } elseif ($status === 'late') {
        $summary['late']++;
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
        'status_label' => $status === 'late' ? 'Late' : (($status === 'present' || $status === 'completed') ? 'Present' : 'Absent'),
        'badge_class' => $status === 'late' ? 'badge--late' : (($status === 'present' || $status === 'completed') ? 'badge--present' : 'badge--absent'),
    ];
}

$summary['hours'] = (float) $summary['hours'];

// Find accepted schedules for this application that have no matching attendance log (derived absences)
$absentSchedulesStmt = $pdo->prepare(
    'SELECT ds.duty_id, ds.day_of_week, ds.start_time, ds.end_time, COALESCE(NULLIF(TRIM(ds.office_name), ""), a.preferred_office, "Unassigned") AS office_name
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
    LEFT JOIN (
        SELECT application_id AS app_sub, duty_id, MAX(log_id) AS max_log_id
        FROM attendance_logs
        WHERE application_id = :application_id_sub
        GROUP BY application_id, duty_id
    ) lm ON lm.duty_id = ds.duty_id AND lm.app_sub = ds.application_id
    WHERE ds.application_id = :application_id_outer
       AND ds.status = "accepted"
       AND lm.max_log_id IS NULL'
);
$absentSchedulesStmt->execute(['application_id_sub' => $applicationId, 'application_id_outer' => $applicationId]);
$absentSchedules = $absentSchedulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
    'success' => true,
    'signature' => $signature,
    'summary' => $summary,
    'attendance_rows' => $rows,
    'absent_schedules' => $absentSchedules,
]);
