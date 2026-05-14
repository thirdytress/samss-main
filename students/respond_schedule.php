<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: schedule.php');
    exit;
}

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'student') {
    $_SESSION['student_schedule_flash'] = 'Unauthorized';
    header('Location: schedule.php');
    exit;
}

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
if ($scheduleId <= 0 || !in_array($status, ['accepted', 'declined'], true)) {
    $_SESSION['student_schedule_flash'] = 'Invalid request';
    header('Location: schedule.php');
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

    $update = $pdo->prepare('UPDATE duty_schedules SET status = :status, updated_at = NOW() WHERE duty_id = :id');
    $update->execute(['status' => $status, 'id' => $scheduleId]);

    $_SESSION['student_schedule_flash'] = 'Schedule updated.';
} catch (Throwable $e) {
    $_SESSION['student_schedule_flash'] = 'Error: ' . $e->getMessage();
}

header('Location: schedule.php');
exit;
