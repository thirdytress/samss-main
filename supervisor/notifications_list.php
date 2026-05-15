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
    $stmt = $pdo->prepare("SELECT id, title, body, created_at FROM announcements WHERE is_active = 1 AND audience IN ('supervisors','all') ORDER BY created_at DESC LIMIT 50");
    $stmt->execute();
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $body = (string) ($row['body'] ?? '');
        $snippet = mb_strlen($body) > 200 ? mb_substr($body, 0, 197) . '...' : $body;
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? 'Announcement'),
            'snippet' => $snippet,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'link_url' => 'announcements.php',
        ];
    }

    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
