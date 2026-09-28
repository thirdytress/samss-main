<?php
declare(strict_types=1);

function sams_admin_meetings_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_meetings (
            meeting_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL,
            meeting_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NULL,
            location VARCHAR(200) NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'admin_personal',
            notes TEXT NULL,
            reminder_minutes INT NOT NULL DEFAULT 30,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_admin_meetings_owner (admin_user_id),
            KEY idx_admin_meetings_schedule (meeting_date, start_time),
            KEY idx_admin_meetings_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_meeting_notifications (
            notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT UNSIGNED NOT NULL,
            admin_user_id INT UNSIGNED NOT NULL,
            notify_type VARCHAR(20) NOT NULL,
            scheduled_notify_at DATETIME NOT NULL,
            message VARCHAR(255) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_meeting_notify (meeting_id, notify_type),
            KEY idx_admin_meeting_notifications_owner (admin_user_id, is_read),
            KEY idx_admin_meeting_notifications_time (scheduled_notify_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function sams_admin_meetings_generate_notifications(PDO $pdo, int $adminUserId): void
{
    sams_admin_meetings_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        "SELECT
            meeting_id,
            title,
            category,
            meeting_date,
            start_time,
            reminder_minutes,
            TIMESTAMP(meeting_date, start_time) AS starts_at,
            TIMESTAMPADD(MINUTE, -COALESCE(reminder_minutes, 30), TIMESTAMP(meeting_date, start_time)) AS upcoming_at
         FROM admin_meetings
         WHERE admin_user_id = :admin_user_id
           AND status = 'scheduled'
           AND meeting_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 180 DAY)"
    );
    $stmt->execute(['admin_user_id' => $adminUserId]);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($meetings)) {
        return;
    }

    $insert = $pdo->prepare(
        "INSERT IGNORE INTO admin_meeting_notifications
            (meeting_id, admin_user_id, notify_type, scheduled_notify_at, message)
         VALUES
            (:meeting_id, :admin_user_id, :notify_type, :scheduled_notify_at, :message)"
    );

    $now = new DateTimeImmutable('now');

    foreach ($meetings as $meeting) {
        $meetingId = (int) ($meeting['meeting_id'] ?? 0);
        if ($meetingId <= 0) {
            continue;
        }

        $title = trim((string) ($meeting['title'] ?? 'Meeting'));
        $category = trim((string) ($meeting['category'] ?? 'admin_personal'));
        $startsAtRaw = (string) ($meeting['starts_at'] ?? '');
        $upcomingAtRaw = (string) ($meeting['upcoming_at'] ?? '');
        $reminderMinutes = max(0, (int) ($meeting['reminder_minutes'] ?? 30));

        if ($startsAtRaw === '' || $upcomingAtRaw === '') {
            continue;
        }

        $startsAt = new DateTimeImmutable($startsAtRaw);
        $upcomingAt = new DateTimeImmutable($upcomingAtRaw);



        if ($reminderMinutes > 0 && $upcomingAt <= $now) {
            $insert->execute([
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
                'notify_type' => 'upcoming',
                'scheduled_notify_at' => $upcomingAt->format('Y-m-d H:i:s'),
                'message' => sprintf(
                    'Upcoming meeting: %s (%d minutes before) on %s at %s',
                    $title,
                    $reminderMinutes,
                    $startsAt->format('M d, Y'),
                    $startsAt->format('g:i A')
                ),
            ]);
        }

        if ($startsAt <= $now) {
            $insert->execute([
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
                'notify_type' => 'start',
                'scheduled_notify_at' => $startsAt->format('Y-m-d H:i:s'),
                'message' => sprintf(
                    'You have a meeting now: %s (%s) on %s at %s',
                    $title,
                    str_replace('_', ' ', $category),
                    $startsAt->format('M d, Y'),
                    $startsAt->format('g:i A')
                ),
            ]);
        }
    }
}

function sams_admin_meetings_fetch_upcoming(PDO $pdo, int $adminUserId, int $limit = 5): array
{
    sams_admin_meetings_ensure_schema($pdo);

    $limit = max(1, min(20, $limit));
    $stmt = $pdo->prepare(
        "SELECT
            meeting_id,
            title,
            category,
            meeting_date,
            start_time,
            end_time,
            location,
            notes,
            TIMESTAMP(meeting_date, start_time) AS starts_at
         FROM admin_meetings
         WHERE admin_user_id = :admin_user_id
           AND status = 'scheduled'
           AND TIMESTAMP(meeting_date, start_time) >= NOW()
         ORDER BY meeting_date ASC, start_time ASC
         LIMIT {$limit}"
    );
    $stmt->execute(['admin_user_id' => $adminUserId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
