<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();

$supervisorStatement = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorOffice = trim((string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? '')));

$activeTerm = sams_current_term($pdo);
$activeTermId = (int) ($activeTerm['term_id'] ?? 0);
$termLabel = trim((string) ($activeTerm['term_name'] ?? '') . ' ' . (string) ($activeTerm['term_year'] ?? ''));
if ($termLabel === '') {
    $termLabel = 'Current Term';
}

$search = trim((string) ($_GET['q'] ?? ''));

$where = [
    'ds.status = "deployed"',
    '(ds.office_name = :office_ds OR a.preferred_office = :office_app)',
];
$params = [
    'office_ds' => $supervisorOffice,
    'office_app' => $supervisorOffice,
];

$termFilterForSchedules = '';
$termFilterForLogs = '';
$termFilterForEval = '';
if ($activeTermId > 0) {
    $where[] = 'ds.term_id = :term_id_ds';
    $params['term_id_ds'] = $activeTermId;
    $termFilterForLogs = ' AND l.term_id = :term_id_logs';
    $termFilterForEval = ' AND e.term_id = :term_id_eval';
    $params['term_id_logs'] = $activeTermId;
    $params['term_id_eval'] = $activeTermId;
}

if ($search !== '') {
    $where[] = '(u.first_name LIKE :search_first_name OR u.last_name LIKE :search_last_name OR s.student_id_number LIKE :search_student_id OR s.program LIKE :search_program)';
    $searchLike = '%' . $search . '%';
    $params['search_first_name'] = $searchLike;
    $params['search_last_name'] = $searchLike;
    $params['search_student_id'] = $searchLike;
    $params['search_program'] = $searchLike;
}

$sql =
    'SELECT
        a.application_id,
        s.student_id,
        s.student_id_number,
        s.program,
        s.year_level,
        COALESCE(u.first_name, "") AS first_name,
        COALESCE(u.last_name, "") AS last_name,
        COALESCE(NULLIF(TRIM(a.preferred_office), ""), NULLIF(TRIM(ds.office_name), ""), "Unassigned") AS office_name,
        COUNT(DISTINCT ds.duty_id) AS deployed_schedule_count,
        COALESCE((
            SELECT SUM(TIMESTAMPDIFF(SECOND, l.clock_in_time, l.clock_out_time) / 3600)
            FROM attendance_logs l
            WHERE l.application_id = a.application_id
              AND l.clock_in_time IS NOT NULL
              AND l.clock_out_time IS NOT NULL' . $termFilterForLogs . '
        ), 0) AS rendered_hours,
        COALESCE((
            SELECT AVG((e.performance_rating + e.reliability_rating + e.professionalism_rating) / 3)
            FROM evaluations e
            WHERE e.application_id = a.application_id' . $termFilterForEval . '
        ), 0) AS avg_rating
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE ' . implode(' AND ', $where) . '
     GROUP BY a.application_id, s.student_id, s.student_id_number, s.program, s.year_level, u.first_name, u.last_name, office_name
     ORDER BY u.last_name ASC, u.first_name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalStudents = count($students);
