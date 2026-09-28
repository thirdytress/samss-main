<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$announcementId = isset($input['announcement_id']) ? (int)$input['announcement_id'] : 0;
$markAll = !empty($input['mark_all']);

if ($announcementId <= 0 && !$markAll) {
    http_response_code(400);
    echo json_encode(['error' => 'announcement_id or mark_all required']);
    exit;
}

// CSRF protection: accept token via header or JSON body
$csrfToken = '';
if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrfToken = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (!empty($input['_csrf'])) {
    $csrfToken = (string) $input['_csrf'];
}
if (!sams_verify_csrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_csrf']);
    exit;
}

// Simple per-session rate limiting: allow up to 20 marks per 10 seconds
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$now = microtime(true);
$window = 10.0;
$limit = 20;
$key = 'ann_mark_timestamps';
$_SESSION[$key] = array_filter((array)($_SESSION[$key] ?? []), function ($t) use ($now, $window) { return ($t > $now - $window); });
if (count($_SESSION[$key]) >= $limit) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited']);
    exit;
}
$_SESSION[$key][] = $now;

$pdo = sams_pdo();
$userId = (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0);

try {
    if ($markAll) {
        // Mark all active announcements for this user's role as read
        $audience = (($currentUser['role'] ?? null) === 'supervisor') ? 'supervisors' : 'students';
        $stmt = $pdo->prepare('
            INSERT INTO announcement_reads (announcement_id, user_id, read_at)
            SELECT id, :uid, NOW()
            FROM announcements
            WHERE is_active = 1 AND audience IN (:aud, "all")
            ON DUPLICATE KEY UPDATE read_at = NOW()
        ');
        $stmt->execute(['uid' => $userId, 'aud' => $audience]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Use idempotent insert; requires unique key on (announcement_id, user_id)
    $ins = $pdo->prepare(
        'INSERT INTO announcement_reads (announcement_id, user_id, read_at) VALUES (:aid, :uid, NOW())
         ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
    );
    $ins->execute(['aid' => $announcementId, 'uid' => $userId]);

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    // If the table or constraint does not exist, respond gracefully
    http_response_code(500);
    echo json_encode(['error' => 'db_error', 'message' => $e->getMessage()]);
}
