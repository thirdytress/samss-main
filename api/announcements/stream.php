<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

// Simple SSE stream that pushes announcements and unread count when changed.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // for nginx proxy buffering
set_time_limit(0);
ignore_user_abort(true);

$pdo = sams_pdo();
$user = sams_authenticated_user();
$userId = $user ? (int)($user['user_id'] ?? $user['id'] ?? 0) : 0;

// Close session write lock so this long-running SSE connection doesn't block
// other PHP pages that need the session (prevents 'View all' hanging).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$lastState = '';
$pingAt = microtime(true) + 15;

while (true) {
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
        $ann = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $unreadStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM announcements a LEFT JOIN announcement_reads r ON a.id = r.announcement_id AND r.user_id = :user_id WHERE a.is_active = 1 AND a.audience IN ("students","all") AND r.id IS NULL'
        );
        $unreadStmt->execute(['user_id' => $userId]);
        $unread = (int)$unreadStmt->fetchColumn();

        $payload = ['announcements' => $ann, 'unread_count' => $unread];
        $state = json_encode($payload);

        if ($state !== $lastState) {
            echo "event: announcements\n";
            echo "data: " . $state . "\n\n";
            @ob_flush();
            @flush();
            $lastState = $state;
        }
    } catch (Throwable $e) {
        // send error event and continue
        $err = json_encode(['error' => 'db', 'message' => $e->getMessage()]);
        echo "event: error\n";
        echo "data: " . $err . "\n\n";
        @ob_flush();
        @flush();
    }

    // periodic ping to keep connection alive
    if (microtime(true) >= $pingAt) {
        echo ": ping\n\n";
        @ob_flush();
        @flush();
        $pingAt = microtime(true) + 15;
    }

    // sleep a short while to reduce DB load (was 0.5s)
    if (connection_aborted()) break;
    usleep(1000000); // 1s
}
