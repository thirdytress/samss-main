<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
sams_admin_meetings_ensure_schema($pdo);

$adminUserId = (int) ($currentUser['user_id'] ?? 0);
$adminName = (string) ($currentUser['name'] ?? 'Admin');

$flashMessage = '';
$flashError = '';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create_meeting') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $meetingDate = trim((string) ($_POST['meeting_date'] ?? ''));
            $startTime = trim((string) ($_POST['start_time'] ?? ''));
            $endTime = trim((string) ($_POST['end_time'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'admin_personal'));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $reminderMinutes = (int) ($_POST['reminder_minutes'] ?? 30);

            if ($title === '' || $meetingDate === '' || $startTime === '') {
                throw new RuntimeException('Title, date, and start time are required.');
            }

            $startAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $startTime);
            if (!$startAt) {
                throw new RuntimeException('Invalid date/time format.');
            }

            if ($endTime !== '') {
                $endAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $endTime);
                if (!$endAt || $endAt <= $startAt) {
                    throw new RuntimeException('End time must be later than start time.');
                }
            }

            $allowedCategories = ['admin_personal', 'sa_related', 'office_meeting', 'external'];
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'admin_personal';
            }

            $reminderMinutes = max(0, min(1440, $reminderMinutes));

            $createStmt = $pdo->prepare(
                'INSERT INTO admin_meetings
                    (admin_user_id, title, meeting_date, start_time, end_time, location, category, notes, reminder_minutes, status)
                 VALUES
                    (:admin_user_id, :title, :meeting_date, :start_time, :end_time, :location, :category, :notes, :reminder_minutes, :status)'
            );

            $createStmt->execute([
                'admin_user_id' => $adminUserId,
                'title' => $title,
                'meeting_date' => $meetingDate,
                'start_time' => $startTime,
                'end_time' => $endTime !== '' ? $endTime : null,
                'location' => $location !== '' ? $location : null,
                'category' => $category,
                'notes' => $notes !== '' ? $notes : null,
                'reminder_minutes' => $reminderMinutes,
                'status' => 'scheduled',
            ]);

            $flashMessage = 'Meeting scheduled successfully.';
        }

        if ($action === 'edit_meeting') {
            $meetingId = (int) ($_POST['meeting_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $meetingDate = trim((string) ($_POST['meeting_date'] ?? ''));
            $startTime = trim((string) ($_POST['start_time'] ?? ''));
            $endTime = trim((string) ($_POST['end_time'] ?? ''));
            $location = trim((string) ($_POST['location'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? 'admin_personal'));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $reminderMinutes = (int) ($_POST['reminder_minutes'] ?? 30);

            if ($meetingId <= 0 || $title === '' || $meetingDate === '' || $startTime === '') {
                throw new RuntimeException('Title, date, and start time are required.');
            }

            $startAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $startTime);
            if (!$startAt) {
                throw new RuntimeException('Invalid date/time format.');
            }

            if ($endTime !== '') {
                $endAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $meetingDate . ' ' . $endTime);
                if (!$endAt || $endAt <= $startAt) {
                    throw new RuntimeException('End time must be later than start time.');
                }
            }

            $allowedCategories = ['admin_personal', 'sa_related', 'office_meeting', 'external'];
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'admin_personal';
            }

            $reminderMinutes = max(0, min(1440, $reminderMinutes));

            $updateStmt = $pdo->prepare(
                'UPDATE admin_meetings
                 SET title = :title,
                     meeting_date = :meeting_date,
                     start_time = :start_time,
                     end_time = :end_time,
                     location = :location,
                     category = :category,
                     notes = :notes,
                     reminder_minutes = :reminder_minutes,
                     status = :status
                 WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id
                 LIMIT 1'
            );

            $updateStmt->execute([
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
                'title' => $title,
                'meeting_date' => $meetingDate,
                'start_time' => $startTime,
                'end_time' => $endTime !== '' ? $endTime : null,
                'location' => $location !== '' ? $location : null,
                'category' => $category,
                'notes' => $notes !== '' ? $notes : null,
                'reminder_minutes' => $reminderMinutes,
                'status' => 'scheduled',
            ]);

            $flashMessage = 'Meeting updated successfully.';
            $editMeeting = null;
        }

        if ($action === 'set_status') {
            $meetingId = (int) ($_POST['meeting_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'scheduled'));
            $allowedStatus = ['scheduled', 'completed', 'cancelled'];
            if ($meetingId <= 0 || !in_array($status, $allowedStatus, true)) {
                throw new RuntimeException('Invalid meeting update request.');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE admin_meetings SET status = :status WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id LIMIT 1'
            );
            $updateStmt->execute([
                'status' => $status,
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
            ]);

            $flashMessage = 'Meeting status updated.';
        }

        if ($action === 'delete_meeting') {
            $meetingId = (int) ($_POST['meeting_id'] ?? 0);
            if ($meetingId <= 0) {
                throw new RuntimeException('Invalid meeting delete request.');
            }

            $pdo->beginTransaction();

            $deleteNotificationsStmt = $pdo->prepare(
                'DELETE FROM admin_meeting_notifications WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id'
            );
            $deleteNotificationsStmt->execute([
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
            ]);

            $deleteMeetingStmt = $pdo->prepare(
                'DELETE FROM admin_meetings WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id LIMIT 1'
            );
            $deleteMeetingStmt->execute([
                'meeting_id' => $meetingId,
                'admin_user_id' => $adminUserId,
            ]);

            $pdo->commit();
            $flashMessage = 'Meeting removed.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flashError = $e->getMessage();
    }
}

sams_admin_meetings_generate_notifications($pdo, $adminUserId);

$upcomingMeetingsStmt = $pdo->prepare(
    "SELECT
        meeting_id,
        title,
        category,
        meeting_date,
        start_time,
        end_time,
        location,
        notes,
        reminder_minutes,
        status,
        TIMESTAMP(meeting_date, start_time) AS starts_at
     FROM admin_meetings
     WHERE admin_user_id = :admin_user_id
     ORDER BY meeting_date ASC, start_time ASC
     LIMIT 25"
);
$upcomingMeetingsStmt->execute(['admin_user_id' => $adminUserId]);
$meetings = $upcomingMeetingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$todayCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM admin_meetings WHERE admin_user_id = :admin_user_id AND meeting_date = CURDATE() AND status = :status'
);
$todayCountStmt->execute(['admin_user_id' => $adminUserId, 'status' => 'scheduled']);
$todayCount = (int) $todayCountStmt->fetchColumn();

$upcomingCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM admin_meetings
     WHERE admin_user_id = :admin_user_id
       AND status = 'scheduled'
       AND TIMESTAMP(meeting_date, start_time) >= NOW()"
);
$upcomingCountStmt->execute(['admin_user_id' => $adminUserId]);
$upcomingCount = (int) $upcomingCountStmt->fetchColumn();

$editMeeting = null;
$editMeetingId = (int) ($_GET['edit_id'] ?? 0);
if ($editMeetingId > 0) {
    $editStmt = $pdo->prepare(
        "SELECT meeting_id, title, meeting_date, start_time, end_time, location, category, notes, reminder_minutes, status
         FROM admin_meetings
         WHERE meeting_id = :meeting_id AND admin_user_id = :admin_user_id
         LIMIT 1"
    );
    $editStmt->execute([
        'meeting_id' => $editMeetingId,
        'admin_user_id' => $adminUserId,
    ]);
    $editMeeting = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$nextMeeting = null;
foreach ($meetings as $row) {
    if (($row['status'] ?? '') !== 'scheduled') {
        continue;
    }
    if (strtotime((string) ($row['starts_at'] ?? '')) >= time()) {
        $nextMeeting = $row;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Meetings</title>
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, Segoe UI, Arial, sans-serif; }
        .meetings-page { padding: 32px; display: flex; flex-direction: column; gap: 16px; }
        .meetings-header {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }
        .meetings-header__title { margin: 0; font-size: 28px; line-height: 1.15; color: var(--color-heading); }
        .meetings-header__sub { margin: 6px 0 0; color: var(--color-body); }
        .meetings-header__actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .topbar__notif-btn {
            width: 40px;
            height: 40px;
            border-radius: 999px;
            border: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            cursor: pointer;
            background: var(--color-white);
            flex-shrink: 0;
        }
        .topbar__notif-dot {
            position: absolute;
            top: 3px;
            right: 3px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            display: none;
            text-align: center;
            line-height: 18px;
        }
        .meetings-content { display: flex; flex-direction: column; gap: 16px; }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        .card {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }
        .stat__label { color: var(--color-muted); font-size: 13px; }
        .stat__value { font-size: 28px; font-weight: 800; margin-top: 8px; color: var(--color-heading); }
        .layout {
            display: grid;
            grid-template-columns: 1.1fr 1.4fr;
            gap: 16px;
        }
        label { font-size: 13px; color: var(--color-muted); margin-bottom: 5px; display: block; }
        input, select, textarea {
            width: 100%;
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: #fff;
            padding: 10px 12px;
            font: inherit;
        }
        textarea { min-height: 92px; resize: vertical; }
        .grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .alert { border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; font-size: 14px; }
        .alert--ok { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .alert--err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .meeting-list { display: flex; flex-direction: column; gap: 10px; }
        .meeting { border: 1px solid var(--color-border); border-radius: 12px; padding: 12px; background: #fff; }
        .meeting__head { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .meeting__title { font-weight: 700; color: var(--color-heading); }
        .meeting__meta { color: var(--color-body); font-size: 13px; line-height: 1.5; }
        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            font-size: 12px;
            padding: 4px 10px;
            border: 1px solid transparent;
            white-space: nowrap;
            height: fit-content;
        }
        .badge--scheduled { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .badge--completed { background: #ecfdf3; color: #166534; border-color: #bbf7d0; }
        .badge--cancelled { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .meeting__actions { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; }
        .btn {
            border: 1px solid var(--color-border);
            background: #fff;
            color: var(--color-heading);
            min-height: 36px;
            padding: 0 14px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .btn--brand { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
        .btn--brand:hover { background: var(--color-primary-dark); }
        .btn--small { min-height: 30px; border-radius: 8px; font-size: 12px; padding: 0 10px; }
        .btn--danger { border-color: #fecaca; color: #991b1b; }
        .btn--danger:hover { background: #fef2f2; }
        .meeting-form-title { margin: 0 0 10px; font-size: 19px; color: var(--color-heading); }
        .empty-state { padding: 12px; border: 1px dashed var(--color-border); border-radius: 10px; color: var(--color-body); background: #fff; }
        @media (max-width: 980px) {
            .meetings-page { padding: 20px; }
            .layout { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr; }
            .meetings-header { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <aside class="sidebar" aria-label="Admin navigation">
            <div class="sidebar__brand">
                <div class="sidebar__logo" aria-hidden="true"><span class="sidebar__logo-text">NU</span></div>
                <div>
                    <div class="sidebar__brand-name">SA System</div>
                    <div class="sidebar__brand-sub">Admin Panel</div>
                </div>
            </div>
            <nav class="sidebar__nav" aria-label="Main navigation">
                <a href="dashboard.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M2.5 7.5L10 2.5L17.5 7.5V17.5H12.5V12.5H7.5V17.5H2.5V7.5Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Dashboard
                </a>
                <a href="application.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 3h8l4 4v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 3v4h4" stroke="#364153" stroke-width="1.5"/><path d="M7 10h6M7 13h4" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Applications
                </a>
                <a href="scheduling.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="3.5" width="15" height="14" rx="1.5" stroke="#364153" stroke-width="1.5"/><path d="M2.5 6h15M7 1v4M13 1v4" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Scheduling
                </a>
                <a href="attendance.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17.5 17.5c0-4.14-3.36-7.5-7.5-7.5S2.5 13.36 2.5 17.5" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Attendance
                </a>
                <a href="evaluation.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2l2 5.5H17l-4 3 1.5 5.5L10 13l-4.5 3L7 11 3 8h5L10 2Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Evaluation
                </a>
                <a href="reports.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="2.5" width="15" height="15" rx="2" stroke="#364153" stroke-width="1.5"/><path d="M6 14V10M10 14V7M14 14V11" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Reports
                </a>
                <a href="announcements.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 1c-1.5 0-2.5 1.5-2.5 3v4H4c-1.1 0-2 .9-2 2v4c0 1.1.9 2 2 2h1v2c0 1.1.9 2 2 2s2-.9 2-2v-2h4v2c0 1.1.9 2 2 2s2-.9 2-2v-2h1c1.1 0 2-.9 2-2v-4c0-1.1-.9-2-2-2h-3.5V4c0-1.5-1-3-2.5-3Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Announcements
                </a>
                <a href="supervisors.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM3 18a7 7 0 0 1 14 0" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Supervisors
                </a>
                <a href="meetings.php" class="sidebar__nav-link sidebar__nav-link--active" aria-current="page">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="3.5" width="15" height="14" rx="1.5" stroke="white" stroke-width="1.5"/><path d="M2.5 6h15M7 1v4M13 1v4" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Meetings
                </a>
                <a href="students.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17.5 17.5c0-4.14-3.36-7.5-7.5-7.5S2.5 13.36 2.5 17.5" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Students
                </a>
            </nav>
            <div class="sidebar__footer">
                <a href="logout.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M7 3H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h3" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/><path d="M13 14l3-4-3-4M16 10H7" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Sign Out
                </a>
            </div>
        </aside>

        <div class="main">
            <header class="topbar" role="banner">
                <div class="topbar__heading">
                    <div class="topbar__title">Admin Meetings</div>
                    <div class="topbar__sub">Set your own meetings and receive reminders on exact date and time.</div>
                </div>
                <div class="meetings-header__actions">
                    <a class="btn" href="dashboard.php">Back to Dashboard</a>
                    <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M10 2a6 6 0 00-6 6v3.6l-.7.7A1 1 0 004 14h12a1 1 0 00.7-1.7l-.7-.7V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/>
                        </svg>
                        <span class="topbar__notif-dot" aria-hidden="true"></span>
                    </div>
                    <span style="font-size:13px;color:var(--color-body);"><?php echo h($adminName); ?></span>
                </div>
            </header>

            <main class="meetings-page" id="main-content">
                <section class="meetings-content">
                    <section class="stats">
                        <article class="card">
                            <div class="stat__label">Meetings Today</div>
                            <div class="stat__value"><?php echo (int) $todayCount; ?></div>
                        </article>
                        <article class="card">
                            <div class="stat__label">Upcoming Meetings</div>
                            <div class="stat__value"><?php echo (int) $upcomingCount; ?></div>
                        </article>
                        <article class="card">
                            <div class="stat__label">Next Meeting</div>
                            <div class="stat__value" style="font-size:17px;line-height:1.4;">
                                <?php if ($nextMeeting): ?>
                                    <?php echo h((string) $nextMeeting['title']); ?><br>
                                    <span style="font-size:13px;color:var(--color-body);">
                                        <?php echo h(date('M d, Y', strtotime((string) $nextMeeting['meeting_date']))); ?> at <?php echo h(date('g:i A', strtotime((string) $nextMeeting['start_time']))); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size:15px;color:var(--color-body);">No upcoming meetings</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    </section>

                    <section class="layout">
                        <article class="card">
                            <h2 class="meeting-form-title"><?php echo $editMeeting ? 'Edit Meeting' : 'Create Meeting'; ?></h2>
                <?php if ($flashMessage !== ''): ?>
                    <div class="alert alert--ok"><?php echo h($flashMessage); ?></div>
                <?php endif; ?>
                <?php if ($flashError !== ''): ?>
                    <div class="alert alert--err"><?php echo h($flashError); ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="<?php echo $editMeeting ? 'edit_meeting' : 'create_meeting'; ?>">
                    <?php if ($editMeeting): ?>
                        <input type="hidden" name="meeting_id" value="<?php echo (int) ($editMeeting['meeting_id'] ?? 0); ?>">
                    <?php endif; ?>

                    <div style="margin-bottom:10px;">
                        <label for="title">Meeting Title</label>
                        <input id="title" name="title" required placeholder="Weekly admin check-in" value="<?php echo h((string) ($editMeeting['title'] ?? '')); ?>">
                    </div>

                    <div class="grid2" style="margin-bottom:10px;">
                        <div>
                            <label for="meeting_date">Date</label>
                            <input id="meeting_date" type="date" name="meeting_date" required value="<?php echo h((string) ($editMeeting['meeting_date'] ?? '')); ?>">
                        </div>
                        <div>
                            <label for="category">Category</label>
                            <select id="category" name="category">
                                <option value="admin_personal" <?php echo (($editMeeting['category'] ?? 'admin_personal') === 'admin_personal') ? 'selected' : ''; ?>>Admin Personal</option>
                                <option value="office_meeting" <?php echo (($editMeeting['category'] ?? '') === 'office_meeting') ? 'selected' : ''; ?>>Office Meeting</option>
                                <option value="sa_related" <?php echo (($editMeeting['category'] ?? '') === 'sa_related') ? 'selected' : ''; ?>>S.A Related</option>
                                <option value="external" <?php echo (($editMeeting['category'] ?? '') === 'external') ? 'selected' : ''; ?>>External Meeting</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid2" style="margin-bottom:10px;">
                        <div>
                            <label for="start_time">Start Time</label>
                            <input id="start_time" type="time" name="start_time" required value="<?php echo h(substr((string) ($editMeeting['start_time'] ?? ''), 0, 5)); ?>">
                        </div>
                        <div>
                            <label for="end_time">End Time</label>
                            <input id="end_time" type="time" name="end_time" value="<?php echo h(substr((string) ($editMeeting['end_time'] ?? ''), 0, 5)); ?>">
                        </div>
                    </div>

                    <div class="grid2" style="margin-bottom:10px;">
                        <div>
                            <label for="location">Location / Link</label>
                            <input id="location" name="location" placeholder="SDAO Office / Google Meet link" value="<?php echo h((string) ($editMeeting['location'] ?? '')); ?>">
                        </div>
                        <div>
                            <label for="reminder_minutes">Reminder (minutes before)</label>
                            <input id="reminder_minutes" name="reminder_minutes" type="number" value="<?php echo (int) ($editMeeting['reminder_minutes'] ?? 30); ?>" min="0" max="1440">
                        </div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" placeholder="Agenda or reminders"><?php echo h((string) ($editMeeting['notes'] ?? '')); ?></textarea>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <button class="btn btn--brand" type="submit"><?php echo $editMeeting ? 'Update Meeting' : 'Save Meeting'; ?></button>
                        <?php if ($editMeeting): ?>
                            <a class="btn" href="meetings.php">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </article>

            <article class="card">
                <h2 class="meeting-form-title">Scheduled Meetings</h2>
                <div class="meeting-list">
                    <?php if (empty($meetings)): ?>
                        <div class="empty-state">No meetings scheduled yet.</div>
                    <?php endif; ?>

                    <?php foreach ($meetings as $meeting): ?>
                        <?php
                            $status = (string) ($meeting['status'] ?? 'scheduled');
                            $badgeClass = 'badge--scheduled';
                            if ($status === 'completed') {
                                $badgeClass = 'badge--completed';
                            }
                            if ($status === 'cancelled') {
                                $badgeClass = 'badge--cancelled';
                            }
                        ?>
                        <div class="meeting" id="meeting-<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                            <div class="meeting__head">
                                <div>
                                    <div class="meeting__title"><?php echo h((string) ($meeting['title'] ?? 'Meeting')); ?></div>
                                    <div class="meeting__meta">
                                        <?php echo h(date('M d, Y', strtotime((string) ($meeting['meeting_date'] ?? 'now')))); ?>
                                        at <?php echo h(date('g:i A', strtotime((string) ($meeting['start_time'] ?? '00:00:00')))); ?>
                                        <?php if (!empty($meeting['end_time'])): ?>
                                            - <?php echo h(date('g:i A', strtotime((string) $meeting['end_time']))); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge <?php echo h($badgeClass); ?>"><?php echo h(ucfirst($status)); ?></span>
                            </div>

                            <div class="meeting__meta">
                                Category: <?php echo h(str_replace('_', ' ', (string) ($meeting['category'] ?? 'admin_personal'))); ?>
                                <?php if (!empty($meeting['location'])): ?>
                                    <br>Location: <?php echo h((string) $meeting['location']); ?>
                                <?php endif; ?>
                                <?php if (!empty($meeting['notes'])): ?>
                                    <br>Notes: <?php echo h((string) $meeting['notes']); ?>
                                <?php endif; ?>
                                <br>Reminder: <?php echo (int) ($meeting['reminder_minutes'] ?? 30); ?> minutes before
                            </div>

                            <div class="meeting__actions">
                                <a class="btn btn--small" href="meetings.php?edit_id=<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">Edit</a>
                                <?php if ($status !== 'completed'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="meeting_id" value="<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                                        <input type="hidden" name="status" value="completed">
                                        <button class="btn btn--small" type="submit">Mark Completed</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($status !== 'cancelled'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="meeting_id" value="<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                                        <input type="hidden" name="status" value="cancelled">
                                        <button class="btn btn--small" type="submit" style="border-color:#fecaca;color:#991b1b;">Cancel</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($status !== 'scheduled'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="meeting_id" value="<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                                        <input type="hidden" name="status" value="scheduled">
                                        <button class="btn btn--small" type="submit">Set Scheduled</button>
                                    </form>
                                <?php endif; ?>

                                    <form method="post" onsubmit="return confirm('Delete this meeting permanently?');">
                                    <input type="hidden" name="action" value="delete_meeting">
                                    <input type="hidden" name="meeting_id" value="<?php echo (int) ($meeting['meeting_id'] ?? 0); ?>">
                                        <button class="btn btn--small btn--danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
            </section>
                </main>
            </div>
        </div>

    <script src="../assets/js/admin-notifications.js"></script>
</body>
</html>
