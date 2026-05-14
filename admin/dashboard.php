<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();

$admin_name  = (string) ($currentUser['name'] ?? 'SAMS Admin');
$admin_role  = 'SDAO Head';
$department  = 'NU Lipa - Student Development and Activities Office';

$activeStudents = (int) $pdo->query('SELECT COUNT(*) FROM students WHERE is_enrolled = 1')->fetchColumn();
$pendingApplicationsStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE status = :status');
$pendingApplicationsStmt->execute(['status' => 'pending']);
$pendingApplications = (int) $pendingApplicationsStmt->fetchColumn();

$monthlyHoursStmt = $pdo->query(
    'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, clock_in_time, clock_out_time) / 3600), 0)
     FROM attendance_logs
     WHERE YEAR(created_at) = YEAR(CURDATE())
       AND MONTH(created_at) = MONTH(CURDATE())
       AND clock_in_time IS NOT NULL
       AND clock_out_time IS NOT NULL'
);
$monthlyHours = (float) $monthlyHoursStmt->fetchColumn();

$avgRatingStmt = $pdo->query(
    'SELECT COALESCE(AVG((performance_rating + reliability_rating + professionalism_rating) / 3), 0)
     FROM evaluations
     WHERE performance_rating IS NOT NULL'
);
$avgRating = (float) $avgRatingStmt->fetchColumn();

$recentApplicationsStmt = $pdo->query(
    "SELECT a.application_id,
            a.status,
            a.submitted_at,
            s.student_id_number,
            s.program,
            u.first_name,
            u.last_name
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     ORDER BY a.submitted_at DESC
     LIMIT 4"
);
$recentApplications = $recentApplicationsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$todayAttendanceStmt = $pdo->query(
    "SELECT u.first_name,
            u.last_name,
            COALESCE(a.preferred_office, 'Unassigned') AS office_name,
            al.clock_in_time AS time_in,
            al.status
     FROM attendance_logs al
     LEFT JOIN duty_schedules ds ON ds.duty_id = al.duty_id
     LEFT JOIN applications a ON a.application_id = ds.application_id
     LEFT JOIN students s ON s.student_id = a.student_id
     LEFT JOIN users u ON u.user_id = s.user_id
     WHERE DATE(al.created_at) = CURDATE()
     ORDER BY al.clock_in_time DESC
     LIMIT 4"
);
$todayAttendance = $todayAttendanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$alerts = [];
if ($pendingApplications > 0) {
    $alerts[] = [
        'tone' => 'warn',
        'title' => $pendingApplications . ' applications awaiting review',
        'body' => 'Review pending applications from this week',
    ];
}
if ($monthlyHours > 0) {
    $alerts[] = [
        'tone' => 'succ',
        'title' => 'Live monthly hours updated',
        'body' => 'Current month total is ' . number_format($monthlyHours, 1) . ' hours',
    ];
}
if ($avgRating > 0) {
    $alerts[] = [
        'tone' => 'info',
        'title' => 'Current evaluation average ' . number_format($avgRating, 1) . '/5',
        'body' => 'Supervisor ratings are reflected from live records',
    ];
}

function sams_admin_dashboard_initials(?string $firstName, ?string $lastName): string
{
    $a = strtoupper(substr(trim((string) $firstName), 0, 1));
    $b = strtoupper(substr(trim((string) $lastName), 0, 1));
    $initials = trim($a . $b);
    return $initials !== '' ? $initials : 'SA';
}

function sams_admin_dashboard_badge_class(string $status): string
{
    return match (strtolower($status)) {
        'approved' => 'app-row__badge--approved',
        'interview' => 'app-row__badge--interview',
        default => 'app-row__badge--pending',
    };
}

