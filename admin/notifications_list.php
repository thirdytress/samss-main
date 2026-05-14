<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$pdo = sams_pdo();
try {
    $stmt = $pdo->query(
        "SELECT sr.report_id, sr.student_code, sr.application_id, sr.notes, sr.created_at, COALESCE(a.preferred_office, '') AS preferred_office
         FROM student_reports sr
         LEFT JOIN applications a ON a.application_id = sr.application_id
         WHERE sr.status = 'open'
         ORDER BY sr.created_at DESC
         LIMIT 5"
    );
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'report_id' => (int) ($r['report_id'] ?? 0),
            'student_code' => (string) ($r['student_code'] ?? ''),
            'application_id' => (int) ($r['application_id'] ?? 0),
            'preferred_office' => (string) ($r['preferred_office'] ?? ''),
            'snippet' => mb_substr((string) ($r['notes'] ?? ''), 0, 140),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ];
    }
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
