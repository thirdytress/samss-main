<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$pdo = sams_pdo();

// Prefer application id stored in the registration workflow session
$submission = $_SESSION['registration_submission'] ?? [];
$appId = isset($submission['application_id']) ? (int) $submission['application_id'] : 0;

$user = sams_authenticated_user();
$userId = (int) ($user['user_id'] ?? 0);

try {
    if ($appId <= 0 && $userId > 0) {
        // Try to find the latest application for this user via students table.
        $stmt = $pdo->prepare(
            'SELECT a.application_id
             FROM applications a
             INNER JOIN students s ON s.student_id = a.student_id
             WHERE s.user_id = :user_id
             ORDER BY a.application_id DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $appId = (int) ($row['application_id'] ?? 0);
        }
    }

    if ($appId <= 0) {
        echo json_encode(['success' => false, 'message' => 'not_found']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT
            a.application_id,
            a.status,
            a.preferred_office,
            COALESCE(a.submitted_at, a.created_at) AS date_submitted,
            s.student_id_number AS student_number,
            s.program AS course,
            s.year_level,
            COALESCE(u.first_name, u.last_name) AS name_part,
            u.first_name,
            u.last_name
         FROM applications a
         LEFT JOIN students s ON a.student_id = s.student_id
         LEFT JOIN users u ON s.user_id = u.user_id
         WHERE a.application_id = :application_id
         LIMIT 1'
    );
    $stmt->execute(['application_id' => $appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$app) {
        echo json_encode(['success' => false, 'message' => 'not_found']);
        exit;
    }

    $fullName = trim(((string) ($app['first_name'] ?? '')) . ' ' . ((string) ($app['last_name'] ?? '')));
    if ($fullName === '') {
        $fullName = trim((string) ($app['name_part'] ?? ''));
    }

    $statusVal = strtoupper((string) ($app['status'] ?? 'PENDING'));
    $item = [
        'application_id' => (int) ($app['application_id'] ?? 0),
        'full_name' => $fullName,
        'student_id' => (string) ($app['student_number'] ?? ''),
        'course' => (string) ($app['course'] ?? ''),
        'year_level' => (string) ($app['year_level'] ?? ''),
        'date_submitted' => (string) ($app['date_submitted'] ?? ''),
        'status' => $statusVal,
        'title' => 'Application Status',
        'sub' => 'Updated application status',
        'show_availability' => true,
    ];

    echo json_encode(['success' => true, 'item' => $item]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db_error']);
}
