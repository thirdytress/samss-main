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
    $activeTermId = (int) ($pdo->query("SELECT COALESCE(MAX(term_id), 0) FROM terms WHERE start_date <= CURDATE() AND end_date >= CURDATE()")->fetchColumn() ?: 0);

    // Today's attendance records with student and office info (one row per duty schedule)
    $todayStmt = $pdo->query(
        "SELECT u.first_name, u.last_name, s.student_id AS student_code, a.preferred_office AS office_name,
                al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, ds.start_time
         FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         LEFT JOIN attendance_logs al ON al.log_id = (
             SELECT al2.log_id
             FROM attendance_logs al2
             WHERE al2.application_id = ds.application_id AND al2.duty_id = ds.duty_id
             ORDER BY al2.log_id DESC
             LIMIT 1
         )
         WHERE ds.day_of_week = " . $pdo->quote($currentDay) . "
           AND ds.status = 'accepted'
           AND ds.term_id = " . $activeTermId . "
         ORDER BY ds.start_time ASC, al.log_id DESC"
    );

    $todayRows = $todayStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!sams_attendance_clocking_enabled()) {
        foreach ($todayRows as &$todayRow) {
            $todayRow['time_in'] = null;
            $todayRow['time_out'] = null;
            $todayRow['status'] = 'absent';
        }
        unset($todayRow);
    }

    // Metrics
    $activeNowStmt = $pdo->query("SELECT COUNT(*) FROM attendance_logs WHERE 1 = 0");
    $activeNow = (int) $activeNowStmt->fetchColumn();

    $completedTodayStmt = $pdo->query("SELECT COUNT(*) FROM attendance_logs WHERE 1 = 0");
    $completedToday = (int) $completedTodayStmt->fetchColumn();

    $totalSchedulesStmt = $pdo->query(
        "SELECT COUNT(DISTINCT ds.duty_id) FROM duty_schedules ds 
         WHERE ds.status = 'assigned' AND ds.term_id = (SELECT MAX(term_id) FROM terms WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1)"
    );
    $totalSchedules = (int) $totalSchedulesStmt->fetchColumn();

    // Office distribution - attendance per office today
    $officeStmt = $pdo->query(
        "SELECT COALESCE(a.preferred_office, 'Unassigned') AS office_name,
                COUNT(DISTINCT al.log_id) AS total,
                SUM(CASE WHEN al.clock_out_time IS NULL THEN 1 ELSE 0 END) AS active
         FROM attendance_logs al
         LEFT JOIN applications a ON a.application_id = al.application_id
         WHERE DATE(al.created_at) = CURDATE()
         GROUP BY COALESCE(a.preferred_office, 'Unassigned')
         ORDER BY total DESC"
    );
    $offices = $officeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Security stats (simple counts)
    $verifiedStmt = $pdo->query("SELECT COUNT(*) FROM attendance_logs WHERE 1 = 0");
    $verifiedCount = (int) $verifiedStmt->fetchColumn();

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
