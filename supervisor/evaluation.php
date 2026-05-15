<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'supervisor') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$flashMessage = '';
$flashError = '';

$supervisorStatement = $pdo->prepare(
    'SELECT s.supervisor_id, s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorId = (int) ($supervisorRow['supervisor_id'] ?? 0);
$officeName = (string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? 'Assigned Office'));

$termStatement = $pdo->query(
    'SELECT term_id, term_name, term_year
     FROM terms
     WHERE is_active = 1
     ORDER BY term_id DESC
     LIMIT 1'
);
$activeTerm = $termStatement->fetch(PDO::FETCH_ASSOC) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $termId = (int) ($_POST['term_id'] ?? 0);
    $performanceRating = (int) ($_POST['performance_rating'] ?? 0);
    $reliabilityRating = (int) ($_POST['reliability_rating'] ?? 0);
    $professionalismRating = (int) ($_POST['professionalism_rating'] ?? 0);
    $comments = trim((string) ($_POST['comments'] ?? ''));

    if ($supervisorId <= 0 || $applicationId <= 0 || $termId <= 0) {
        $flashError = 'Invalid evaluation request.';
    } elseif ($performanceRating < 1 || $performanceRating > 5 || $reliabilityRating < 1 || $reliabilityRating > 5 || $professionalismRating < 1 || $professionalismRating > 5) {
        $flashError = 'All ratings must be between 1 and 5.';
    } else {
        $applicationStatement = $pdo->prepare(
            'SELECT a.application_id
             FROM applications a
             WHERE a.application_id = :application_id
               AND a.term_id = :term_id
               AND a.preferred_office = :office_name
               AND a.status = "approved"
             LIMIT 1'
        );
        $applicationStatement->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
            'office_name' => $officeName,
        ]);

        if (!$applicationStatement->fetchColumn()) {
            $flashError = 'The selected student is not available for this office or term.';
        } else {
            try {
              // Ensure evaluations table exists (safe to run repeatedly)
              $pdo->exec(
                'CREATE TABLE IF NOT EXISTS evaluations (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  application_id INT NOT NULL,
                  term_id INT NOT NULL,
                  supervisor_id INT NOT NULL,
                  performance_rating TINYINT NOT NULL,
                  reliability_rating TINYINT NOT NULL,
                  professionalism_rating TINYINT NOT NULL,
                  comments TEXT NULL,
                  submitted_at DATETIME NOT NULL,
                  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (application_id),
                  INDEX (supervisor_id),
                  INDEX (term_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
              );

                $insertStatement = $pdo->prepare(
                    'INSERT INTO evaluations (
                        application_id,
                        term_id,
                        supervisor_id,
                        performance_rating,
                        reliability_rating,
                        professionalism_rating,
                        comments,
                        submitted_at
                    ) VALUES (
                        :application_id,
                        :term_id,
                        :supervisor_id,
                        :performance_rating,
                        :reliability_rating,
                        :professionalism_rating,
                        :comments,
                        NOW()
                    )'
                );
                $insertStatement->execute([
                    'application_id' => $applicationId,
                    'term_id' => $termId,
                    'supervisor_id' => $supervisorId,
                    'performance_rating' => $performanceRating,
                    'reliability_rating' => $reliabilityRating,
                    'professionalism_rating' => $professionalismRating,
                    'comments' => $comments,
                ]);

                $flashMessage = 'Evaluation submitted successfully.';
            } catch (Throwable $exception) {
                $flashError = 'Unable to submit evaluation: ' . $exception->getMessage();
            }
        }
    }
}

