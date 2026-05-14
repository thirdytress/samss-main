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
    $stmt = $pdo->query("SELECT COUNT(*) FROM student_reports WHERE status = 'open' AND is_new = 1");
    $count = (int) $stmt->fetchColumn();
    echo json_encode(['success' => true, 'count' => $count]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
