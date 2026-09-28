<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
if ($scheduleId <= 0 || !in_array($status, ['accepted', 'declined'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
if (!sams_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$pdo = sams_pdo();
try {
    $stmt = $pdo->prepare('SELECT student_id FROM students s WHERE s.user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => (int) ($currentUser['user_id'] ?? $currentUser['id'] ?? 0)]);
    $studentId = (int) ($stmt->fetchColumn() ?: 0);

    if ($studentId <= 0) {
        throw new RuntimeException('Student record not found for current user.');
    }

    $check = $pdo->prepare('SELECT COUNT(*) FROM duty_schedules ds JOIN applications a ON a.application_id = ds.application_id WHERE ds.duty_id = :id AND a.student_id = :student_id');
    $check->execute(['id' => $scheduleId, 'student_id' => $studentId]);
    if ((int) $check->fetchColumn() <= 0) {
        throw new RuntimeException('Schedule not found or does not belong to you.');
    }

    $update = $pdo->prepare('UPDATE duty_schedules SET status = :status, student_response_date = NOW(), updated_at = NOW() WHERE duty_id = :id');
    $update->execute(['status' => $status, 'id' => $scheduleId]);

    echo json_encode(['success' => true, 'message' => 'Schedule updated.', 'status' => $status]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update schedule.']);
    exit;
}
