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

$userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid user']);
    exit;
}

$notificationId = (int) ($_POST['notification_id'] ?? 0);
$type = trim((string) ($_POST['type'] ?? ''));
$markAll = !empty($_POST['mark_all']);

if (!$markAll && ($type !== 'announcement' || $notificationId <= 0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid payload']);
    exit;
}

$pdo = sams_pdo();

try {
    if ($markAll) {
        // Mark all active announcements for supervisors/all as read
        $stmt = $pdo->prepare('
            INSERT INTO announcement_reads (announcement_id, user_id, read_at)
            SELECT id, :uid, NOW()
            FROM announcements
            WHERE is_active = 1 AND audience IN ("supervisors", "all")
            ON DUPLICATE KEY UPDATE read_at = NOW()
        ');
        $stmt->execute(['uid' => $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($type === 'announcement' && $notificationId > 0) {
        $ins = $pdo->prepare(
            'INSERT INTO announcement_reads (announcement_id, user_id, read_at) 
             VALUES (:aid, :uid, NOW())
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
        );
        $ins->execute(['aid' => $notificationId, 'uid' => $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'unsupported type']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'db error', 'error' => $e->getMessage()]);
}
