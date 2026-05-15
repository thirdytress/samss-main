<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$pdo = sams_pdo();
try {
    // Count recent active announcements for supervisors or all users
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE is_active = 1 AND audience IN ('supervisors','all') AND created_at >= (NOW() - INTERVAL 30 DAY)");
    $stmt->execute();
    $count = (int) $stmt->fetchColumn();
    echo json_encode(['success' => true, 'count' => $count]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
