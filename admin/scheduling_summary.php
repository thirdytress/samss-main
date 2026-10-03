<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $pdo = sams_pdo();
    $currentTerm = sams_current_term($pdo);
    $termId = (int) ($currentTerm['term_id'] ?? 0);
    $summaryStatement = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT CASE WHEN ds.status <> 'declined' THEN a.student_id END) AS students_scheduled,
            SUM(CASE WHEN ds.status IN ('assigned', 'pending') THEN 1 ELSE 0 END) AS awaiting_response,
            SUM(CASE WHEN ds.status = 'accepted' THEN 1 ELSE 0 END) AS accepted_shifts,
            COUNT(DISTINCT CASE WHEN ds.status = 'deployed' THEN a.student_id END) AS deployed_students,
            COALESCE(SUM(CASE WHEN ds.status <> 'declined' THEN TIMESTAMPDIFF(MINUTE, ds.start_time, ds.end_time) ELSE 0 END), 0) / 60 AS scheduled_hours
         FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         WHERE ds.term_id = :term_id"
    );
    $summaryStatement->execute(['term_id' => $termId]);
    $metrics = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'term_label' => trim((string) ($currentTerm['term_name'] ?? '') . ' ' . (string) ($currentTerm['term_year'] ?? '')) ?: 'Current Term',
        'updated_at' => date('g:i A'),
        'metrics' => [
            'students_scheduled' => (int) ($metrics['students_scheduled'] ?? 0),
            'awaiting_response' => (int) ($metrics['awaiting_response'] ?? 0),
            'accepted_shifts' => (int) ($metrics['accepted_shifts'] ?? 0),
            'deployed_students' => (int) ($metrics['deployed_students'] ?? 0),
            'scheduled_hours' => (float) ($metrics['scheduled_hours'] ?? 0),
        ],
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load scheduling summary.']);
}