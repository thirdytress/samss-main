<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = sams_authenticated_user();
if (!$currentUser) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$pdo = sams_pdo();
$userId = (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0);

try {
    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.body, a.created_at,
                EXISTS(SELECT 1 FROM announcement_reads r WHERE r.announcement_id = a.id AND r.user_id = :user_id) AS is_read
         FROM announcements a
         WHERE a.is_active = 1 AND a.audience IN ("students","all")
         ORDER BY a.created_at DESC
         LIMIT 10'
    );
    $stmt->execute(['user_id' => $userId]);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $unreadStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM announcements a LEFT JOIN announcement_reads r ON a.id = r.announcement_id AND r.user_id = :user_id WHERE a.is_active = 1 AND a.audience IN ("students","all") AND r.id IS NULL'
    );
    $unreadStmt->execute(['user_id' => $userId]);
    $unreadCount = (int)$unreadStmt->fetchColumn();

    echo json_encode(['announcements' => $announcements, 'unread_count' => $unreadCount]);
} catch (PDOException $e) {
    // If table missing or other DB error, return fallback empty set
    echo json_encode(['announcements' => [], 'unread_count' => 0]);
}
