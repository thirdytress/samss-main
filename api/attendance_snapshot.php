<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'student')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$pdo = sams_pdo();

// Find student's latest application for active term
$stmt = $pdo->prepare('SELECT s.student_id AS student_db_id, a.application_id, a.term_id FROM students s LEFT JOIN applications a ON a.student_id = s.student_id WHERE s.user_id = :user_id ORDER BY a.application_id DESC LIMIT 1');
$stmt->execute(['user_id' => $user['user_id'] ?? $user['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$applicationId = (int) ($row['application_id'] ?? 0);
$termId = (int) ($row['term_id'] ?? 0);

if ($applicationId <= 0) {
    echo json_encode(['success' => true, 'attendance' => [], 'summary' => ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0]]);
    exit;
}

$logs = sams_attendance_normalize_student_logs($pdo, $applicationId, $termId > 0 ? $termId : null);

$summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0];
foreach ($logs as $l) {
    $status = (string) ($l['status'] ?? 'absent');
    if ($status === 'present' || $status === 'active' || $status === 'completed') $summary['present']++;
    elseif ($status === 'late') $summary['late']++;
    else $summary['absent']++;
    $summary['total']++;
}

$recent = array_slice($logs, 0, 5);

echo json_encode(['success' => true, 'attendance' => $recent, 'summary' => $summary]);
