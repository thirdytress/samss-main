<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function sams_student_detail_first_existing_column(PDO $pdo, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        if ((int) $stmt->fetchColumn() > 0) {
            return $column;
        }
    }

    return null;
}

function sams_student_detail_status_label(int $isEnrolled, int $isGoodStanding): string
{
    if ($isEnrolled !== 1) {
        return 'Inactive Student';
    }

    if ($isGoodStanding !== 1) {
        return 'Needs Review';
    }

    return 'Active Student Assistant';
}

function sams_student_detail_status_class(int $isEnrolled, int $isGoodStanding): string
{
    return ($isEnrolled === 1 && $isGoodStanding === 1) ? 'green' : 'red';
}

function sams_student_detail_application_status_label(string $status): string
{
    return match ($status) {
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
        default => 'Pending Review',
    };
}

function sams_student_detail_application_status_class(string $status): string
{
    return $status === 'approved' ? 'green' : 'red';
}

function sams_student_detail_split_tags(?string $value): array
{
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[\r\n,;]+/', $value) ?: [];
    $tags = [];
    foreach ($parts as $part) {
        $tag = trim($part);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return array_values(array_unique($tags));
}

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin' && ($currentUser['role'] ?? null) !== 'supervisor')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$studentId = (int) ($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid student id']);
    exit;
}