$eligibleStudents = [];
if ($activeTerm && $officeName !== '') {
    $studentStatement = $pdo->prepare(
        'SELECT
            a.application_id,
            a.preferred_office,
            a.term_id,
            u.first_name,
            u.last_name,
            s.student_id_number,
            s.program
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE a.term_id = :term_id
           AND a.preferred_office = :office_name
           AND a.status = "approved"
         ORDER BY u.last_name, u.first_name'
    );
    $studentStatement->execute([
        'term_id' => (int) $activeTerm['term_id'],
        'office_name' => $officeName,
    ]);
    $eligibleStudents = $studentStatement->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Supervisor Evaluation – SAMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
  <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
  <link rel="stylesheet" href="../assets/css/supervisor-notifications.css" />
  <style>
    /* Reuse admin design system for consistent shell */
      *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
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
    .topbar{background:var(--color-white); border-bottom:1px solid var(--color-border); height:var(--topbar-height); padding:0 32px; display:flex; align-items:center; justify-content:space-between; gap:16px}
    .topbar__heading{display:flex;flex-direction:column;gap:2px}
    .topbar__title{font-size:18px;font-weight:700;color:var(--color-heading)}
    .topbar__subtitle{font-size:14px;color:var(--color-body)}
    .logout-btn{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 14px;border-radius:10px;background:#fee2e2;color:#991b1b;font-weight:700;text-decoration:none}
    .logout-btn:hover{background:#fecaca}
    .page{flex:1; padding:32px;}
    .card{background:var(--color-white); border:1px solid var(--color-border); border-radius:var(--radius-card); padding:24px; box-shadow:0 1px 2px rgba(16,24,40,.04)}
    .eyebrow{font-size:14px;color:var(--color-muted);margin-bottom:4px}
    .card h1{font-size:30px;line-height:1.1;margin-bottom:8px;color:var(--color-heading)}
    .card p{color:var(--color-body);line-height:1.5;max-width:760px}
    .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:18px}
    .tile{border:1px solid var(--color-border);border-radius:14px;padding:16px;background:#fff;min-height:104px;display:flex;flex-direction:column;justify-content:space-between}
    .tile span{font-size:13px;color:var(--color-muted);display:block;margin-bottom:4px}
    .tile strong{font-size:16px;color:var(--color-heading);display:block}
    .form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:18px}
    .field{display:flex;flex-direction:column;gap:6px}
    .field label{font-weight:600;color:var(--color-label);font-size:14px}
    .field select,.field textarea{width:100%;padding:10px 12px;border:1px solid var(--color-border);border-radius:10px;background:#fff;font:inherit;color:var(--color-heading)}
    .field textarea{min-height:110px;resize:vertical}
    .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    .btn{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 14px;border-radius:10px;background:var(--color-primary);color:#fff;border:0;cursor:pointer;font-weight:700;text-decoration:none}
    .btn--primary{background:var(--gradient-brand);color:#fff}
    .btn--secondary{background:#fff;color:var(--color-heading);border:1px solid var(--color-border)}
    .btn--secondary:hover{border-color:var(--color-primary);color:var(--color-primary)}
    .flash{padding:12px 14px;border-radius:12px;margin-top:14px;border:1px solid var(--color-border);background:#fff}
    .flash--success{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
    .flash--error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .student-list{display:flex;flex-direction:column;gap:10px;margin-top:18px}
    .student-item{display:flex;justify-content:space-between;gap:12px;padding:14px 16px;border:1px solid var(--color-border);border-radius:12px;background:#fff;align-items:center}
    .student-item__name{font-weight:700;color:var(--color-heading)}
    .student-item__meta{font-size:13px;color:var(--color-body)}
    @media (max-width: 960px){.grid,.form-grid{grid-template-columns:1fr}.page{padding:20px}.topbar{padding:0 20px}.card{padding:20px}}
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
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
          <path d="M2.5 7.5L10 2.5L17.5 7.5V17.5H12.5V12.5H7.5V17.5H2.5V7.5Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Dashboard
      </a>
      <a href="attendance.php" class="sidebar__nav-link">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
          <path d="M17 5L8 14.5L3.5 10" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Attendance
      </a>
      <a href="evaluation.php" class="sidebar__nav-link sidebar__nav-link--active" aria-current="page">
        <svg class="sidebar__nav-icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
          <path d="M10 2l2 5.5H17l-4 3 1.5 5.5L10 13l-4.5 3L7 11 3 8h5L10 2Z" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
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
      <div class="topbar__heading">
        <div class="topbar__title">Supervisor Evaluation</div>
        <div class="topbar__subtitle">Submit end-of-term evaluations</div>
      </div>
      <div class="topbar__right">
        <div class="topbar__notif" aria-label="Notifications">
          <svg class="topbar__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#364153"/></svg>
          <span class="topbar__notif-dot" aria-label="New notifications" style="display:none"></span>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
      </div>
    </header>
    <main class="page">
    <section class="card">
      <div class="eyebrow">Supervisor Evaluation</div>
      <h1><?php echo htmlspecialchars((string) ($user['name'] ?? 'Supervisor'), ENT_QUOTES, 'UTF-8'); ?></h1>
      <p>Submit per-term performance feedback for approved student assistants in <?php echo htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8'); ?>.</p>

      <?php if ($flashMessage !== ''): ?><div class="flash flash--success"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($flashError !== ''): ?><div class="flash flash--error"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <div class="grid">
        <div class="tile"><span>Active Term</span><strong><?php echo $activeTerm ? htmlspecialchars((string) $activeTerm['term_name'] . ' ' . (string) $activeTerm['term_year'], ENT_QUOTES, 'UTF-8') : 'No active term'; ?></strong></div>
        <div class="tile"><span>Office</span><strong><?php echo htmlspecialchars($officeName, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div class="tile"><span>Eligible Students</span><strong><?php echo (int) count($eligibleStudents); ?></strong></div>
      </div>

      <form method="post">
        <div class="form-grid">
          <div class="field">
            <label for="term_id">Term</label>
            <select id="term_id" name="term_id" required>
              <option value=""><?php echo $activeTerm ? htmlspecialchars((string) $activeTerm['term_name'] . ' ' . (string) $activeTerm['term_year'], ENT_QUOTES, 'UTF-8') : 'No active term'; ?></option>
              <?php if ($activeTerm): ?>
                <option value="<?php echo (int) $activeTerm['term_id']; ?>" selected><?php echo htmlspecialchars((string) $activeTerm['term_name'] . ' ' . (string) $activeTerm['term_year'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endif; ?>
            </select>
          </div>
          <div class="field">
            <label for="application_id">Student</label>
            <select id="application_id" name="application_id" required>
              <option value="">Select student</option>
              <?php foreach ($eligibleStudents as $student): ?>
                <option value="<?php echo (int) $student['application_id']; ?>">
                  <?php echo htmlspecialchars(trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? '')) . ' (' . (string) ($student['student_id_number'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="performance_rating">Performance</label>
            <select id="performance_rating" name="performance_rating" required>
              <option value="5">5 - Excellent</option>
              <option value="4">4 - Very Good</option>
              <option value="3" selected>3 - Satisfactory</option>
              <option value="2">2 - Needs Improvement</option>
              <option value="1">1 - Poor</option>
            </select>
          </div>
          <div class="field">
            <label for="reliability_rating">Reliability</label>
            <select id="reliability_rating" name="reliability_rating" required>
              <option value="5">5 - Excellent</option>
              <option value="4">4 - Very Good</option>
              <option value="3" selected>3 - Satisfactory</option>
              <option value="2">2 - Needs Improvement</option>
              <option value="1">1 - Poor</option>
            </select>
          </div>
          <div class="field">
            <label for="professionalism_rating">Professionalism</label>
            <select id="professionalism_rating" name="professionalism_rating" required>
              <option value="5">5 - Excellent</option>
              <option value="4">4 - Very Good</option>
              <option value="3" selected>3 - Satisfactory</option>
              <option value="2">2 - Needs Improvement</option>
              <option value="1">1 - Poor</option>
            </select>
          </div>
        </div>

        <div class="field">
          <label for="comments">Supervisor Comments</label>
          <textarea id="comments" name="comments" placeholder="Add notes for admin review and end-of-term evaluation"></textarea>
        </div>

        <div class="actions">
          <button class="btn btn--primary" type="submit">Submit Evaluation</button>
          <a class="btn btn--secondary" href="dashboard.php">Back to Dashboard</a>
        </div>
      </form>

      <div class="student-list">
        <?php foreach ($eligibleStudents as $student): ?>
          <div class="student-item">
            <div>
              <div class="student-item__name"><?php echo htmlspecialchars(trim((string) ($student['first_name'] ?? '') . ' ' . (string) ($student['last_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="student-item__meta"><?php echo htmlspecialchars((string) ($student['student_id_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars((string) ($student['preferred_office'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="student-item__meta">Ready for evaluation</div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
        </main>
    </div>
</div>
    <script src="../assets/js/admin-notifications.js"></script>
</body>
</html>