function sams_admin_dashboard_attendance_dot(string $status): string
{
    return match (strtolower($status)) {
        'present', 'completed' => 'att-row__dot--present',
        'late', 'active' => 'att-row__dot--present',
        default => 'att-row__dot--absent',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NU SA System – Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <style>
        /* =============================================
           CSS VARIABLES – Design System
        ============================================= */
        :root {
            /* Brand */
            --color-primary:         #155dfc;
            --color-primary-dark:    #1447e6;
            --color-purple:          #9810fa;
            --gradient-brand:        linear-gradient(135deg, #155dfc 0%, #9810fa 100%);

            /* Neutral */
            --color-heading:         #101828;
            --color-body:            #4a5565;
            --color-label:           #364153;
            --color-muted:           #6a7282;
            --color-border:          #e5e7eb;
            --color-bg-app:          #f9fafb;
            --color-white:           #ffffff;

            /* Status badges */
            --color-pending-bg:      #fef9c2;
            --color-pending-text:    #a65f00;
            --color-interview-bg:    #dbeafe;
            --color-interview-text:  #1447e6;
            --color-approved-bg:     #dcfce7;
            --color-approved-text:   #008236;

            /* Presence dots */
            --color-present:         #00c950;
            --color-absent:          #d1d5dc;

            /* Alert colours */
            --color-alert-warn-bg:   #fff7ed;
            --color-alert-warn-bd:   #ffd6a8;
            --color-alert-succ-bg:   #f0fdf4;
            --color-alert-succ-bd:   #b9f8cf;
            --color-alert-info-bg:   #eff6ff;
            --color-alert-info-bd:   #bedbff;
            --color-red-dot:         #fb2c36;
            --color-green-up:        #00a63e;

            /* Shadows */
            --shadow-card: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.05);

            /* Dimensions */
            --sidebar-width: 256px;
            --topbar-height: 89px;
            --radius-card:   16.4px;
            --radius-nav:    10px;
            --radius-badge:  9999px;
            --radius-icon:   10px;

            /* Typography */
            --font-xs:   12px;
            --font-sm:   14px;
            --font-base: 16px;
            --font-md:   18px;
            --font-lg:   24px;
            --font-xl:   30px;

            --lh-xs:   16px;
            --lh-sm:   20px;
            --lh-base: 24px;
            --lh-md:   28px;
            --lh-lg:   32px;
            --lh-xl:   36px;
        }

        /* =============================================
           RESET & BASE
        ============================================= */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--color-bg-app);
            color: var(--color-heading);
            min-height: 100vh;
            display: flex;
        }
        a { text-decoration: none; color: inherit; }
        img { display: block; max-width: 100%; }
        button { font-family: inherit; cursor: pointer; border: none; background: none; }

        /* =============================================
           APP SHELL
        ============================================= */
        .shell { display: flex; width: 100%; min-height: 100vh; }

        /* =============================================
           SIDEBAR
        ============================================= */
        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--color-white);
            border-right: 1px solid var(--color-border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar__brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 24px 24px 20px;
            border-bottom: 1px solid var(--color-border);
            flex-shrink: 0;
        }
        .sidebar__logo {
            width: 40px;
            height: 40px;
            background: var(--gradient-brand);
            border-radius: var(--radius-icon);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .sidebar__logo-text { font-size: 18px; font-weight: 700; color: var(--color-white); line-height: 1; }
        .sidebar__brand-name { font-size: var(--font-base); font-weight: 700; color: var(--color-heading); line-height: var(--lh-base); }
        .sidebar__brand-sub  { font-size: var(--font-xs); font-weight: 400; color: var(--color-body); line-height: var(--lh-xs); }

        .sidebar__nav {
            flex: 1;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            overflow-y: auto;
        }
        .sidebar__nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 48px;
            padding: 0 16px;
            border-radius: var(--radius-nav);
            font-size: var(--font-base);
            font-weight: 400;
            color: var(--color-label);
            transition: background .15s;
            white-space: nowrap;
        }
        .sidebar__nav-link:hover { background: var(--color-bg-app); }
        .sidebar__nav-link--active { background: var(--color-primary); color: var(--color-white); }
        .sidebar__nav-link--active:hover { opacity: .92; }
        .sidebar__nav-icon { width: 20px; height: 20px; flex-shrink: 0; }
        .sidebar__nav-badge {
            background: #dbeafe;
            color: var(--color-primary);
            font-size: var(--font-xs);
            font-weight: 700;
            line-height: var(--lh-xs);
            padding: 2px 8px;
            border-radius: var(--radius-badge);
            margin-left: auto;
        }

        .sidebar__footer {
            border-top: 1px solid var(--color-border);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }

        /* =============================================
           MAIN
        ============================================= */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        .topbar {
            background: var(--color-white);
            border-bottom: 1px solid var(--color-border);
            height: var(--topbar-height);
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .topbar__left-wrap { display: flex; align-items: center; }
        .topbar__title { font-size: var(--font-lg); font-weight: 700; line-height: var(--lh-lg); color: var(--color-heading); }
        .topbar__sub   { font-size: var(--font-sm); font-weight: 400; line-height: var(--lh-sm); color: var(--color-body); }

        .topbar__right { display: flex; align-items: center; gap: 12px; }
        .topbar__notif-btn {
            width: 36px; height: 36px;
            border-radius: var(--radius-badge);
            display: flex; align-items: center; justify-content: center;
            position: relative; cursor: pointer;
            transition: background .15s;
        }
        .topbar__notif-btn:hover { background: var(--color-bg-app); }
        .topbar__notif-btn svg { width: 20px; height: 20px; }
        .topbar__notif-dot {
            position: absolute; top: 4px; right: 4px;
            width: 8px; height: 8px;
            background: var(--color-red-dot);
            border-radius: var(--radius-badge);
        }
        .topbar__user-info { text-align: right; }
        .topbar__user-name { font-size: var(--font-sm); font-weight: 400; color: var(--color-heading); line-height: var(--lh-sm); }
        .topbar__user-role { font-size: var(--font-xs); font-weight: 400; color: var(--color-body); line-height: var(--lh-xs); }
        .topbar__avatar {
            width: 40px; height: 40px;
            background: var(--gradient-brand);
            border-radius: var(--radius-badge);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .topbar__avatar svg { width: 20px; height: 20px; }

        /* Hamburger */
        .topbar__hamburger {
            display: none;
            flex-direction: column; gap: 5px;
            width: 32px; height: 32px;
            justify-content: center; align-items: center;
            padding: 0; margin-right: 16px; cursor: pointer;
        }
        .topbar__hamburger-bar {
            display: block; width: 22px; height: 2px;
            background: var(--color-heading); border-radius: 2px;
            transition: transform .3s, opacity .3s;
        }
        .topbar__hamburger[aria-expanded="true"] .topbar__hamburger-bar:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .topbar__hamburger[aria-expanded="true"] .topbar__hamburger-bar:nth-child(2) { opacity: 0; }
        .topbar__hamburger[aria-expanded="true"] .topbar__hamburger-bar:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* =============================================
           DASHBOARD CONTENT
        ============================================= */
        .dashboard { padding: 32px; display: flex; flex-direction: column; gap: 24px; flex: 1; }

        /* =============================================
           STAT CARDS
        ============================================= */
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; }
        .stat-card {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 24px;
        }
        .stat-card__top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }
        .stat-card__icon-wrap {
            width: 48px; height: 48px;
            background: var(--color-bg-app);
            border-radius: var(--radius-icon);
            display: flex; align-items: center; justify-content: center;
        }
        .stat-card__icon-wrap svg { width: 24px; height: 24px; }
        .stat-card__trend { font-size: var(--font-sm); font-weight: 400; color: var(--color-green-up); line-height: var(--lh-sm); }
        .stat-card__value { font-size: var(--font-xl); font-weight: 700; line-height: var(--lh-xl); color: var(--color-heading); margin-bottom: 4px; }
        .stat-card__label { font-size: var(--font-sm); font-weight: 400; line-height: var(--lh-sm); color: var(--color-body); margin-bottom: 4px; }
        .stat-card__sub   { font-size: var(--font-xs); font-weight: 400; line-height: var(--lh-xs); color: var(--color-muted); }

        /* =============================================
           MID ROW
        ============================================= */
        .mid-row { display: grid; grid-template-columns: 1fr 431px; gap: 24px; }

        /* =============================================
           CARD BASE
        ============================================= */
        .card {
            background: var(--color-white);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 25px;
        }
        .card__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
        .card__title  { font-size: var(--font-md); font-weight: 700; line-height: var(--lh-md); color: var(--color-heading); }
        .card__link   { font-size: var(--font-sm); font-weight: 400; color: var(--color-primary); line-height: var(--lh-sm); transition: opacity .15s; }
        .card__link:hover { opacity: .75; }

        /* =============================================
           APPLICATION ROWS
        ============================================= */
        .app-list { display: flex; flex-direction: column; gap: 12px; }
        .app-row {
            display: flex; align-items: center; gap: 16px;
            height: 76px; padding: 0 16px;
            border-radius: var(--radius-nav);
            transition: background .12s;
        }
        .app-row:hover { background: var(--color-bg-app); }
        .app-row__avatar {
            width: 40px; height: 40px;
            background: var(--gradient-brand);
            border-radius: var(--radius-badge);
            display: flex; align-items: center; justify-content: center;
            font-size: var(--font-base); font-weight: 700; color: var(--color-white);
            flex-shrink: 0;
        }
        .app-row__info { flex: 1; min-width: 0; }
        .app-row__name   { font-size: var(--font-base); font-weight: 700; line-height: var(--lh-base); color: var(--color-heading); }
        .app-row__detail { font-size: var(--font-sm); font-weight: 400; line-height: var(--lh-sm); color: var(--color-body); }
        .app-row__meta   { text-align: right; flex-shrink: 0; }
        .app-row__badge {
            display: inline-block; height: 24px;
            padding: 4px 12px;
            border-radius: var(--radius-badge);
            font-size: var(--font-xs); font-weight: 400; line-height: var(--lh-xs);
            margin-bottom: 3px;
        }
        .app-row__badge--pending   { background: var(--color-pending-bg);   color: var(--color-pending-text); }
        .app-row__badge--interview { background: var(--color-interview-bg); color: var(--color-interview-text); }
        .app-row__badge--approved  { background: var(--color-approved-bg);  color: var(--color-approved-text); }
        .app-row__date { font-size: var(--font-xs); font-weight: 400; line-height: var(--lh-xs); color: var(--color-muted); }

        /* =============================================
           QUICK ACTIONS
        ============================================= */
        .qa-list { display: flex; flex-direction: column; gap: 12px; }
        .qa-btn {
            display: flex; align-items: center; gap: 12px;
            height: 64px; padding: 0 16px;
            background: var(--color-bg-app);
            border-radius: var(--radius-nav);
            font-size: var(--font-base); font-weight: 400; color: var(--color-heading);
            text-decoration: none; transition: background .15s; cursor: pointer;
        }
        .qa-btn:hover { background: var(--color-border); }
        .qa-btn__emoji { font-size: 24px; line-height: 1; flex-shrink: 0; width: 33px; }

        /* =============================================
           BOTTOM ROW
        ============================================= */
        .bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

        /* =============================================
           ATTENDANCE ROWS
        ============================================= */
        .att-list { display: flex; flex-direction: column; gap: 12px; }
        .att-row {
            display: flex; align-items: center; gap: 16px;
            height: 76px; padding: 0 16px;
            background: var(--color-bg-app);
            border-radius: var(--radius-nav);
        }
        .att-row__dot { width: 12px; height: 12px; border-radius: var(--radius-badge); flex-shrink: 0; }
        .att-row__dot--present { background: var(--color-present); }
        .att-row__dot--absent  { background: var(--color-absent); }
        .att-row__info { flex: 1; }
        .att-row__name     { font-size: var(--font-base); font-weight: 700; line-height: var(--lh-base); color: var(--color-heading); }
        .att-row__location { font-size: var(--font-sm); font-weight: 400; line-height: var(--lh-sm); color: var(--color-body); }
        .att-row__time     { font-size: var(--font-base); font-weight: 400; line-height: var(--lh-base); color: var(--color-heading); text-align: right; flex-shrink: 0; }

        /* =============================================
           ALERTS
        ============================================= */
        .alert-list { display: flex; flex-direction: column; gap: 12px; }
        .alert-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 17px; border-radius: var(--radius-nav); border: 1px solid;
        }
        .alert-item--warn { background: var(--color-alert-warn-bg); border-color: var(--color-alert-warn-bd); }
        .alert-item--succ { background: var(--color-alert-succ-bg); border-color: var(--color-alert-succ-bd); }
        .alert-item--info { background: var(--color-alert-info-bg); border-color: var(--color-alert-info-bd); }
        .alert-item__icon  { width: 20px; height: 20px; flex-shrink: 0; }
        .alert-item__title { font-size: var(--font-sm); font-weight: 700; line-height: var(--lh-sm); color: var(--color-heading); margin-bottom: 4px; }
        .alert-item__body  { font-size: var(--font-xs); font-weight: 400; line-height: var(--lh-xs); color: var(--color-body); }

        /* =============================================
           OVERLAY
        ============================================= */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 90; }
        .sidebar-overlay--visible { display: block; }

        /* =============================================
           RESPONSIVE – TABLET (≤1024px)
        ============================================= */
        @media (max-width: 1024px) {
            .sidebar {
                position: fixed; left: 0; top: 0; height: 100%; z-index: 100;
                transform: translateX(-100%); transition: transform .3s ease;
            }
            .sidebar--open { transform: translateX(0); }
            .topbar__hamburger { display: flex; }
            .topbar { padding: 0 24px; }
            .dashboard { padding: 24px; }
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
            .mid-row { grid-template-columns: 1fr; }
            .bottom-row { grid-template-columns: 1fr; }
        }

        /* =============================================
           RESPONSIVE – MOBILE (≤768px)
        ============================================= */
        @media (max-width: 768px) {
            .topbar { padding: 0 16px; }
            .dashboard { padding: 16px; gap: 16px; }
            .stat-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .stat-card { padding: 16px; }
            .topbar__user-info { display: none; }
            .card { padding: 16px; }
            .card__title { font-size: var(--font-base); }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>

<div class="shell">

    <!-- ══════════════════ SIDEBAR ══════════════════ -->
    <aside class="sidebar" id="sidebar" aria-label="Admin navigation">

        <div class="sidebar__brand">
            <div class="sidebar__logo" aria-hidden="true">
                <span class="sidebar__logo-text">NU</span>
            </div>
            <div>
                <div class="sidebar__brand-name">SA System</div>
                <div class="sidebar__brand-sub">Admin Panel</div>
            </div>
        </div>

        <nav class="sidebar__nav" aria-label="Main navigation">

            <a href="dashboard.php" class="sidebar__nav-link sidebar__nav-link--active" aria-current="page">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M2.5 7.5L10 2.5L17.5 7.5V17.5H12.5V12.5H7.5V17.5H2.5V7.5Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Dashboard
            </a>

            <a href="applications.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M4 3h8l4 4v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 3v4h4" stroke="#364153" stroke-width="1.5"/>
                    <path d="M7 10h6M7 13h4" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Applications
                <?php if ((int) $pendingApplications > 0): ?><span class="sidebar__nav-badge" id="nav-pending-count"><?= (int) $pendingApplications ?></span><?php endif; ?>
            </a>

            <a href="scheduling.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <rect x="2.5" y="3" width="15" height="14" rx="2" stroke="#364153" stroke-width="1.5"/>
                    <path d="M2.5 7h15" stroke="#364153" stroke-width="1.5"/>
                    <path d="M6.5 1.5V4M13.5 1.5V4" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Scheduling
            </a>

            <a href="attendance.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M17 5L8 14.5L3.5 10" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Attendance
            </a>

            <a href="evaluation.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M10 2l2 5.5H17l-4 3 1.5 5.5L10 13l-4.5 3L7 11 3 8h5L10 2Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Evaluation
            </a>

            <a href="reports.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <rect x="2.5" y="2.5" width="15" height="15" rx="2" stroke="#364153" stroke-width="1.5"/>
                    <path d="M6 14V10M10 14V7M14 14V11" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Reports
            </a>

            <a href="announcements.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M10 1c-1.5 0-2.5 1.5-2.5 3v4H4c-1.1 0-2 .9-2 2v4c0 1.1.9 2 2 2h1v2c0 1.1.9 2 2 2s2-.9 2-2v-2h4v2c0 1.1.9 2 2 2s2-.9 2-2v-2h1c1.1 0 2-.9 2-2v-4c0-1.1-.9-2-2-2h-3.5V4c0-1.5-1-3-2.5-3Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Announcements
            </a>

            <a href="students.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17.5 17.5c0-4.14-3.36-7.5-7.5-7.5S2.5 13.36 2.5 17.5" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Students
            </a>

        </nav>

        <div class="sidebar__footer">
            <a href="settings.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="2.5" stroke="#364153" stroke-width="1.5"/>
                    <path d="M17.14 12.19A7.5 7.5 0 0 0 17.5 10a7.5 7.5 0 0 0-.36-2.19l1.57-1.57-2.5-4.33-2.08.76A7.5 7.5 0 0 0 12 1.92V0H8v1.92a7.5 7.5 0 0 0-2.13.75l-2.08-.76L1.29 6.24l1.57 1.57A7.5 7.5 0 0 0 2.5 10a7.5 7.5 0 0 0 .36 2.19l-1.57 1.57 2.5 4.33 2.08-.76A7.5 7.5 0 0 0 8 18.08V20h4v-1.92a7.5 7.5 0 0 0 2.13-.75l2.08.76 2.5-4.33-1.57-1.57Z" stroke="#364153" stroke-width="1.5"/>
                </svg>
                Settings
            </a>
            <a href="logout.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M13 15l5-5-5-5M18 10H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M8 17.5H3.5a.5.5 0 0 1-.5-.5V3a.5.5 0 0 1 .5-.5H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Sign Out
            </a>
        </div>

    </aside>

    <!-- ══════════════════ MAIN ══════════════════ -->
    <div class="main">

        <header class="topbar" role="banner">
            <div class="topbar__left-wrap">
                <button class="topbar__hamburger" id="hamburger-btn" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">
                    <span class="topbar__hamburger-bar"></span>
                    <span class="topbar__hamburger-bar"></span>
                    <span class="topbar__hamburger-bar"></span>
                </button>
                <div class="topbar__left">
                    <div class="topbar__title">Dashboard</div>
                    <div class="topbar__sub"><?= htmlspecialchars($department) ?></div>
                </div>
            </div>
            <div class="topbar__right">
                <span id="connectivity-badge" style="font-size:12px;font-weight:700;padding:6px 10px;border-radius:9999px;background:#fef3c6;color:#a65f00;">Checking...</span>
                <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M15 6.67A5 5 0 0 0 5 6.67C5 12.5 2.5 14.17 2.5 14.17h15S15 12.5 15 6.67Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M11.44 17.5a1.67 1.67 0 0 1-2.88 0" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <span class="topbar__notif-dot" aria-hidden="true"></span>
                </div>
                <script>
                (function(){
                    function updateBell(count){
                        var dot = document.querySelector('.topbar__notif-dot');
                        if(!dot) return;
                        if(count && count>0){ dot.style.display=''; dot.textContent = count>99? '99+' : String(count);} else { dot.style.display='none'; dot.textContent=''; }
                    }
                    function poll(){ fetch('notifications_count.php', { credentials:'same-origin' }).then(function(r){ if(!r.ok) throw 0; return r.json(); }).then(function(d){ if(d && d.success) updateBell(d.count); }).catch(function(){}); }
                    poll(); setInterval(poll,10000);
                })();
                </script>
                <script>
                (function(){
                    var bell = document.querySelector('.topbar__notif') || document.querySelector('.topbar__notif-btn');
                    if(!bell) return;
                    var dropdown = null;
                    function show(items){
                        if(!dropdown){
                            dropdown = document.createElement('div');
                            dropdown.style.position='absolute'; dropdown.style.right='24px'; dropdown.style.top='84px'; dropdown.style.width='360px';
                            dropdown.style.background='#fff'; dropdown.style.border='1px solid #e6eef7'; dropdown.style.borderRadius='8px'; dropdown.style.boxShadow='0 8px 24px rgba(3,7,18,.08)'; dropdown.style.zIndex=9999; dropdown.style.overflow='hidden';
                            document.body.appendChild(dropdown);
                        }
                        if(!items||items.length===0){ dropdown.innerHTML='<div style="padding:12px;color:#6b7280">No recent reports</div>'; return; }
                        var html='<div style="max-height:360px;overflow:auto">';
                        items.forEach(function(it){ html += '<a href="report_detail.php?report_id='+encodeURIComponent(it.report_id)+'" style="display:block;padding:10px 12px;border-bottom:1px solid #f1f5f9;color:#0b0b0b;text-decoration:none">' +
                            '<div style="font-weight:600">'+(it.student_code||'Student')+' <span style="float:right;color:#6b7280;font-weight:400">'+it.created_at+'</span></div>' +
                            '<div style="color:#6b7280;font-size:13px;margin-top:6px">'+(it.snippet||'')+'</div>' +
                            '</a>'; });
                        html += '</div>'; dropdown.innerHTML = html; dropdown.style.display = '';
                    }
                    bell.addEventListener('click', function(e){ e.preventDefault(); fetch('notifications_list.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{ if(d && d.success) show(d.items); }).catch(()=>{}); });
                    document.addEventListener('click', function(ev){ if(!dropdown) return; if(ev.target.closest && (ev.target.closest('.topbar__notif')||ev.target.closest('.topbar__notif-btn'))) return; if(ev.target.closest && ev.target.closest('a')) return; dropdown.style.display='none'; });
                })();
                </script>
                <div class="topbar__user-info" aria-label="Logged in user">
                    <div class="topbar__user-name"><?= htmlspecialchars($admin_name) ?></div>
                    <div class="topbar__user-role"><?= htmlspecialchars($admin_role) ?></div>
                </div>
                <div class="topbar__avatar" aria-hidden="true">
                    <svg viewBox="0 0 20 20" fill="none">
                        <path d="M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM17.5 17.5c0-4.14-3.36-7.5-7.5-7.5S2.5 13.36 2.5 17.5" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>
        </header>

        <main class="dashboard" id="main-content">

            <!-- STAT CARDS -->
            <div class="stat-grid" role="list" aria-label="Key metrics">

                <div class="stat-card" role="listitem">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round"/>
                                <circle cx="9" cy="7" r="4" stroke="#4a5565" stroke-width="1.5"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__trend" aria-label="Trending up">↑</span>
                    </div>
                    <div class="stat-card__value" id="metric-active-students"><?= (int) $activeStudents ?></div>
                    <div class="stat-card__label">Active Student Assistants</div>
                    <div class="stat-card__sub">+3 this month</div>
                </div>

                <div class="stat-card" role="listitem">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2Z" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </div>
                    <div class="stat-card__value" id="metric-pending-applications"><?= (int) $pendingApplications ?></div>
                    <div class="stat-card__label">Pending Applications</div>
                    <div class="stat-card__sub">Needs review</div>
                </div>

                <div class="stat-card" role="listitem">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="9" stroke="#4a5565" stroke-width="1.5"/>
                                <path d="M12 7v5l3 3" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__trend" aria-label="Trending up">↑</span>
                    </div>
                    <div class="stat-card__value" id="metric-monthly-hours"><?= number_format($monthlyHours, 0) ?></div>
                    <div class="stat-card__label">Total Hours (This Month)</div>
                    <div class="stat-card__sub">+12% from last month</div>
                </div>

                <div class="stat-card" role="listitem">
                    <div class="stat-card__top">
                        <div class="stat-card__icon-wrap" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M22 7L13.5 15.5L8.5 10.5L2 17" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M16 7h6v6" stroke="#4a5565" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <span class="stat-card__trend" aria-label="Trending up">↑</span>
                    </div>
                    <div class="stat-card__value" id="metric-avg-rating"><?= number_format($avgRating, 1) ?>/5</div>
                    <div class="stat-card__label">Avg. Performance Rating</div>
                    <div class="stat-card__sub">+0.2 improvement</div>
                </div>

            </div>

            <!-- MID ROW -->
            <div class="mid-row">

                <section class="card" aria-labelledby="recent-apps-heading">
                    <div class="card__header">
                        <h2 class="card__title" id="recent-apps-heading">Recent Applications</h2>
                        <a href="applications.php" class="card__link">View All →</a>
                    </div>
                    <div class="app-list" id="recent-apps-list">
                        <?php foreach ($recentApplications as $application): ?>
                        <?php $fullName = trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? '')); ?>
                        <div class="app-row">
                            <div class="app-row__avatar" aria-hidden="true"><?= htmlspecialchars(sams_admin_dashboard_initials((string) ($application['first_name'] ?? ''), (string) ($application['last_name'] ?? ''))) ?></div>
                            <div class="app-row__info">
                                <div class="app-row__name"><?= htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Student') ?></div>
                                <div class="app-row__detail"><?= htmlspecialchars((string) ($application['student_id_number'] ?? '')) ?> • <?= htmlspecialchars((string) ($application['program'] ?? '')) ?></div>
                            </div>
                            <div class="app-row__meta">
                                <span class="app-row__badge <?= htmlspecialchars(sams_admin_dashboard_badge_class((string) ($application['status'] ?? 'pending'))) ?>"><?= htmlspecialchars((string) ($application['status'] ?? 'pending')) ?></span>
                                <div class="app-row__date"><?= htmlspecialchars(!empty($application['submitted_at']) ? date('M j, Y', strtotime((string) $application['submitted_at'])) : 'No date') ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="card" aria-labelledby="qa-heading">
                    <h2 class="card__title" id="qa-heading" style="margin-bottom:24px;">Quick Actions</h2>
                    <div class="qa-list">
                        <a href="applications.php" class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">📋</span> Review Applications</a>
                        <a href="scheduling.php"   class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">📅</span> Create Schedule</a>
                        <a href="reports.php"      class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">📄</span> Generate Reports</a>
                        <a href="evaluation.php"   class="qa-btn"><span class="qa-btn__emoji" aria-hidden="true">✍️</span> Evaluate Students</a>
                    </div>
                </section>

            </div>

            <!-- BOTTOM ROW -->
            <div class="bottom-row">

                <section class="card" aria-labelledby="attendance-heading">
                    <div class="card__header">
                        <h2 class="card__title" id="attendance-heading">Today's Attendance</h2>
                        <a href="attendance.php" class="card__link">View All →</a>
                    </div>
                    <div class="att-list" id="today-attendance-list">
                        <?php foreach ($todayAttendance as $attendance): ?>
                        <div class="att-row">
                            <span class="att-row__dot <?= htmlspecialchars(sams_admin_dashboard_attendance_dot((string) ($attendance['status'] ?? ''))) ?>" aria-label="<?= htmlspecialchars((string) ($attendance['status'] ?? 'Scheduled')) ?>"></span>
                            <div class="att-row__info">
                                <div class="att-row__name"><?= htmlspecialchars(trim((string) ($attendance['first_name'] ?? '') . ' ' . (string) ($attendance['last_name'] ?? '')) ?: 'Unassigned Student') ?></div>
                                <div class="att-row__location"><?= htmlspecialchars((string) ($attendance['office_name'] ?? '-')) ?></div>
                            </div>
                            <div class="att-row__time"><?= htmlspecialchars(!empty($attendance['time_in']) ? date('g:i A', strtotime((string) $attendance['time_in'])) : 'Not yet') ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="card" aria-labelledby="alerts-heading">
                    <h2 class="card__title" id="alerts-heading" style="margin-bottom:24px;">Alerts &amp; Notifications</h2>
                    <div class="alert-list">
                        <?php foreach ($alerts as $alert): ?>
                        <div class="alert-item alert-item--<?= htmlspecialchars((string) ($alert['tone'] ?? 'info')) ?>" role="status">
                            <svg class="alert-item__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <div>
                                <div class="alert-item__title"><?= htmlspecialchars((string) ($alert['title'] ?? 'Live alert')) ?></div>
                                <div class="alert-item__body"><?= htmlspecialchars((string) ($alert['body'] ?? '')) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

            </div>

        </main>
    </div>

</div>

<script>
(function () {
    'use strict';
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    var hamburger = document.getElementById('hamburger-btn');

    function open() {
        sidebar.classList.add('sidebar--open');
        overlay.classList.add('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'true');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function close() {
        sidebar.classList.remove('sidebar--open');
        overlay.classList.remove('sidebar-overlay--visible');
        hamburger.setAttribute('aria-expanded', 'false');
        overlay.setAttribute('aria-hidden', 'true');
    }

    if (hamburger) {
        hamburger.addEventListener('click', function () {
            sidebar.classList.contains('sidebar--open') ? close() : open();
        });
    }
    if (overlay) {
        overlay.addEventListener('click', close);
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            close();
        }
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 1024) {
            close();
        }
    });

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch] || ch;
        });
    }

    function statusClass(status) {
        var normalized = String(status || '').toLowerCase();
        if (normalized === 'approved') {
            return 'app-row__badge--approved';
        }
        if (normalized === 'interview') {
            return 'app-row__badge--interview';
        }
        return 'app-row__badge--pending';
    }

    function initials(firstName, lastName) {
        var a = (firstName || '').trim().charAt(0).toUpperCase();
        var b = (lastName || '').trim().charAt(0).toUpperCase();
        return (a + b) || 'SA';
    }

    function formatHours(value) {
        return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 1 });
    }

    function formatRating(value) {
        var rating = Number(value || 0);
        return rating > 0 ? rating.toFixed(1) + '/5' : 'N/A';
    }

    function formatDate(value) {
        if (!value) {
            return 'No date';
        }
        var dt = new Date(value);
        if (isNaN(dt.getTime())) {
            return String(value);
        }
        return dt.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatTime(value) {
        if (!value) {
            return 'Not yet';
        }
        var dt = new Date('1970-01-01T' + String(value));
        if (isNaN(dt.getTime())) {
            return String(value);
        }
        return dt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function setConnectivity(state, text) {
        var badge = document.getElementById('connectivity-badge');
        if (!badge) {
            return;
        }

        if (state === 'online') {
            badge.style.background = '#dcfce7';
            badge.style.color = '#166534';
        } else if (state === 'offline') {
            badge.style.background = '#fee2e2';
            badge.style.color = '#991b1b';
        } else {
            badge.style.background = '#fef3c6';
            badge.style.color = '#a65f00';
        }

        badge.textContent = text;
    }

    function renderRecentApplications(list) {
        var root = document.getElementById('recent-apps-list');
        if (!root) {
            return;
        }

        if (!Array.isArray(list) || list.length === 0) {
            root.innerHTML = '<div class="app-row"><div class="app-row__info"><div class="app-row__name">No recent applications</div><div class="app-row__detail">No data available</div></div></div>';
            return;
        }

        root.innerHTML = list.map(function (item) {
            var first = item.first_name || '';
            var last = item.last_name || '';
            var fullName = (first + ' ' + last).trim() || 'Student Assistant';
            var studentCode = item.student_id || 'N/A';
            var program = item.program || 'N/A';
            var status = String(item.status || 'pending').toLowerCase();
            var dateText = formatDate(item.submitted_at);

            return '<div class="app-row">'
                + '<div class="app-row__avatar" aria-hidden="true">' + esc(initials(first, last)) + '</div>'
                + '<div class="app-row__info">'
                + '<div class="app-row__name">' + esc(fullName) + '</div>'
                + '<div class="app-row__detail">' + esc(studentCode) + ' • ' + esc(program) + '</div>'
                + '</div>'
                + '<div class="app-row__meta">'
                + '<span class="app-row__badge ' + statusClass(status) + '">' + esc(status) + '</span>'
                + '<div class="app-row__date">' + esc(dateText) + '</div>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    function renderTodayAttendance(list) {
        var root = document.getElementById('today-attendance-list');
        if (!root) {
            return;
        }

        if (!Array.isArray(list) || list.length === 0) {
            root.innerHTML = '<div class="att-row"><div class="att-row__info"><div class="att-row__name">No schedules for today</div><div class="att-row__location">Try again later</div></div><div class="att-row__time">-</div></div>';
            return;
        }

        root.innerHTML = list.map(function (item) {
            var first = item.first_name || '';
            var last = item.last_name || '';
            var fullName = (first + ' ' + last).trim() || 'Student Assistant';
            var office = item.office_name || 'Assigned Office';
            var status = String(item.status || '').toLowerCase();
            var checkedIn = status === 'present' || status === 'late' || status === 'incomplete' || !!item.time_in;
            var dotClass = checkedIn ? 'att-row__dot--present' : 'att-row__dot--absent';

            return '<div class="att-row">'
                + '<span class="att-row__dot ' + dotClass + '" aria-label="Attendance status"></span>'
                + '<div class="att-row__info">'
                + '<div class="att-row__name">' + esc(fullName) + '</div>'
                + '<div class="att-row__location">' + esc(office) + '</div>'
                + '</div>'
                + '<div class="att-row__time">' + esc(formatTime(item.time_in)) + '</div>'
                + '</div>';
        }).join('');
    }

    function updateMetrics(payload) {
        var metrics = payload.metrics || {};

        var activeEl = document.getElementById('metric-active-students');
        var pendingEl = document.getElementById('metric-pending-applications');
        var hoursEl = document.getElementById('metric-monthly-hours');
        var ratingEl = document.getElementById('metric-avg-rating');
        var navPendingEl = document.getElementById('nav-pending-count');

        if (activeEl) {
            activeEl.textContent = String(metrics.active_students ?? 0);
        }
        if (pendingEl) {
            pendingEl.textContent = String(metrics.pending_applications ?? 0);
        }
        if (hoursEl) {
            hoursEl.textContent = formatHours(metrics.monthly_hours);
        }
        if (ratingEl) {
            ratingEl.textContent = formatRating(metrics.avg_rating);
        }
        if (navPendingEl) {
            navPendingEl.textContent = String(metrics.pending_applications ?? 0);
        }

        renderRecentApplications(payload.recent_applications || []);
        renderTodayAttendance(payload.today_attendance || []);
    }

    function refreshDashboard() {
        setConnectivity('checking', 'Checking...');
        fetch('dashboard_data.php?t=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (payload) {
                if (!payload || payload.success !== true) {
                    throw new Error((payload && payload.message) || 'Invalid response');
                }
                updateMetrics(payload);
                setConnectivity('online', 'Live');
            })
            .catch(function () {
                setConnectivity('offline', 'Offline');
            });
    }

    refreshDashboard();
    window.setInterval(refreshDashboard, 20000);
})();
</script>

</body>
</html>