<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$termFilter = (int) ($_GET['term_id'] ?? 0);

$termRows = $pdo->query(
    'SELECT term_id, term_name, term_year
     FROM terms
     ORDER BY term_year DESC, term_name DESC'
)->fetchAll(PDO::FETCH_ASSOC);

if ($termFilter <= 0 && !empty($termRows)) {
    $termFilter = (int) $termRows[0]['term_id'];
}

$evaluations = [];
if ($termFilter > 0) {
    $statement = $pdo->prepare(
        'SELECT
            e.evaluation_id,
            e.performance_rating,
            e.reliability_rating,
            e.professionalism_rating,
            e.comments,
            e.submitted_at,
            a.application_id,
            a.preferred_office,
            u.first_name,
            u.last_name,
            s.student_id_number,
            sp.office_name,
            t.term_name,
            t.term_year
         FROM evaluations e
         INNER JOIN applications a ON a.application_id = e.application_id
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         INNER JOIN supervisors sp ON sp.supervisor_id = e.supervisor_id
         INNER JOIN terms t ON t.term_id = e.term_id
         WHERE e.term_id = :term_id
         ORDER BY e.submitted_at DESC, e.evaluation_id DESC'
    );
    $statement->execute(['term_id' => $termFilter]);
    $evaluations = $statement->fetchAll(PDO::FETCH_ASSOC);
}

  $pendingApplicationsStmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE status = :status');
  $pendingApplicationsStmt->execute(['status' => 'pending']);
  $pendingApplicationsCount = (int) $pendingApplicationsStmt->fetchColumn();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sams_eval_student_name(array $row): string
{
    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    return $name !== '' ? $name : 'Unassigned Student';
}

function sams_eval_avg_score(array $row): float
{
    $ratings = [
        (float) ($row['performance_rating'] ?? 0),
        (float) ($row['reliability_rating'] ?? 0),
        (float) ($row['professionalism_rating'] ?? 0),
    ];

    return array_sum($ratings) / 3;
}

$evaluationCount = count($evaluations);
$overallAverage = 0.0;
$officeCounts = [];
$latestSubmittedAt = null;

foreach ($evaluations as $evaluation) {
    $overallAverage += sams_eval_avg_score($evaluation);

    $office = trim((string) ($evaluation['preferred_office'] ?? '')) ?: 'Unassigned';
    $officeCounts[$office] = ($officeCounts[$office] ?? 0) + 1;

    $submittedAt = (string) ($evaluation['submitted_at'] ?? '');
    if ($submittedAt !== '' && ($latestSubmittedAt === null || strtotime($submittedAt) > strtotime($latestSubmittedAt))) {
        $latestSubmittedAt = $submittedAt;
    }
}

if ($evaluationCount > 0) {
    $overallAverage /= $evaluationCount;
}

$topOffice = 'Unassigned';
$topOfficeCount = 0;
foreach ($officeCounts as $officeName => $count) {
    if ($count > $topOfficeCount) {
        $topOffice = $officeName;
        $topOfficeCount = $count;
    }
}

