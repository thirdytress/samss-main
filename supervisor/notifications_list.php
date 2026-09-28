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
    $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT a.id, a.title, a.body, a.created_at
        FROM announcements a
        LEFT JOIN announcement_reads r ON a.id = r.announcement_id AND r.user_id = :user_id
        WHERE a.is_active = 1 
          AND a.audience IN ('supervisors','all') 
          AND r.id IS NULL
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute(['user_id' => $userId]);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $body = (string) ($row['body'] ?? '');
        $snippet = mb_strlen($body) > 200 ? mb_substr($body, 0, 197) . '...' : $body;
        $items[] = [
            'type' => 'announcement',
            'notification_id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? 'Announcement'),
            'preferred_office' => 'Announcement',
            'snippet' => $snippet,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'link_url' => 'announcements.php',
        ];
    }

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