$activeStudents = $totalStudents;
$departments = [];
$ratingSum = 0.0;
$ratingCount = 0;
foreach ($students as $studentRow) {
    $departments[(string) ($studentRow['office_name'] ?? 'Unassigned')] = true;
    $rating = (float) ($studentRow['avg_rating'] ?? 0.0);
    if ($rating > 0) {
        $ratingSum += $rating;
        $ratingCount++;
    }
}
$departmentCount = count($departments);
$avgRating = $ratingCount > 0 ? $ratingSum / $ratingCount : 0.0;
$resultCount = count($students);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Students | Supervisor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <link rel="stylesheet" href="../assets/css/supervisor-notifications.css" />
    <link rel="stylesheet" href="../assets/css/notifications-shell.css?v=20260922" />
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Inter,Arial,Helvetica,sans-serif;background:var(--color-bg-app);color:var(--color-heading);min-height:100vh;display:flex}
        a{text-decoration:none;color:inherit}
        .shell{display:flex;width:100%;min-height:100vh}
        .sidebar{width:var(--sidebar-width);min-height:100vh;background:var(--color-white);border-right:1px solid var(--color-border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
        .sidebar__brand{display:flex;align-items:center;gap:12px;padding:24px 24px 20px;border-bottom:1px solid var(--color-border)}
        .sidebar__logo{width:40px;height:40px;background:var(--gradient-brand);border-radius:var(--radius-icon);display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .sidebar__logo-text{font-size:18px;font-weight:700;color:var(--color-white)}
        .sidebar__brand-name{font-size:var(--font-base);font-weight:700;color:var(--color-heading)}
        .sidebar__brand-sub{font-size:var(--font-xs);color:var(--color-body)}
        .sidebar__nav{flex:1;padding:16px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}
        .sidebar__nav-link{display:flex;align-items:center;gap:12px;height:48px;padding:0 16px;border-radius:var(--radius-nav);font-size:var(--font-base);color:var(--color-label);transition:background .15s;white-space:nowrap}
        .sidebar__nav-link:hover{background:var(--color-bg-app)}
        .sidebar__nav-link--active{background:var(--color-primary);color:#fff}
        .sidebar__nav-link--active:hover{opacity:.92}
        .sidebar__nav-icon{width:20px;height:20px;flex-shrink:0}
        .sidebar__footer{border-top:1px solid var(--color-border);padding:16px;display:flex;flex-direction:column;gap:4px;flex-shrink:0}
        .main{flex:1;min-width:0;display:flex;flex-direction:column}
        .topbar{background:var(--color-white);border-bottom:1px solid var(--color-border);height:var(--topbar-height);padding:0 32px;display:flex;align-items:center;justify-content:space-between}
        .topbar__title{font-size:var(--font-lg);font-weight:700;color:var(--color-heading)}
        .topbar__sub{font-size:var(--font-sm);color:var(--color-body)}
        .page{flex:1;padding:36px;display:flex;flex-direction:column;gap:18px}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .stat{position:relative;background:#fff;border:1px solid var(--color-border);border-radius:14px;padding:16px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
        .stat::before{content:'';position:absolute;left:0;top:0;width:100%;height:3px;border-radius:14px 14px 0 0;background:linear-gradient(90deg,#155dfc,#9810fa)}
        .stat__label{font-size:13px;color:var(--color-muted)}
        .stat__value{margin-top:6px;font-size:34px;font-weight:800;line-height:1;color:var(--color-heading)}
        .card{background:#fff;border:1px solid var(--color-border);border-radius:14px;overflow:hidden}
        .card__head{padding:16px;border-bottom:1px solid var(--color-border);background:linear-gradient(180deg,#fbfcff 0%,#ffffff 100%)}
        .card__title{font-size:18px;font-weight:700;color:var(--color-heading)}
        .card__meta{margin-top:4px;font-size:13px;color:var(--color-body)}
        .toolbar{display:flex;align-items:center;gap:12px;justify-content:space-between;padding:16px;border-bottom:1px solid var(--color-border);background:#fcfcfd}
        .search{width:100%;max-width:320px;height:40px;border:1px solid var(--color-border);border-radius:10px;padding:0 12px;font-size:14px;transition:border-color .18s ease, box-shadow .18s ease}
        .search:focus{outline:none;border-color:#9fc0ff;box-shadow:0 0 0 3px rgba(21,93,252,.12)}
        table{width:100%;border-collapse:collapse}
        thead th{padding:12px 16px;border-bottom:1px solid var(--color-border);font-size:13px;color:var(--color-heading);text-align:left;background:#f9fafb}
        tbody td{padding:12px 16px;border-bottom:1px solid var(--color-border);font-size:14px;color:var(--color-heading);vertical-align:middle}
        tbody tr:hover td{background:#f8faff}
        tbody tr:last-child td{border-bottom:none}
        .pill{display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:9999px;background:#e8f0ff;color:#155dfc;font-size:12px;font-weight:700}
        .pill--active{background:#ecfdf3;color:#027a48}
        .btn{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 12px;border-radius:10px;background:var(--gradient-brand);color:#fff;font-weight:700;font-size:13px;box-shadow:0 8px 16px rgba(21,93,252,.2)}
        .btn:hover{opacity:.95;transform:translateY(-1px)}
        .muted{color:var(--color-muted)}
        .empty{padding:24px;text-align:center;color:var(--color-muted)}
        @media (max-width:960px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.page{padding:20px}}
        @media (max-width:680px){.stats{grid-template-columns:1fr}.toolbar{flex-direction:column;align-items:stretch}.search{max-width:none}}
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar__brand">
            <div class="sidebar__logo"><span class="sidebar__logo-text">NU</span></div>
            <div>
                <div class="sidebar__brand-name">SA System</div>
                <div class="sidebar__brand-sub">Supervisor</div>
            </div>
        </div>
        <nav class="sidebar__nav" aria-label="Supervisor navigation">
            <a href="dashboard.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M2.5 7.5L10 2.5L17.5 7.5V17.5H12.5V12.5H7.5V17.5H2.5V7.5Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Dashboard
            </a>
            <a href="attendance.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M17 5L8 14.5L3.5 10" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
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
            <a href="students.php" class="sidebar__nav-link sidebar__nav-link--active" aria-current="page">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="6.5" r="3" stroke="white" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
                Students
            </a>
        </nav>
        <div class="sidebar__footer">
            <a href="logout.php" class="sidebar__nav-link">
                <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M13 15l5-5-5-5M18 10H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 17.5H3.5a.5.5 0 0 1-.5-.5V3a.5.5 0 0 1 .5-.5H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
                Sign Out
            </a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <div class="topbar__title">Student List Management</div>
                <div class="topbar__sub"><?php echo h($supervisorOffice !== '' ? $supervisorOffice : 'Assigned Office'); ?> · <?php echo h($termLabel); ?></div>
            </div>
            <div class="topbar__right">
                <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/></svg>
                    <span class="topbar__notif-dot" aria-hidden="true" style="display:none"></span>
                </div>
                <a href="logout.php" class="btn">Logout</a>
            </div>
        </header>

        <script src="../assets/js/admin-notifications.js?v=20260922"></script>

        <section class="page">
            <div class="stats">
                <div class="stat"><div class="stat__label">Total Students</div><div class="stat__value"><?php echo (int) $totalStudents; ?></div></div>
                <div class="stat"><div class="stat__label">Active</div><div class="stat__value"><?php echo (int) $activeStudents; ?></div></div>
                <div class="stat"><div class="stat__label">Avg Rating</div><div class="stat__value"><?php echo $avgRating > 0 ? number_format($avgRating, 1) . '/5' : 'N/A'; ?></div></div>
                <div class="stat"><div class="stat__label">Departments</div><div class="stat__value"><?php echo (int) $departmentCount; ?></div></div>
            </div>

            <div class="card">
                <div class="card__head">
                    <div class="card__title">Student Directory</div>
                    <div class="card__meta">Showing <?php echo (int) $resultCount; ?> result<?php echo (int) $resultCount === 1 ? '' : 's'; ?> for <?php echo h($supervisorOffice !== '' ? $supervisorOffice : 'assigned office'); ?>.</div>
                </div>
                <form method="get" class="toolbar">
                    <input class="search" type="text" name="q" value="<?php echo h($search); ?>" placeholder="Search students by name, ID, or program" />
                    <button class="btn" type="submit">Search</button>
                </form>
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Program</th>
                            <th>Office</th>
                            <th>Accepted Schedules</th>
                            <th>Hours</th>
                            <th>Rating</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($students)): ?>
                        <?php foreach ($students as $studentRow): ?>
                            <?php
                                $fullName = trim((string) ($studentRow['first_name'] ?? '') . ' ' . (string) ($studentRow['last_name'] ?? ''));
                                $rating = (float) ($studentRow['avg_rating'] ?? 0.0);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo h($fullName !== '' ? $fullName : 'Unassigned Student'); ?></strong>
                                    <div class="muted"><?php echo h((string) ($studentRow['year_level'] ?? '')); ?></div>
                                </td>
                                <td><?php echo h((string) ($studentRow['student_id_number'] ?? '')); ?></td>
                                <td><?php echo h((string) ($studentRow['program'] ?? '-')); ?></td>
                                <td><span class="pill"><?php echo h((string) ($studentRow['office_name'] ?? 'Unassigned')); ?></span></td>
                                <td><?php echo (int) ($studentRow['deployed_schedule_count'] ?? 0); ?></td>
                                <td><?php echo number_format((float) ($studentRow['rendered_hours'] ?? 0), 1); ?>h</td>
                                <td><?php echo $rating > 0 ? number_format($rating, 1) . '/5' : 'N/A'; ?></td>
                                <td><span class="pill pill--active">Active</span></td>
                                <td><a class="btn" href="student_profile.php?application_id=<?php echo (int) ($studentRow['application_id'] ?? 0); ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td class="empty" colspan="9">No deployed students found for this office.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
