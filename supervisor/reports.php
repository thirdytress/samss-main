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
$supervisorOffice = (string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? 'Assigned Office'));

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS student_reports (
        report_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NULL,
        duty_id INT NULL,
        student_code VARCHAR(128) NULL,
        reporter_id INT NOT NULL,
        title VARCHAR(255) NULL,
        notes TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'open',
        is_new TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Students scheduled / assigned to this office (applications with duty schedules)
$studentsStmt = $pdo->prepare(
    'SELECT DISTINCT a.application_id, s.student_id, s.student_id_number AS student_code, CONCAT(COALESCE(u.last_name, ""), ", ", COALESCE(u.first_name, "")) AS name
         FROM applications a
         JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         WHERE (
                            a.preferred_office = :office1
                        OR EXISTS (
                                SELECT 1
                                FROM duty_schedules ds
                                WHERE ds.application_id = a.application_id
                                        AND ds.office_name = :office2
                        )
             )
             AND EXISTS (
                        SELECT 1
                        FROM duty_schedules ds2
                        WHERE ds2.application_id = a.application_id
                                AND (ds2.office_name = :office3 OR a.preferred_office = :office4)
             )
         ORDER BY s.student_id_number ASC'
);
    $studentsStmt->execute([
        'office1' => $supervisorOffice,
        'office2' => $supervisorOffice,
        'office3' => $supervisorOffice,
        'office4' => $supervisorOffice,
    ]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Recent reports for this office
$reportsStmt = $pdo->prepare(
    'SELECT sr.*, CONCAT(COALESCE(u.first_name, ""), CASE WHEN u.first_name IS NOT NULL AND u.last_name IS NOT NULL THEN " " ELSE "" END, COALESCE(u.last_name, "")) AS reporter_name, a.preferred_office
     FROM student_reports sr
     LEFT JOIN users u ON u.user_id = sr.reporter_id
     LEFT JOIN applications a ON a.application_id = sr.application_id
     WHERE a.preferred_office = :office
     ORDER BY sr.created_at DESC
     LIMIT 10'
);
$reportsStmt->execute(['office' => $supervisorOffice]);
$reports = $reportsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reports — <?php echo htmlspecialchars($supervisorOffice); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <link rel="stylesheet" href="../assets/css/supervisor-notifications.css" />
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
        .topbar{background:var(--color-white);border-bottom:1px solid var(--color-border);height:var(--topbar-height);padding:0 32px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:sticky;top:0;z-index:50}
        .topbar__title{font-size:var(--font-lg);font-weight:700;color:var(--color-heading)}
        .topbar__sub{font-size:var(--font-sm);color:var(--color-body)}
        .dashboard{flex:1;padding:32px;display:flex;flex-direction:column;gap:20px}
        .card{background:var(--color-white);padding:18px;border-radius:var(--radius-card);border:1px solid var(--color-border);box-shadow:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.05)}
        .form-label{display:block;font-weight:600;margin-bottom:6px}
        .form-input,.form-textarea,.form-select{width:100%;padding:10px;border:1px solid #e6e9ee;border-radius:8px}
        .btn{background:#155dfc;color:#fff;padding:10px 14px;border-radius:8px;border:0;font-weight:600}
        .recent-list{display:flex;flex-direction:column;gap:12px}
        .report-item{border:1px solid #eef2f7;padding:12px;border-radius:12px;background:#fff;transition:transform .15s ease,box-shadow .15s ease}
        .report-item:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(16,24,40,.06)}
        .meta{color:#6b7280;font-size:13px}
        .report-title{font-size:24px;font-weight:700;color:var(--color-heading);margin-bottom:8px}
        /* Tabs */
        .tabs{display:flex;gap:8px;margin-bottom:12px}
        .tab{padding:8px 12px;border-radius:8px;background:#fff;border:1px solid var(--color-border);cursor:pointer;font-weight:700;color:var(--color-label)}
        .tab--active{background:var(--color-primary);color:#fff;border-color:var(--color-primary)}
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
                <a href="reports.php" class="sidebar__nav-link sidebar__nav-link--active" aria-current="page">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><rect x="2.5" y="2.5" width="15" height="15" rx="2" stroke="white" stroke-width="1.5"/><path d="M6 14V10M10 14V7M14 14V11" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Reports
                </a>
                <a href="students.php" class="sidebar__nav-link">
                    <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="6.5" r="3" stroke="#364153" stroke-width="1.5"/><path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/></svg>
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
        <div class="main">
            <header class="topbar">
                <div>
                    <div class="topbar__title">Reports</div>
                    <div class="topbar__sub"><?php echo htmlspecialchars($supervisorOffice); ?> office reports</div>
                </div>
                <div class="topbar__right">
                    <div class="topbar__notif-btn" role="button" aria-label="Notifications" tabindex="0">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/></svg>
                        <span class="topbar__notif-dot" aria-hidden="true" style="display:none"></span>
                    </div>
                    <div><a href="logout.php" class="logout-warning">Logout</a></div>
                </div>
            </header>
            <main class="dashboard">
                <div class="tabs" role="tablist" aria-label="Reports tabs">
                    <button id="tabForm" class="tab tab--active" role="tab" aria-selected="true">Student report</button>
                    <button id="tabHistory" class="tab" role="tab" aria-selected="false">History</button>
                </div>
                <section class="card">
                    <section id="formSection" class="card">
                    <div class="report-title">Report Student</div>
                    <div class="muted-desc">Use this form to submit a report for students assigned to your office. Only students with duty schedules in <?php echo htmlspecialchars($supervisorOffice); ?> are shown.</div>
                    <div>
                        <label class="form-label" for="title">Report Title</label>
                        <input id="title" class="form-input" placeholder="Short title (e.g., Late, Misconduct)" />
                    </div>
                    <div class="mt-8">
                        <label class="form-label" for="student">Student</label>
                        <select id="student" class="form-select">
                            <option value="">Select student...</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?php echo (int)$s['student_id']; ?>" data-student-code="<?php echo htmlspecialchars((string)$s['student_code']); ?>" data-application="<?php echo (int)$s['application_id']; ?>"><?php echo htmlspecialchars((string)($s['student_code'] . ' — ' . trim((string)$s['name']))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mt-8">
                        <label class="form-label" for="notes">Message</label>
                        <textarea id="notes" class="form-textarea" rows="5" placeholder="Describe the issue..."></textarea>
                    </div>
                    <div class="text-right mt-12">
                        <button id="submitReport" class="btn">Submit Report</button>
                    </section>

                <section id="historySection" class="hidden">
                    <div class="report-title mt-4">Recent Reports</div>
                    <div class="recent-list" id="recentList">
                        <?php foreach ($reports as $r): ?>
                            <a href="../admin/report_detail.php?report_id=<?php echo (int)($r['report_id'] ?? 0); ?>" class="no-decor">
                                <div class="report-item">
                                    <div class="flex-between">
                                        <strong><?php echo htmlspecialchars((string)($r['title'] ?? '-')); ?></strong>
                                        <div class="meta"><?php echo htmlspecialchars((string)$r['created_at']); ?></div>
                                    </div>
                                    <div class="meta mt-6">Student: <?php echo htmlspecialchars((string)($r['student_code'] ?? '-')); ?> — Reporter: <?php echo htmlspecialchars((string)($r['reporter_name'] ?? '')); ?></div>
                                    <div class="mt-8 pre-wrap muted-desc"><?php echo nl2br(htmlspecialchars((string)($r['notes'] ?? ''))); ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($reports)): ?><div class="card">No recent reports.</div><?php endif; ?>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <script>
        (function(){
            var btn = document.getElementById('submitReport');
            var title = document.getElementById('title');
            var notes = document.getElementById('notes');
            var student = document.getElementById('student');
            var recent = document.getElementById('recentList');
            var csrf = '<?php echo htmlspecialchars(sams_csrf_token(), ENT_QUOTES); ?>';

            function showTab(name){
                var f = document.getElementById('formSection');
                var h = document.getElementById('historySection');
                var tF = document.getElementById('tabForm');
                var tH = document.getElementById('tabHistory');
                if(name === 'history'){
                    f.style.display = 'none'; h.style.display = '';
                    tF.classList.remove('tab--active'); tH.classList.add('tab--active');
                    tF.setAttribute('aria-selected','false'); tH.setAttribute('aria-selected','true');
                } else {
                    f.style.display = ''; h.style.display = 'none';
                    tH.classList.remove('tab--active'); tF.classList.add('tab--active');
                    tH.setAttribute('aria-selected','false'); tF.setAttribute('aria-selected','true');
                }
            }

            document.getElementById('tabForm').addEventListener('click', function(){ showTab('form'); });
            document.getElementById('tabHistory').addEventListener('click', function(){ showTab('history'); });

            btn.addEventListener('click', function(){
                var t = title.value.trim();
                var n = notes.value.trim();
                var st = student.value || '';
                var appOpt = student.options[student.selectedIndex];
                var app = appOpt ? appOpt.getAttribute('data-application') : '';
                var studentCode = appOpt ? appOpt.getAttribute('data-student-code') : '';
                if (!t) return alert('Please enter a short title');
                if (!st) return alert('Please select a student');
                if (!n) return alert('Please enter a message');

                fetch('./report_student.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ title: t, notes: n, student_code: st, student_display_code: studentCode, application_id: app })
                }).then(function(r){ return r.json(); }).then(function(d){
                    if (d && d.success) {
                        alert('Report submitted');
                        // switch to history tab after successful submit
                        showTab('history');
                        // prepend to recent list
                        var node = document.createElement('div'); node.className='report-item';
                        node.innerHTML = '<div class="flex-between"><strong>'+escapeHtml(t)+'</strong><div class="meta">just now</div></div><div class="meta mt-6">Student: '+escapeHtml(studentCode || st)+'</div><div class="mt-8 pre-wrap">'+escapeHtml(n)+'</div>';
                        recent.insertBefore(node, recent.firstChild);
                        title.value=''; notes.value=''; student.selectedIndex=0;
                    } else {
                        alert('Failed: ' + (d && d.message ? d.message : 'Unknown'));
                    }
                }).catch(function(e){ console.error(e); alert('Request failed'); });
            });

            function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; }); }
        })();
    </script>
        <script src="../assets/js/admin-notifications.js"></script>
</body>
</html>
