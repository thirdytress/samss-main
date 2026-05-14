<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'supervisor') {
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

$supervisor_name = $user['name'];
$office_name = (string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? 'Assigned Office'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Supervisor Dashboard – SAMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <style>
        /* Reuse admin design system for consistent shell */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--color-bg-app); color: var(--color-heading); min-height: 100vh; display: flex; }
        a { text-decoration: none; color: inherit; }
        .shell { display: flex; width: 100%; min-height: 100vh; }
        .sidebar { width: var(--sidebar-width); min-height: 100vh; background: var(--color-white); border-right: 1px solid var(--color-border); display: flex; flex-direction: column; }
        .sidebar__brand { display:flex; align-items:center; gap:12px; padding:24px 24px 20px; border-bottom:1px solid var(--color-border); }
        .sidebar__logo { width:40px; height:40px; background:var(--gradient-brand); border-radius:var(--radius-icon); display:flex; align-items:center; justify-content:center; }
        .sidebar__logo-text{font-size:18px;font-weight:700;color:var(--color-white);} .sidebar__brand-name{font-size:var(--font-base);font-weight:700}
        .sidebar__brand-sub{font-size:var(--font-xs);color:var(--color-body)}
        .sidebar__nav{flex:1;padding:16px;display:flex;flex-direction:column;gap:4px;overflow-y:auto}
        .sidebar__nav-link{display:flex;align-items:center;gap:12px;height:48px;padding:0 16px;border-radius:var(--radius-nav);font-size:var(--font-base);color:var(--color-label);transition:background .15s;white-space:nowrap}
        .sidebar__nav-link:hover{background:var(--color-bg-app)}
        .sidebar__nav-link--active{background:var(--color-primary);color:#fff}
        .sidebar__nav-link--active:hover{opacity:.92}
        .sidebar__nav-icon{width:20px;height:20px;flex-shrink:0}
        .sidebar__footer{border-top:1px solid var(--color-border);padding:16px;display:flex;flex-direction:column;gap:4px;flex-shrink:0}
        .main{flex:1; min-width:0; display:flex; flex-direction:column;}
        .topbar{background:var(--color-white); border-bottom:1px solid var(--color-border); height:var(--topbar-height); padding:0 32px; display:flex; align-items:center; justify-content:space-between}
        .page{flex:1; padding:32px;}
        .card{background:var(--color-white); border:1px solid var(--color-border); border-radius:var(--radius-card); padding:24px; box-shadow:0 1px 2px rgba(16,24,40,.04)}
        .eyebrow{font-size:14px;color:var(--color-muted);margin-bottom:4px}
        .card h1{font-size:30px;line-height:1.1;margin-bottom:8px;color:var(--color-heading)}
        .card p{color:var(--color-body);line-height:1.5;max-width:760px}
        .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:18px}
        .tile{border:1px solid var(--color-border);border-radius:14px;padding:16px;background:#fff;min-height:104px;display:flex;flex-direction:column;justify-content:space-between}
        .tile span{font-size:13px;color:var(--color-muted);display:block;margin-bottom:4px}
        .tile strong{font-size:16px;color:var(--color-heading)}
        .links{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
        .primary,.secondary{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 14px;border-radius:10px;font-weight:700;font-size:14px;border:1px solid transparent;transition:all .15s ease}
        .primary{background:var(--color-primary);color:#fff}
        .primary:hover{background:var(--color-primary-dark);transform:translateY(-1px)}
        .secondary{background:#fff;color:var(--color-heading);border-color:var(--color-border)}
        .secondary:hover{border-color:var(--color-primary);color:var(--color-primary)}
        .recent-wrap{margin-top:20px}
        .recent-title{font-size:18px;font-weight:700;margin-bottom:10px;color:var(--color-heading)}
        .recent-empty{padding:12px;border:1px dashed var(--color-border);border-radius:10px;background:#fff;color:var(--color-muted)}
        .recent-list{display:flex;flex-direction:column;gap:10px}
        .report-card{background:#fff;border:1px solid var(--color-border);padding:14px;border-radius:12px;transition:transform .15s ease,box-shadow .15s ease}
        .report-card:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(16,24,40,.06)}
        .report-card__meta{display:flex;justify-content:space-between;gap:12px;align-items:center}
        .report-card__meta strong{font-size:15px;color:var(--color-heading)}
        .report-card__date{font-size:13px;color:var(--color-muted)}
        .report-card__sub{margin-top:6px;color:var(--color-body);font-size:14px}
        .report-card__body{margin-top:8px;color:var(--color-body);white-space:pre-wrap}
        @media (max-width: 960px){.grid{grid-template-columns:1fr}.page{padding:20px}.topbar{padding:0 20px}.card{padding:20px}}
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
        <a href="dashboard.php" class="sidebar__nav-link sidebar__nav-link--active" aria-current="page">
          <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M2.5 7.5L10 2.5L17.5 7.5V17.5H12.5V12.5H7.5V17.5H2.5V7.5Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Dashboard
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
        <a href="students.php" class="sidebar__nav-link">
          <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <circle cx="10" cy="6.5" r="3" stroke="#364153" stroke-width="1.5"/>
            <path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          Students
        </a>
      </nav>

      <div class="sidebar__footer">
        <a href="logout.php" class="sidebar__nav-link">
          <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M13 15l5-5-5-5M18 10H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M8 17.5H3.5a.5.5 0 0 1-.5-.5V3a.5.5 0 0 1 .5-.5H8" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          Sign Out
        </a>
      </div>
    </aside>
    <div class="main">
        <header class="topbar">
            <div></div>
            <div><a href="logout.php" class="logout-warning">Logout</a></div>
        </header>
        <main class="page">
    <section class="card">
      <div class="eyebrow">Supervisor Portal</div>
      <h1><?php echo htmlspecialchars($supervisor_name); ?></h1>
      <p><?php echo htmlspecialchars($office_name); ?> can use this portal for attendance, evaluation, and reports for assigned student assistants only.</p>
      <div class="grid">
        <div class="tile" id="attendanceTile">
          <div>
            <span>Attendance</span>
            <strong>Assigned SAs only</strong>
          </div>
          <div class="tile__metrics">
            <div class="metric-pill metric-pill--present">
              Present<br/><strong id="attendance-present">0</strong>
            </div>
            <div class="metric-pill metric-pill--late">
              Late<br/><strong id="attendance-late">0</strong>
            </div>
            <div class="metric-pill metric-pill--absent">
              Absent<br/><strong id="attendance-absent">0</strong>
            </div>
            <div class="meta-small ml-auto">Total: <strong id="attendance-total">0</strong></div>
          </div>
        </div>
        <div class="tile"><span>Evaluation</span><strong>Per term, per student</strong></div>
        <div class="tile"><span>Reports</span><strong>Office-specific history</strong></div>
      </div>
      <div class="links">
        <a class="primary" href="attendance.php">Open Attendance</a>
        <a class="secondary" href="evaluation.php">Open Evaluation</a>
        <a class="secondary" href="reports.php">Open Reports</a>
        <a class="secondary" href="students.php">Open Students</a>
        <a class="secondary" href="logout.php">Logout</a>
      </div>
    </section>
        </main>
    </div>
</div>
  <script>
    (function () {
      'use strict';
      var presentEl = document.getElementById('attendance-present');
      var lateEl = document.getElementById('attendance-late');
      var absentEl = document.getElementById('attendance-absent');
      var totalEl = document.getElementById('attendance-total');

      function applyMetrics(m) {
        if (!m) return;
        presentEl.textContent = m.present ?? 0;
        lateEl.textContent = m.late ?? 0;
        absentEl.textContent = m.absent ?? 0;
        totalEl.textContent = m.total ?? 0;
      }

      function fetchMetrics() {
        fetch('attendance_data.php', { credentials: 'same-origin' }).then(function (r) {
          if (!r.ok) throw new Error('Network');
          return r.json();
        }).then(function (data) {
          if (data && data.success) applyMetrics(data.metrics);
        }).catch(function (e) { console.warn('fetchMetrics failed', e); });
      }

      // Use polling on the dashboard to avoid opening a long-lived SSE connection
      fetchMetrics();
      setInterval(fetchMetrics, 5000);

      // initial load
      fetchMetrics();
    })();
  </script>
</body>
</html>
