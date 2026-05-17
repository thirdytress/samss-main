<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = sams_authenticated_user();
    if (!$user || (($user['role'] ?? null) !== 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    $pdo = sams_pdo();
    $pdo->query('SELECT 1');

    $currentDay = date('l');
    $activeTerm = sams_current_term($pdo);
    $activeTermId = (int) ($activeTerm['term_id'] ?? 0);

    $todayRows = [];
    if ($activeTermId > 0) {
        $todayStmt = $pdo->prepare(
            'SELECT u.first_name, u.last_name, s.student_id AS student_code,
                    COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), ""), "Unassigned") AS office_name,
                    al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, ds.start_time
             FROM duty_schedules ds
             INNER JOIN applications a ON a.application_id = ds.application_id
             LEFT JOIN students s ON s.student_id = a.student_id
             LEFT JOIN users u ON u.user_id = s.user_id
             LEFT JOIN attendance_logs al ON al.log_id = (
                 SELECT al2.log_id
                 FROM attendance_logs al2
                 WHERE al2.application_id = ds.application_id
                   AND al2.duty_id = ds.duty_id
                 ORDER BY al2.log_id DESC
                 LIMIT 1
             )
             WHERE ds.day_of_week = :day
               AND ds.status = "accepted"
               AND ds.term_id = :term_id
             ORDER BY ds.start_time ASC, al.log_id DESC'
        );
        $todayStmt->execute([
            'day' => $currentDay,
            'term_id' => $activeTermId,
        ]);
        $todayRows = $todayStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $activeNow = 0;
    $completedToday = 0;
    $totalSchedules = count($todayRows);
    $verifiedCount = 0;
    $officeSummary = [];

    foreach ($todayRows as &$todayRow) {
        $rawStatus = (string) ($todayRow['status'] ?? '');
        $status = sams_attendance_display_status($rawStatus);
        if ($status === '') {
            $status = 'absent';
        }

        $timeInRaw = !empty($todayRow['time_in']) ? (string) $todayRow['time_in'] : null;
        $timeOutRaw = !empty($todayRow['time_out']) ? (string) $todayRow['time_out'] : null;

        if (!sams_attendance_clocking_enabled()) {
            $timeInRaw = null;
            $timeOutRaw = null;
            $status = 'absent';
        }

        if ($timeInRaw !== null && $timeOutRaw === null) {
            $activeNow++;
            $verifiedCount++;
        }

        if ($timeInRaw !== null && $timeOutRaw !== null) {
            $completedToday++;
            $verifiedCount++;
        }

        $officeName = (string) ($todayRow['office_name'] ?? 'Unassigned');
        if (!isset($officeSummary[$officeName])) {
            $officeSummary[$officeName] = [
                'name' => $officeName,
                'count' => 0,
                'active' => 0,
            ];
        }
        $officeSummary[$officeName]['count']++;
        if ($timeInRaw !== null && $timeOutRaw === null) {
            $officeSummary[$officeName]['active']++;
        }

        $todayRow['time_in'] = $timeInRaw ? date('g:i A', strtotime($timeInRaw)) : '-';
        $todayRow['time_out'] = $timeOutRaw ? date('g:i A', strtotime($timeOutRaw)) : ($timeInRaw ? 'In Progress' : '-');
        $todayRow['status'] = match ($status) {
            'present', 'completed' => 'Present',
            'late' => 'Late',
            default => 'Absent',
        };
    }
    unset($todayRow);

    $offices = [];
    foreach ($officeSummary as $office) {
        $name = (string) ($office['name'] ?? 'Unassigned');
        $count = (int) ($office['count'] ?? 0);
        $pct = $totalSchedules > 0 ? (int) round(($count / $totalSchedules) * 100) : 0;

        $color = 'grey';
        $lower = strtolower($name);
        if (str_contains($lower, 'sdao')) {
            $color = 'blue';
        } elseif (str_contains($lower, 'library')) {
            $color = 'green';
        } elseif (str_contains($lower, 'computer')) {
            $color = 'purple';
        } elseif (str_contains($lower, 'registrar')) {
            $color = 'orange';
        }

        $offices[] = [
            'name' => $name,
            'count' => $count,
            'active' => (int) ($office['active'] ?? 0),
            'pct' => $pct,
            'color' => $color,
        ];
    }

    $accuracy = 100;

    echo json_encode([
        'success' => true,
        'generated_at' => date('c'),
        'metrics' => [
            'active_now' => $activeNow,
            'completed_today' => $completedToday,
            'total_schedules' => $totalSchedules,
        ],
        'today_rows' => $todayRows,
        'offices' => $offices,
        'security' => [
            'verified' => $verifiedCount,
            'accuracy' => $accuracy,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
