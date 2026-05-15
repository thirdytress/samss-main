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
    $adminUserId = (int) ($user['user_id'] ?? 0);
    if ($adminUserId > 0) {
        sams_admin_meetings_generate_notifications($pdo, $adminUserId);
    }

    $reportStmt = $pdo->query(
        "SELECT sr.report_id, sr.student_code, sr.application_id, sr.notes, sr.created_at, COALESCE(a.preferred_office, '') AS preferred_office
         FROM student_reports sr
         LEFT JOIN applications a ON a.application_id = sr.application_id
         WHERE sr.status = 'open'
         ORDER BY sr.created_at DESC
         LIMIT 8"
    );

    $meetingRows = [];
    if ($adminUserId > 0) {
        $meetingStmt = $pdo->prepare(
            "SELECT
                mn.notification_id,
                mn.meeting_id,
                mn.notify_type,
                mn.message,
                mn.scheduled_notify_at,
                m.title,
                m.category,
                m.meeting_date,
                m.start_time,
                m.location
             FROM admin_meeting_notifications mn
             INNER JOIN admin_meetings m ON m.meeting_id = mn.meeting_id
             WHERE mn.admin_user_id = :admin_user_id
               AND mn.is_read = 0
             ORDER BY mn.scheduled_notify_at DESC
             LIMIT 8"
        );
        $meetingStmt->execute(['admin_user_id' => $adminUserId]);
        $meetingRows = $meetingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $items = [];
    foreach ($reportStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'type' => 'report',
            'report_id' => (int) ($r['report_id'] ?? 0),
            'student_code' => (string) ($r['student_code'] ?? ''),
            'application_id' => (int) ($r['application_id'] ?? 0),
            'title' => 'Student report: ' . (string) ($r['student_code'] ?? 'Student'),
            'preferred_office' => (string) ($r['preferred_office'] ?? ''),
            'snippet' => mb_substr((string) ($r['notes'] ?? ''), 0, 140),
            'created_at' => (string) ($r['created_at'] ?? ''),
            'link_url' => 'report_detail.php?report_id=' . (int) ($r['report_id'] ?? 0),
        ];
    }

    $meetingNotificationIds = [];
    foreach ($meetingRows as $row) {
        $meetingNotificationIds[] = (int) ($row['notification_id'] ?? 0);
        $title = trim((string) ($row['title'] ?? 'Meeting'));
        $meetingDate = (string) ($row['meeting_date'] ?? '');
        $startTime = (string) ($row['start_time'] ?? '');
        $whenText = trim($meetingDate . ' ' . $startTime);
        $location = trim((string) ($row['location'] ?? ''));

        $items[] = [
            'type' => 'meeting',
            'meeting_id' => (int) ($row['meeting_id'] ?? 0),
            'notification_id' => (int) ($row['notification_id'] ?? 0),
            'notify_type' => (string) ($row['notify_type'] ?? ''),
            'title' => 'Meeting: ' . $title,
            'student_code' => 'Meeting',
            'preferred_office' => (string) ($row['category'] ?? ''),
            'snippet' => trim((string) ($row['message'] ?? '') . ($location !== '' ? ' Location: ' . $location : '')),
            'created_at' => (string) ($row['scheduled_notify_at'] ?? ''),
            'link_url' => 'meetings.php?meeting_id=' . (int) ($row['meeting_id'] ?? 0),
            'meeting_at' => $whenText,
        ];
    }

    if (!empty($meetingNotificationIds)) {
        $meetingNotificationIds = array_values(array_filter($meetingNotificationIds, static fn(int $id): bool => $id > 0));
        if (!empty($meetingNotificationIds)) {
            $placeholders = implode(',', array_fill(0, count($meetingNotificationIds), '?'));
            $markReadSql = "UPDATE admin_meeting_notifications SET is_read = 1, read_at = NOW() WHERE notification_id IN ({$placeholders})";
            $markReadStmt = $pdo->prepare($markReadSql);
            $markReadStmt->execute($meetingNotificationIds);
        }
    }

    usort(
        $items,
        static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        }
    );

    $items = array_slice($items, 0, 10);
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'db error']);
}