try {
    $pdo = sams_pdo();
    $applicationIdColumn = sams_student_detail_first_existing_column($pdo, 'applications', ['id', 'application_id']);
    $attendanceLogIdColumn = sams_student_detail_first_existing_column($pdo, 'attendance_logs', ['id', 'log_id']);

    $latestApplicationSql =
        'SELECT preferred_office, skills, available_hours_per_week, status
         FROM applications
         WHERE student_id = :student_id
         ORDER BY submitted_at DESC';

    if ($applicationIdColumn !== null) {
        $latestApplicationSql .= ', ' . $applicationIdColumn . ' DESC';
    }

    $latestApplicationSql .= ' LIMIT 1';

    $applicationStmt = $pdo->prepare($latestApplicationSql);
    $applicationStmt->execute(['student_id' => $studentId]);
    $latestApplication = $applicationStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $studentStmt = $pdo->prepare(
        'SELECT
            s.student_id,
            s.student_id_number,
            s.program,
            s.year_level,
            s.is_enrolled,
            s.is_good_standing,
            s.created_at AS student_created_at,
            u.email,
            u.first_name,
            u.last_name
         FROM students s
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE s.student_id = :student_id
         LIMIT 1'
    );
    $studentStmt->execute(['student_id' => $studentId]);
    $row = $studentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $name = trim($first . ' ' . $last);
    if ($name === '') {
        $name = 'Student';
    }

    $joinedAt = !empty($row['student_created_at']) ? date('F Y', strtotime((string) $row['student_created_at'])) : 'N/A';
    $isEnrolled = (int) ($row['is_enrolled'] ?? 0);
    $isGoodStanding = (int) ($row['is_good_standing'] ?? 1);
    $studentStatusLabel = sams_student_detail_status_label($isEnrolled, $isGoodStanding);
    $studentStatusClass = sams_student_detail_status_class($isEnrolled, $isGoodStanding);

    $applicationStatusRaw = strtolower(trim((string) ($latestApplication['status'] ?? 'pending')));
    $applicationStatusLabel = sams_student_detail_application_status_label($applicationStatusRaw);
    $applicationStatusClass = sams_student_detail_application_status_class($applicationStatusRaw);
    $applicationOffice = trim((string) ($latestApplication['preferred_office'] ?? ''));
    $skillTags = sams_student_detail_split_tags($latestApplication['skills'] ?? null);
    $availableHoursPerWeek = $latestApplication['available_hours_per_week'] !== null
        ? (int) $latestApplication['available_hours_per_week']
        : null;

    $acceptedSchedulesStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM duty_schedules ds INNER JOIN applications a ON a.application_id = ds.application_id WHERE a.student_id = :student_id AND ds.status = :status'
    );
    $acceptedSchedulesStmt->execute(['student_id' => $studentId, 'status' => 'accepted']);
    $acceptedSchedules = (int) $acceptedSchedulesStmt->fetchColumn();

    $totalSchedulesStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM duty_schedules ds INNER JOIN applications a ON a.application_id = ds.application_id WHERE a.student_id = :student_id'
    );
    $totalSchedulesStmt->execute(['student_id' => $studentId]);
    $totalSchedules = (int) $totalSchedulesStmt->fetchColumn();

    $attendanceTotals = [
        'present' => 0,
        'late' => 0,
        'absent' => 0,
        'incomplete' => 0,
        'total' => 0,
    ];

    $attendanceStmt = $pdo->prepare(
        'SELECT al.status, COUNT(*) AS total_count FROM attendance_logs al INNER JOIN applications a ON a.application_id = al.application_id WHERE a.student_id = :student_id GROUP BY al.status'
    );
    $attendanceStmt->execute(['student_id' => $studentId]);
    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $attendanceRow) {
        $status = (string) ($attendanceRow['status'] ?? '');
        $count = (int) ($attendanceRow['total_count'] ?? 0);
        if (array_key_exists($status, $attendanceTotals)) {
            $attendanceTotals[$status] = $count;
        }
        $attendanceTotals['total'] += $count;
    }

    $attendanceRate = $attendanceTotals['total'] > 0
        ? (int) round((($attendanceTotals['present'] + $attendanceTotals['late']) / $attendanceTotals['total']) * 100)
        : 0;

    $totalDutyHours = 0.0;
    try {
        $hoursStmt = $pdo->prepare('SELECT total_hours FROM student_hours_summary WHERE student_id = :student_id LIMIT 1');
        $hoursStmt->execute(['student_id' => $studentId]);
        $hoursRow = $hoursStmt->fetch(PDO::FETCH_ASSOC);
        if ($hoursRow && isset($hoursRow['total_hours'])) {
            $totalDutyHours = (float) $hoursRow['total_hours'];
        }
    } catch (Throwable $inner) {
        $hoursStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(COALESCE(al.clock_out_time, NOW()), al.clock_in_time)) / 3600), 0) AS total_hours FROM attendance_logs al INNER JOIN applications a ON a.application_id = al.application_id WHERE a.student_id = :student_id'
        );
        $hoursStmt->execute(['student_id' => $studentId]);
        $hoursRow = $hoursStmt->fetch(PDO::FETCH_ASSOC);
        if ($hoursRow && $hoursRow['total_hours'] !== null) {
            $totalDutyHours = (float) $hoursRow['total_hours'];
        }
    }

    $recentAttendanceStmt = $pdo->prepare(
        'SELECT al.status, al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.late_minutes, al.notes AS remarks, al.created_at
         FROM attendance_logs al
         INNER JOIN applications a ON a.application_id = al.application_id
         WHERE a.student_id = :student_id
         ORDER BY ' . ($attendanceLogIdColumn !== null ? 'al.' . $attendanceLogIdColumn : 'al.created_at') . ' DESC
         LIMIT 1'
    );
    $recentAttendanceStmt->execute(['student_id' => $studentId]);
    $latestAttendance = $recentAttendanceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $data = [
        'student_id' => (int) $row['student_id'],
        'student_code' => (string) ($row['student_id_number'] ?? ''),
        'first_name' => $first,
        'last_name' => $last,
        'name' => $name,
        'initials' => strtoupper(substr($first !== '' ? $first[0] : '', 0, 1) . substr($last !== '' ? $last[0] : '', 0, 1)),
        'program' => (string) ($row['program'] ?? ''),
        'year_level' => (string) ($row['year_level'] ?? ''),
        'joined_at' => $joinedAt,
        'preferred_office' => $applicationOffice,
        'skills' => $skillTags,
        'available_hours_per_week' => $availableHoursPerWeek,
        'application_status' => $applicationStatusLabel,
        'application_status_class' => $applicationStatusClass,
        'student_status_label' => $studentStatusLabel,
        'student_status_class' => $studentStatusClass,
        'total_hours' => number_format($totalDutyHours, 1),
        'accepted_schedules' => $acceptedSchedules,
        'total_schedules' => $totalSchedules,
        'attendance_rate' => $attendanceRate,
        'latest_attendance' => $latestAttendance,
        'email' => (string) ($row['email'] ?? ''),
        'phone' => 'Not provided',
    ];

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}
