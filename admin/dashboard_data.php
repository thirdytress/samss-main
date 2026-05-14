<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = sams_authenticated_user();
    if ($user && (($user['role'] ?? null) !== 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    $pdo = sams_pdo();
    $pdo->query('SELECT 1');

    $activeStudents = (int) $pdo->query('SELECT COUNT(*) FROM students WHERE is_enrolled = 1')->fetchColumn();

    $pendingApplicationsStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE status = :status');
    $pendingApplicationsStmt->execute(['status' => 'pending']);
    $pendingApplications = (int) $pendingApplicationsStmt->fetchColumn();

    $monthlyHoursStmt = $pdo->query(
        'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, clock_in_time, clock_out_time) / 3600), 0)
         FROM attendance_logs
         WHERE YEAR(created_at) = YEAR(CURDATE())
           AND MONTH(created_at) = MONTH(CURDATE())
           AND clock_in_time IS NOT NULL 
           AND clock_out_time IS NOT NULL'
    );
    $monthlyHours = (float) $monthlyHoursStmt->fetchColumn();

    $avgRatingStmt = $pdo->query(
        'SELECT COALESCE(AVG((performance_rating + reliability_rating + professionalism_rating) / 3), 0) 
         FROM evaluations 
         WHERE performance_rating IS NOT NULL'
    );
    $avgRating = (float) $avgRatingStmt->fetchColumn();

    $recentApplicationsStmt = $pdo->query(
        "SELECT a.application_id,
                a.status,
                a.submitted_at,
                s.student_id,
                s.program,
                u.first_name,
                u.last_name
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         ORDER BY a.submitted_at DESC
         LIMIT 5"
    );

    $todayAttendanceStmt = $pdo->query(
        "SELECT u.first_name,
                u.last_name,
                a.preferred_office AS office_name,
                al.clock_in_time AS time_in,
                al.status
         FROM attendance_logs al
         LEFT JOIN duty_schedules ds ON ds.duty_id = al.duty_id
         LEFT JOIN applications a ON a.application_id = ds.application_id
         LEFT JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         WHERE DATE(al.created_at) = CURDATE()
         ORDER BY al.clock_in_time DESC
         LIMIT 6"
    );
    $todayAttendance = $todayAttendanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recentApplications = $recentApplicationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'connectivity' => 'online',
        'generated_at' => date('c'),
        'metrics' => [
            'active_students' => $activeStudents,
            'pending_applications' => $pendingApplications,
            'monthly_hours' => $monthlyHours,
            'avg_rating' => $avgRating,
        ],
        'recent_applications' => $recentApplications,
        'today_attendance' => $todayAttendance,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'connectivity' => 'offline',
        'message' => 'Dashboard data unavailable',
        'error' => $e->getMessage(),
    ]);
}