function sams_eval_badge_class(float $score): string
{
    if ($score >= 4.5) {
        return 'score-badge score-badge--excellent';
    }

    if ($score >= 3.5) {
        return 'score-badge score-badge--good';
    }

    return 'score-badge score-badge--fair';
}

  $selectedTermLabel = 'Selected term';
  if ($termFilter > 0) {
    foreach ($termRows as $term) {
      if ((int) $term['term_id'] === $termFilter) {
        $selectedTermLabel = (string) $term['term_name'] . ' ' . (string) $term['term_year'];
        break;
      }
    }
  }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Evaluation Review | NU SA System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;800;900&display=swap" rel="stylesheet" />
  <style>
    :root {
      --clr-white: #FFFFFF;
      --clr-bg: #F9FAFB;
      --clr-border: #E5E7EB;
      --clr-border-input: #D1D5DC;
      --clr-text-primary: #101828;
      --clr-text-body: #364153;
      --clr-text-muted: #4A5565;
      --clr-blue: #155DFC;
      --clr-blue-bg: #DBEAFE;
      --clr-blue-text: #155DFC;
      --clr-green-bg: #DCFCE7;
      --clr-green-text: #008236;
      --clr-purple-bg: #F3E8FF;
      --clr-purple-text: #9810FA;
      --clr-orange-bg: #FFEDD4;
      --clr-orange-text: #F54900;
      --clr-yellow-bg: #FEF9C2;
      --clr-yellow-text: #A65F00;
      --clr-status-good-bg: #EEF2FF;
      --clr-status-good-text: #1E3A8A;
      --shadow-sm: 0 1px 3px rgba(0,0,0,.10), 0 1px 2px rgba(0,0,0,.06);
      --sidebar-width: 256px;
      --topbar-height: 89px;
      --fs-xs: 12px;
      --fs-sm: 14px;
      --fs-base: 16px;
      --fs-lg: 24px;
      --fs-xl: 32px;
      --space-4: 16px;
      --space-6: 24px;
      --space-8: 32px;
      --radius-sm: 10px;
      --radius-md: 16px;
      --radius-pill: 9999px;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: 'Inter', Arial, sans-serif;
      background: var(--clr-bg);
      color: var(--clr-text-primary);
      -webkit-font-smoothing: antialiased;
    }
    a { color: inherit; text-decoration: none; }
    button, select { font: inherit; }

    .app {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }

    .sidebar {
      width: var(--sidebar-width);
      background: var(--clr-white);
      border-right: 1px solid var(--clr-border);
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
    }
    .sidebar__header {
      height: var(--topbar-height);
      border-bottom: 1px solid var(--clr-border);
      padding: var(--space-6) var(--space-6) 0;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .sidebar__logo {
      width: 40px;
      height: 40px;
      border-radius: var(--radius-sm);
      background: linear-gradient(135deg, #155DFC 0%, #9810FA 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-weight: 800;
      flex-shrink: 0;
    }
    .sidebar__brand-name { font-size: var(--fs-base); font-weight: 800; }
    .sidebar__brand-sub { font-size: var(--fs-xs); color: var(--clr-text-muted); }
    .sidebar__nav { flex: 1; padding: var(--space-4) 16px 0; }
    .nav__list { list-style: none; display: flex; flex-direction: column; gap: 4px; }
    .nav__item { display: block; }
    .nav__link {
      display: flex;
      align-items: center;
      gap: 12px;
      min-height: 48px;
      padding: 0 16px;
      border-radius: var(--radius-sm);
      color: var(--clr-text-body);
      transition: background 0.15s;
    }
    .nav__link:hover { background: var(--clr-bg); }
    .nav__link--active { background: var(--clr-blue); color: #fff; }
    .nav__link--active .nav__label { color: #fff; }
    .nav__icon { width: 20px; height: 20px; flex-shrink: 0; display: flex; align-items: center; }
    .nav__icon svg { width: 100%; height: 100%; }
    .nav__label { font-size: var(--fs-base); line-height: 24px; flex: 1; white-space: nowrap; }
    .nav__badge { background: var(--clr-blue-bg); color: var(--clr-blue); font-size: var(--fs-xs); font-weight: 800; padding: 2px 8px; border-radius: var(--radius-pill); }
    .sidebar__footer { border-top: 1px solid var(--clr-border); padding: 17px 16px 16px; }

    .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .topbar {
      height: var(--topbar-height);
      background: var(--clr-white);
      border-bottom: 1px solid var(--clr-border);
      padding: 0 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-shrink: 0;
    }
    .topbar__title { font-size: var(--fs-lg); font-weight: 800; color: var(--clr-text-primary); line-height: 32px; }
    .topbar__sub { font-size: var(--fs-sm); color: var(--clr-text-muted); }
    .topbar__right { display: flex; align-items: center; gap: 12px; }
    .topbar__notif {
      position: relative;
      width: 36px; height: 36px;
      border-radius: var(--radius-pill);
      display: flex; align-items: center; justify-content: center;
    }
    .topbar__notif svg { width: 20px; height: 20px; }
    .topbar__notif-dot { position: absolute; top: 4px; right: 0; width: 8px; height: 8px; border-radius: 50%; background: #FB2C36; }
    .topbar__user { display: flex; align-items: center; gap: 12px; }
    .topbar__user-info { text-align: right; }
    .topbar__user-name { font-size: var(--fs-sm); color: var(--clr-text-primary); }
    .topbar__user-role { font-size: var(--fs-xs); color: var(--clr-text-muted); }
    .topbar__avatar { width: 36px; height: 36px; border-radius: var(--radius-pill); background: linear-gradient(135deg, #155DFC 0%, #9810FA 100%); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

    .content {
      padding: 24px 32px 32px;
      display: flex;
      flex-direction: column;
      gap: 24px;
      min-width: 0;
    }
    .page-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
    .page-header__title { font-size: 20px; font-weight: 800; color: var(--clr-text-primary); margin-bottom: 4px; }
    .page-header__subtitle { font-size: var(--fs-sm); color: var(--clr-text-muted); }
    .header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .header-select {
      height: 40px;
      padding: 0 12px;
      border: 1px solid var(--clr-border-input);
      border-radius: var(--radius-sm);
      background: #fff;
      color: var(--clr-text-body);
      min-width: 150px;
    }
    .btn-primary {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 40px;
      padding: 0 16px;
      border-radius: var(--radius-sm);
      background: var(--clr-blue);
      color: #fff;
      font-weight: 700;
      border: 0;
    }

    .stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
    .stat-card {
      background: #fff;
      border: 1px solid var(--clr-border);
      border-radius: var(--radius-md);
      padding: 20px;
      box-shadow: var(--shadow-sm);
      min-height: 148px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .stat-card__icon {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
    }
    .stat-card__value { font-size: 26px; font-weight: 900; color: var(--clr-text-primary); margin-top: 12px; }
    .stat-card__label { font-size: var(--fs-sm); color: var(--clr-text-body); margin-top: 2px; }
    .stat-card__hint { font-size: var(--fs-xs); color: #16A34A; margin-top: 4px; }
    .stat-card--blue .stat-card__icon { background: var(--clr-blue-bg); color: var(--clr-blue); }
    .stat-card--green .stat-card__icon { background: var(--clr-green-bg); color: var(--clr-green-text); }
    .stat-card--purple .stat-card__icon { background: var(--clr-purple-bg); color: var(--clr-purple-text); }
    .stat-card--orange .stat-card__icon { background: var(--clr-orange-bg); color: var(--clr-orange-text); }

    .panel {
      background: #fff;
      border: 1px solid var(--clr-border);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }
    .panel__header { padding: 18px 20px; border-bottom: 1px solid var(--clr-border); }
    .panel__title { font-size: 18px; font-weight: 800; color: var(--clr-text-primary); }
    .panel__sub { margin-top: 4px; font-size: var(--fs-sm); color: var(--clr-text-muted); }

    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 14px 20px; border-bottom: 1px solid var(--clr-border); vertical-align: top; }
    th { background: #F8FAFC; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: #6A7282; }
    td { color: var(--clr-text-body); }
    .student-name { font-weight: 800; color: var(--clr-text-primary); }
    .student-sub { font-size: var(--fs-xs); color: var(--clr-text-muted); margin-top: 2px; }
    .rating-group { display: flex; flex-wrap: wrap; gap: 8px; }
    .score-badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: var(--radius-pill);
      font-size: var(--fs-xs);
      font-weight: 800;
    }
    .score-badge--excellent { background: var(--clr-green-bg); color: var(--clr-green-text); }
    .score-badge--good { background: var(--clr-blue-bg); color: var(--clr-blue-text); }
    .score-badge--fair { background: var(--clr-yellow-bg); color: var(--clr-yellow-text); }
    .empty-state { padding: 28px 20px; color: var(--clr-text-muted); }

    @media (max-width: 1180px) {
      .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 1024px) {
      .sidebar { width: 220px; }
      .topbar { padding: 0 24px; }
      .content { padding: 20px 24px 24px; }
    }
    @media (max-width: 820px) {
      .app { flex-direction: column; }
      .sidebar { width: 100%; }
      .topbar { padding: 0 16px; }
      .content { padding: 16px; }
      .stat-grid { grid-template-columns: 1fr; }
      .topbar__user-info { display: none; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
</head>
<body>
  <div class="app">
    <aside class="sidebar" aria-label="Admin navigation">
      <div class="sidebar__header">
        <div class="sidebar__logo" aria-hidden="true">NU</div>
        <div>
          <div class="sidebar__brand-name">SA System</div>
          <div class="sidebar__brand-sub">Admin Panel</div>
        </div>
      </div>

      <nav class="sidebar__nav" aria-label="Site navigation">
        <ul class="nav__list">
          <li class="nav__item"><a href="dashboard.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 10l8-7 8 7v8a1 1 0 0 1-1 1h-4v-6H7v6H3a1 1 0 0 1-1-1v-8Z" fill="#364153"/></svg></span><span class="nav__label">Dashboard</span></a></li>
          <li class="nav__item"><a href="application.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 2h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z" stroke="#364153" stroke-width="1.5"/><path d="M7 7h6M7 10h6M7 13h4" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg></span><span class="nav__label">Applications</span><span class="nav__badge"><?php echo (int) $pendingApplicationsCount; ?></span></a></li>
          <li class="nav__item"><a href="scheduling.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="4" width="16" height="14" rx="2" stroke="#364153" stroke-width="1.5"/><path d="M6 2v4M14 2v4" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/><path d="M2 9h16" stroke="#364153" stroke-width="1.2"/></svg></span><span class="nav__label">Scheduling</span></a></li>
          <li class="nav__item"><a href="attendance.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="8" stroke="#364153" stroke-width="1.5"/><path d="M6.5 10.5l2.5 2.5 4.5-5" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="nav__label">Attendance</span></a></li>
          <li class="nav__item"><a href="evaluation.php" class="nav__link nav__link--active" aria-current="page"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2l2.09 4.26L17 7.27l-3.5 3.41.83 4.82L10 13.27l-4.33 2.23.83-4.82L3 7.27l4.91-.71L10 2z" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg></span><span class="nav__label">Evaluation</span></a></li>
          <li class="nav__item"><a href="reports.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="12" width="3" height="6" rx="1" fill="#364153"/><rect x="8.5" y="8" width="3" height="10" rx="1" fill="#364153"/><rect x="14" y="4" width="3" height="14" rx="1" fill="#364153"/></svg></span><span class="nav__label">Reports</span></a></li>
          <li class="nav__item"><a href="announcements.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 1c-1.5 0-2.5 1.5-2.5 3v4H4c-1.1 0-2 .9-2 2v4c0 1.1.9 2 2 2h1v2c0 1.1.9 2 2 2s2-.9 2-2v-2h4v2c0 1.1.9 2 2 2s2-.9 2-2v-2h1c1.1 0 2-.9 2-2v-4c0-1.1-.9-2-2-2h-3.5V4c0-1.5-1-3-2.5-3Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="nav__label">Announcements</span></a></li>
          <li class="nav__item"><a href="students.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="6.5" r="3" stroke="#364153" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg></span><span class="nav__label">Students</span></a></li>
        </ul>
      </nav>

      <div class="sidebar__footer">
        <ul class="nav__list">
          <li class="nav__item"><a href="settings.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8.325 2.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37a1.724 1.724 0 0 0 2.572-1.065z" stroke="#364153" stroke-width="1.3"/><circle cx="10" cy="10" r="3" stroke="#364153" stroke-width="1.3"/></svg></span><span class="nav__label">Settings</span></a></li>
          <li class="nav__item"><a href="logout.php" class="nav__link"><span class="nav__icon" aria-hidden="true"><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 3H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h3" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/><path d="M13 14l3-4-3-4M16 10H7" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="nav__label">Sign Out</span></a></li>
        </ul>
      </div>
    </aside>

    <main class="main">
      <header class="topbar">
        <div>
          <div class="topbar__title">Evaluation Review</div>
          <div class="topbar__sub">Supervisor evaluations submitted per term for admin review.</div>
        </div>
        <div class="topbar__right">
          <a href="#" class="topbar__notif" aria-label="Notifications">
            <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2a6 6 0 0 0-6 6v3.586l-.707.707A1 1 0 0 0 4 14h12a1 1 0 0 0 .707-1.707L16 11.586V8a6 6 0 0 0-6-6zM10 18a3 3 0 0 1-3-3h6a3 3 0 0 1-3 3z" fill="#4A5565"/></svg>
            <span class="topbar__notif-dot" aria-label="New notifications"></span>
          </a>
          <div class="topbar__user">
            <div class="topbar__user-info">
              <div class="topbar__user-name">Zaira Joy S. Enayo</div>
              <div class="topbar__user-role">SDAO Head</div>
            </div>
            <div class="topbar__avatar" aria-hidden="true">
              <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="7" r="4" fill="white" opacity=".9"/><path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/></svg>
            </div>
          </div>
        </div>
      </header>

      <section class="content" aria-label="Evaluation review content">
        <div class="page-header">
          <div>
            <h1 class="page-header__title">Reports &amp; Analytics</h1>
            <p class="page-header__subtitle">Detailed supervisor evaluation review with live term-based records.</p>
          </div>
          <div class="header-actions">
            <form method="get">
              <select id="term_id" name="term_id" class="header-select" onchange="this.form.submit()">
                <?php foreach ($termRows as $term): ?>
                  <option value="<?php echo (int) $term['term_id']; ?>" <?php echo (int) $term['term_id'] === $termFilter ? 'selected' : ''; ?>><?php echo h((string) $term['term_name'] . ' ' . (string) $term['term_year']); ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <button class="btn-primary" type="button">Export Report</button>
          </div>
        </div>

        <section class="stat-grid" aria-label="Evaluation summary metrics">
          <article class="stat-card stat-card--blue">
            <div class="stat-card__icon" aria-hidden="true">◌</div>
            <div>
              <div class="stat-card__value"><?php echo (int) $evaluationCount; ?></div>
              <div class="stat-card__label">Total Evaluations</div>
              <div class="stat-card__hint">Live term submissions</div>
            </div>
          </article>
          <article class="stat-card stat-card--green">
            <div class="stat-card__icon" aria-hidden="true">★</div>
            <div>
              <div class="stat-card__value"><?php echo number_format($overallAverage, 1); ?>/5</div>
              <div class="stat-card__label">Average Score</div>
              <div class="stat-card__hint">Across all ratings</div>
            </div>
          </article>
          <article class="stat-card stat-card--purple">
            <div class="stat-card__icon" aria-hidden="true">⌁</div>
            <div>
              <div class="stat-card__value"><?php echo h((string) $topOffice); ?></div>
              <div class="stat-card__label">Top Office</div>
              <div class="stat-card__hint"><?php echo (int) $topOfficeCount; ?> evaluation<?php echo $topOfficeCount === 1 ? '' : 's'; ?></div>
            </div>
          </article>
          <article class="stat-card stat-card--orange">
            <div class="stat-card__icon" aria-hidden="true">⏱</div>
            <div>
              <div class="stat-card__value"><?php echo $latestSubmittedAt ? h(date('M j', strtotime($latestSubmittedAt))) : '—'; ?></div>
              <div class="stat-card__label">Latest Submission</div>
              <div class="stat-card__hint"><?php echo $latestSubmittedAt ? h(date('g:i A', strtotime($latestSubmittedAt))) : 'No data yet'; ?></div>
            </div>
          </article>
        </section>

        <section class="panel" aria-label="Evaluation review table">
          <div class="panel__header">
            <div class="panel__title">Evaluation Review</div>
            <div class="panel__sub"><?php echo h($selectedTermLabel); ?></div>
          </div>

          <?php if (empty($evaluations)): ?>
            <div class="empty-state">No evaluations have been submitted for this term yet.</div>
          <?php else: ?>
            <div class="table-wrap">
              <table aria-label="Evaluation review list">
                <thead>
                  <tr>
                    <th>Student</th>
                    <th>Office</th>
                    <th>Supervisor</th>
                    <th>Ratings</th>
                    <th>Comments</th>
                    <th>Submitted</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($evaluations as $evaluation): ?>
                    <?php $score = sams_eval_avg_score($evaluation); ?>
                    <tr>
                      <td>
                        <div class="student-name"><?php echo h(sams_eval_student_name($evaluation)); ?></div>
                        <div class="student-sub"><?php echo h((string) ($evaluation['student_id_number'] ?? '')); ?></div>
                      </td>
                      <td><?php echo h((string) ($evaluation['preferred_office'] ?? '')); ?></td>
                      <td><?php echo h(trim((string) ($evaluation['office_name'] ?? ''))); ?></td>
                      <td>
                        <div class="rating-group">
                          <span class="score-badge score-badge--good">Perf <?php echo (int) ($evaluation['performance_rating'] ?? 0); ?></span>
                          <span class="score-badge score-badge--good">Rel <?php echo (int) ($evaluation['reliability_rating'] ?? 0); ?></span>
                          <span class="score-badge score-badge--good">Prof <?php echo (int) ($evaluation['professionalism_rating'] ?? 0); ?></span>
                          <span class="<?php echo sams_eval_badge_class($score); ?>">Avg <?php echo number_format($score, 1); ?></span>
                        </div>
                      </td>
                      <td><?php echo nl2br(h((string) ($evaluation['comments'] ?? ''))); ?></td>
                      <td><?php echo h((string) ($evaluation['submitted_at'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </section>
    </main>
  </div>
</body>
</html>