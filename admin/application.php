<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mail.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
  header('Location: ../login.php');
  exit;
}

$flashMessage = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $applicationId = (int) ($_POST['application_id'] ?? 0);
  $reviewAction = trim((string) ($_POST['review_action'] ?? ''));

  if ($applicationId <= 0 || !in_array($reviewAction, ['approve', 'reject'], true)) {
    $flashError = 'Invalid review request.';
  } else {
    try {
      $pdo = sams_pdo();

      $applicationStatement = $pdo->prepare(
        'SELECT
          a.application_id AS application_id,
          a.status,
          u.email,
          u.first_name,
          u.last_name
        FROM applications a
        INNER JOIN students s ON s.student_id = a.student_id
        INNER JOIN users u ON u.user_id = s.user_id
        WHERE a.application_id = :application_id
        LIMIT 1'
      );
      $applicationStatement->execute(['application_id' => $applicationId]);
      $application = $applicationStatement->fetch();

      if (!$application) {
        throw new RuntimeException('Application not found.');
      }

      $newStatus = $reviewAction === 'approve' ? 'approved' : 'rejected';

      $updateStatement = $pdo->prepare(
        'UPDATE applications
         SET status = :status,
             reviewed_by = :reviewed_by,
             reviewed_at = NOW()
         WHERE application_id = :application_id'
      );
      $updateStatement->execute([
        'status' => $newStatus,
        'reviewed_by' => (int) ($currentUser['user_id'] ?? 0),
        'application_id' => $applicationId,
      ]);

      if ($reviewAction === 'approve') {
        $mustChangeCol = sams_first_existing_column($pdo, 'users', ['must_change_password']);

        if ($mustChangeCol !== null) {
          $activateStatement = $pdo->prepare(
            'UPDATE users u
             INNER JOIN students s ON s.user_id = u.user_id
             INNER JOIN applications a ON a.student_id = s.student_id
             SET u.is_active = 1, u.' . $mustChangeCol . ' = 1
             WHERE a.application_id = :application_id'
          );
        } else {
          $activateStatement = $pdo->prepare(
            'UPDATE users u
             INNER JOIN students s ON s.user_id = u.user_id
             INNER JOIN applications a ON a.student_id = s.student_id
             SET u.is_active = 1
             WHERE a.application_id = :application_id'
          );
        }

        $activateStatement->execute(['application_id' => $applicationId]);
      }

      try {
        sams_send_application_review_email(
          (string) ($application['email'] ?? ''),
          trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? '')),
          $newStatus
        );
      } catch (Throwable $mailException) {
        $flashError = 'Application was updated, but the email notification could not be sent: ' . $mailException->getMessage();
      }

      $flashMessage = $reviewAction === 'approve'
        ? 'Application approved successfully.'
        : 'Application rejected successfully.';
    } catch (Throwable $exception) {
      $flashError = $exception->getMessage();
    }
  }

  if ($flashMessage !== '') {
    $_SESSION['sams_app_flash'] = $flashMessage;
  }
  if ($flashError !== '') {
    $_SESSION['sams_app_error'] = $flashError;
  }

  header('Location: application.php');
  exit;
}

function sams_application_status_label(string $status): string
{
  return match ($status) {
    'pending' => 'Pending',
    'under_review' => 'Under Review',
    'interview' => 'Interview',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    default => ucfirst(str_replace('_', ' ', $status)),
  };
}

function sams_application_status_class(string $status): string
{
  return match ($status) {
    'pending' => 'badge--pending',
    'under_review' => 'badge--interview',
    'interview' => 'badge--interview',
    'approved' => 'badge--approved',
    'rejected' => 'badge--rejected',
    default => 'badge--pending',
  };
}

function sams_application_avatar(string $name): string
{
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  $initials = '';

  foreach ($parts as $part) {
    if ($part === '') {
      continue;
    }

    $initials .= strtoupper(substr($part, 0, 1));

    if (strlen($initials) >= 2) {
      break;
    }
  }

  return $initials !== '' ? substr($initials, 0, 2) : 'SA';
}

function sams_application_skill_tags(?string $skills): array
{
  if ($skills === null || trim($skills) === '') {
    return [];
  }

  $items = preg_split('/[\r\n,;]+/', $skills) ?: [];

  return array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));
}

$pdo = sams_pdo();

$applicationCounts = [
  'all' => 0,
  'pending' => 0,
  'under_review' => 0,
  'interview' => 0,
  'approved' => 0,
  'rejected' => 0,
];

foreach ($applicationCounts as $status => $count) {
  if ($status === 'all') {
    continue;
  }

  $statement = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE status = :status');
  $statement->execute(['status' => $status]);
  $applicationCounts[$status] = (int) $statement->fetchColumn();
  $applicationCounts['all'] += $applicationCounts[$status];
}

$applicationsStatement = $pdo->query(
  'SELECT
    a.application_id AS application_id,
    a.status,
    a.preferred_office,
    a.skills,
    a.submitted_at,
    a.reviewed_at,
    a.reviewed_by,
    u.first_name,
    u.last_name,
    u.email,
    s.student_id AS student_id_number,
    s.program,
    s.year_level,
    t.term_name,
    t.term_year AS school_year
  FROM applications a
  INNER JOIN students s ON s.student_id = a.student_id
  INNER JOIN users u ON u.user_id = s.user_id
  INNER JOIN terms t ON t.term_id = a.term_id
  ORDER BY a.submitted_at DESC, a.application_id DESC'
);

$applications = $applicationsStatement->fetchAll();

if ($flashMessage === '' && isset($_SESSION['sams_app_flash'])) {
  $flashMessage = (string) $_SESSION['sams_app_flash'];
  unset($_SESSION['sams_app_flash']);
}

if ($flashError === '' && isset($_SESSION['sams_app_error'])) {
  $flashError = (string) $_SESSION['sams_app_error'];
  unset($_SESSION['sams_app_error']);
}

$statusFilterOptions = [
  'all' => 'All',
  'pending' => 'Pending',
  'under_review' => 'Under Review',
  'interview' => 'Interview',
  'approved' => 'Approved',
  'rejected' => 'Rejected',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Application Management – SA System</title>
  <meta name="description" content="Admin Application Management for NU Lipa Student Assistant System." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet" />
  <style>
    /* =============================================
       CSS VARIABLES / DESIGN TOKENS
    ============================================= */
    :root {
      --color-blue:           #155dfc;
      --color-blue-dark:      #1447e6;
      --color-purple:         #9810fa;
      --color-purple-dark:    #8200db;
      --color-dark:           #101828;
      --color-body:           #364153;
      --color-muted:          #4a5565;
      --color-muted-light:    #99a1af;
      --color-white:          #ffffff;
      --color-bg:             #f9fafb;
      --color-border:         #e5e7eb;
      --color-input-border:   #d1d5dc;
      --color-tag-bg:         #f3f4f6;
      --color-red-dot:        #fb2c36;

      /* Status badge colours */
      --color-pending-bg:     #fef9c2;
      --color-pending-text:   #a65f00;
      --color-interview-bg:   #dbeafe;
      --color-interview-text: #1447e6;
      --color-approved-bg:    #dcfce7;
      --color-approved-text:  #008236;
      --color-rejected-bg:    #fee2e2;
      --color-rejected-text:  #b91c1c;

      --grad-brand:    linear-gradient(135deg, #155dfc 0%, #9810fa 100%);
      --grad-blue:     linear-gradient(158deg, #155dfc 0%, #1447e6 100%);
      --grad-purple:   linear-gradient(158deg, #9810fa 0%, #8200db 100%);

      --shadow-card:   0 1px 3px 0 rgba(0,0,0,.10), 0 1px 2px 0 rgba(0,0,0,.06);

      --sidebar-w:     256px;
      --topbar-h:      89px;

      --radius-sm:     4px;
      --radius-md:     10px;
      --radius-lg:     16px;
      --radius-pill:   9999px;

      --font-xs:   12px;
      --font-sm:   14px;
      --font-base: 16px;
      --font-lg:   18px;
      --font-xl:   24px;

      --space-1:   4px;
      --space-2:   8px;
      --space-3:   12px;
      --space-4:   16px;
      --space-5:   20px;
      --space-6:   24px;
      --space-8:   32px;
    }

    /* =============================================
       RESET & BASE
    ============================================= */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: 'Inter', Arial, sans-serif;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--color-bg);
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    img { display: block; }
    button, input { font-family: inherit; }

    /* =============================================
       APP SHELL
    ============================================= */
    .app {
      display: flex;
      height: 100vh;
      overflow: hidden;
    }

    /* =============================================
       SIDEBAR
    ============================================= */
    .sidebar {
      width: var(--sidebar-w);
      flex-shrink: 0;
      background: var(--color-white);
      border-right: 1px solid var(--color-border);
      display: flex;
      flex-direction: column;
      min-height: 100vh;
      height: 100vh;
      overflow-y: auto;
      position: sticky;
      top: 0;
    }

    /* Sidebar brand */
    .sidebar__brand {
      height: var(--topbar-h);
      border-bottom: 1px solid var(--color-border);
      padding: var(--space-6) var(--space-6) 0;
      display: flex;
      align-items: center;
      gap: var(--space-3);
      flex-shrink: 0;
    }
    .sidebar__logo {
      width: 40px;
      height: 40px;
      border-radius: var(--radius-md);
      background: var(--grad-brand);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--font-lg);
      font-weight: 700;
      color: var(--color-white);
      flex-shrink: 0;
    }
    .sidebar__brand-name {
      font-size: var(--font-base);
      font-weight: 700;
      color: var(--color-dark);
    }
    .sidebar__brand-sub {
      font-size: var(--font-xs);
      color: var(--color-muted);
    }

    /* Nav */
    .sidebar__nav {
      flex: 1;
      overflow-y: auto;
      padding: var(--space-4) var(--space-4) 0;
      display: flex;
      flex-direction: column;
      gap: var(--space-1);
    }
    .nav-item {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      height: 48px;
      padding: 0 var(--space-4);
      border-radius: var(--radius-md);
      font-size: var(--font-base);
      color: var(--color-body);
      cursor: pointer;
      transition: background .15s;
    }
    .nav-item:hover { background: var(--color-bg); }
    .nav-item--active {
      background: var(--color-blue);
      color: var(--color-white);
    }
    .nav-item--active:hover { background: var(--color-blue); }
    .nav-item__icon { width: 20px; height: 20px; flex-shrink: 0; }
    .nav-item__label { flex: 1; }
    .nav-item__badge {
      background: var(--color-white);
      color: var(--color-blue);
      font-size: var(--font-xs);
      font-weight: 700;
      padding: 2px var(--space-2);
      border-radius: var(--radius-pill);
      min-width: 20px;
      text-align: center;
    }

    /* Sidebar footer */
    .sidebar__footer {
      border-top: 1px solid var(--color-border);
      padding: 17px var(--space-4) var(--space-4);
      display: flex;
      flex-direction: column;
      gap: var(--space-1);
      flex-shrink: 0;
      margin-top: auto;
    }

    /* Mobile sidebar toggle */
    .sidebar-toggle {
      display: none;
      position: fixed;
      top: 16px;
      left: 16px;
      z-index: 200;
      width: 36px;
      height: 36px;
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-md);
      cursor: pointer;
      align-items: center;
      justify-content: center;
      flex-direction: column;
      gap: 4px;
    }
    .sidebar-toggle__bar {
      display: block;
      width: 18px;
      height: 2px;
      background: var(--color-dark);
      border-radius: 2px;
      transition: transform .3s, opacity .3s;
    }

    /* =============================================
       MAIN AREA
    ============================================= */
    .main {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    /* Top bar */
    .topbar {
      height: var(--topbar-h);
      flex-shrink: 0;
      background: var(--color-white);
      border-bottom: 1px solid var(--color-border);
      padding: 0 var(--space-8);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .topbar__title {
      font-size: var(--font-xl);
      font-weight: 700;
      color: var(--color-dark);
      line-height: 1.33;
    }
    .topbar__sub {
      font-size: var(--font-sm);
      color: var(--color-muted);
    }
    .topbar__user {
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }
    .topbar__notif {
      position: relative;
      width: 36px;
      height: 36px;
      border-radius: var(--radius-pill);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }
    .topbar__notif-icon { width: 20px; height: 20px; }
    .topbar__notif-dot {
      position: absolute;
      top: 4px;
      right: 0;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--color-red-dot);
    }
    .topbar__user-info { text-align: right; }
    .topbar__user-name {
      font-size: var(--font-sm);
      color: var(--color-dark);
    }
    .topbar__user-role {
      font-size: var(--font-xs);
      color: var(--color-muted);
    }
    .topbar__avatar {
      width: 40px;
      height: 40px;
      border-radius: var(--radius-pill);
      background: var(--grad-brand);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .topbar__avatar img { width: 20px; height: 20px; }

    /* =============================================
       PAGE CONTENT
    ============================================= */
    .content {
      flex: 1;
      overflow-y: auto;
      padding: var(--space-8);
      display: flex;
      flex-direction: column;
      gap: var(--space-6);
    }

    /* =============================================
       TOOLBAR (search + filters + export)
    ============================================= */
    .toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: var(--space-4);
      flex-wrap: wrap;
    }
    .toolbar__left {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      flex-wrap: wrap;
    }
    .toolbar__search {
      position: relative;
    }
    .toolbar__search-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      width: 20px;
      height: 20px;
      pointer-events: none;
    }
    .toolbar__search input {
      width: 320px;
      height: 42px;
      border: 1px solid var(--color-input-border);
      border-radius: var(--radius-md);
      padding: var(--space-2) var(--space-4) var(--space-2) 40px;
      font-size: var(--font-base);
      color: var(--color-dark);
      background: var(--color-white);
      outline: none;
      transition: border-color .2s;
    }
    .toolbar__search input::placeholder { color: rgba(10,10,10,.5); }
    .toolbar__search input:focus { border-color: var(--color-blue); }

    /* Filter buttons */
    .filter-btn {
      height: 40px;
      border-radius: var(--radius-md);
      border: none;
      cursor: pointer;
      font-size: var(--font-base);
      padding: 0 16px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      transition: opacity .15s;
    }
    .filter-btn--active {
      background: var(--color-blue);
      color: var(--color-white);
    }
    .filter-btn--inactive {
      background: var(--color-tag-bg);
      color: var(--color-body);
    }
    .filter-btn__count { opacity: .6; }
    .filter-btn--active .filter-btn__count { opacity: .8; }

    /* Export button */
    .btn-export {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      height: 40px;
      padding: 0 var(--space-4);
      border-radius: var(--radius-md);
      border: none;
      background: var(--color-blue);
      color: var(--color-white);
      font-size: var(--font-base);
      cursor: pointer;
      white-space: nowrap;
      transition: opacity .15s;
      text-decoration: none;
    }
    .btn-export:hover { opacity: .88; }
    .btn-export__icon { width: 16px; height: 16px; }

    /* =============================================
       TABLE CARD
    ============================================= */
    .table-card {
      background: var(--color-white);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
    .table-wrap {
      overflow-x: auto;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      max-height: min(72vh, 860px);
      min-height: 0;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 900px;
    }
    thead {
      background: var(--color-bg);
      border-bottom: 1px solid var(--color-border);
    }
    th {
      padding: 16px var(--space-6);
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-dark);
      text-align: left;
      white-space: nowrap;
    }
    tbody tr {
      border-bottom: 1px solid var(--color-border);
    }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #fafafa; }
    td {
      padding: 0 var(--space-6);
      height: 77px;
      vertical-align: middle;
      font-size: var(--font-base);
      color: var(--color-dark);
    }

    /* Applicant cell */
    .applicant {
      display: flex;
      align-items: center;
      gap: var(--space-3);
    }
    .applicant__avatar {
      width: 40px;
      height: 40px;
      border-radius: var(--radius-pill);
      background: var(--grad-brand);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-white);
      flex-shrink: 0;
    }
    .applicant__name {
      font-weight: 700;
      color: var(--color-dark);
      line-height: 1.5;
    }
    .applicant__date {
      font-size: var(--font-sm);
      color: var(--color-muted);
    }

    /* Skills tags */
    .skills {
      display: flex;
      gap: var(--space-1);
      flex-wrap: wrap;
    }
    .skill-tag {
      background: var(--color-tag-bg);
      color: var(--color-body);
      font-size: var(--font-xs);
      padding: var(--space-1) var(--space-2);
      border-radius: var(--radius-sm);
      white-space: nowrap;
    }

    /* Status badges */
    .badge {
      display: inline-block;
      font-size: var(--font-xs);
      padding: var(--space-1) var(--space-3);
      border-radius: var(--radius-pill);
      white-space: nowrap;
    }
    .badge--pending   { background: var(--color-pending-bg);   color: var(--color-pending-text);   }
    .badge--interview { background: var(--color-interview-bg); color: var(--color-interview-text); }
    .badge--approved  { background: var(--color-approved-bg);  color: var(--color-approved-text);  }
    .badge--rejected  { background: var(--color-rejected-bg);  color: var(--color-rejected-text);  }

    /* Action buttons */
    .actions {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }
    .action-btn {
      width: 32px;
      height: 32px;
      border-radius: var(--radius-md);
      border: none;
      background: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      transition: background .15s;
      flex-shrink: 0;
    }
    .action-btn:hover { background: var(--color-tag-bg); }
    .action-btn svg { width: 16px; height: 16px; }
    .action-btn--view  svg { color: var(--color-muted); }
    .action-btn--approve svg { color: #008236; }
    .action-btn--reject  svg { color: #b91c1c; }
    .action-btn--msg   svg { color: var(--color-blue); }
    .action-form {
      display: inline-flex;
      margin: 0;
    }

    /* =============================================
       MODAL
    ============================================= */
    .modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(15, 23, 42, 0.55);
      z-index: 500;
    }
    .modal.is-open {
      display: flex;
    }
    .modal__panel {
      width: min(760px, 100%);
      max-height: 85vh;
      overflow-y: auto;
      background: var(--color-white);
      border-radius: 20px;
      box-shadow: 0 24px 64px rgba(15, 23, 42, 0.28);
      border: 1px solid var(--color-border);
    }
    .modal__header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      padding: 24px 24px 16px;
      border-bottom: 1px solid var(--color-border);
    }
    .modal__title {
      margin: 0;
      font-size: 24px;
      font-weight: 700;
      color: var(--color-dark);
    }
    .modal__subtitle {
      margin-top: 4px;
      font-size: 14px;
      color: var(--color-muted);
    }
    .modal__close {
      width: 36px;
      height: 36px;
      border-radius: 9999px;
      border: 1px solid var(--color-border);
      background: var(--color-white);
      cursor: pointer;
      font-size: 20px;
      line-height: 1;
      color: var(--color-muted);
    }
    .modal__body {
      padding: 24px;
    }
    .detail-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
      width: 100%;
    }
    .detail-item {
      background: #f8fafc;
      border: 1px solid var(--color-border);
      border-radius: 14px;
      padding: 14px 16px;
      word-break: break-word;
    }
    .detail-item[style*="grid-column"] {
      grid-column: 1 / -1;
    }
    .detail-item__label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: var(--color-muted);
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .detail-item__value {
      font-size: 15px;
      color: var(--color-dark);
      line-height: 1.45;
      word-break: break-word;
    }
    .modal__skills {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .modal__footer {
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      flex-wrap: wrap;
      padding: 0 24px 24px;
    }
    .modal__action {
      display: inline-flex;
      margin: 0;
    }
    .modal__button {
      border: 0;
      border-radius: 12px;
      padding: 12px 16px;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .modal__button--approve {
      background: #dcfce7;
      color: #166534;
    }
    .modal__button--reject {
      background: #fee2e2;
      color: #991b1b;
    }
    .modal__button--ghost {
      background: #f3f4f6;
      color: var(--color-body);
    }

    /* =============================================
       PAGE ALERTS
    ============================================= */
    .page-alert {
      margin-bottom: 16px;
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 600;
    }
    .page-alert--success {
      background: #f0fdf4;
      color: #166534;
      border: 1px solid #bbf7d0;
    }
    .page-alert--error {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }

    /* =============================================
       BOTTOM INFO CARDS (2-col grid)
    ============================================= */
    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-6);
    }

    /* Auto-Filtering card */
    .card-auto {
      background: var(--grad-blue);
      border-radius: var(--radius-lg);
      padding: var(--space-6);
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .card-auto__title {
      font-size: var(--font-lg);
      font-weight: 700;
      color: var(--color-white);
    }
    .card-auto__desc {
      font-size: var(--font-sm);
      color: #dbeafe;
    }
    .card-auto__stats {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: var(--space-4);
      margin-top: var(--space-2);
    }
    .stat-box {
      background: rgba(255,255,255,.10);
      border-radius: var(--radius-md);
      padding: var(--space-3);
    }
    .stat-box__num {
      font-size: var(--font-xl);
      font-weight: 700;
      color: var(--color-white);
      line-height: 1.33;
    }
    .stat-box__label {
      font-size: var(--font-sm);
      color: #dbeafe;
    }

    /* AI Recommendations card */
    .card-ai {
      background: var(--grad-purple);
      border-radius: var(--radius-lg);
      padding: var(--space-6);
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
    }
    .card-ai__title {
      font-size: var(--font-lg);
      font-weight: 700;
      color: var(--color-white);
    }
    .card-ai__desc {
      font-size: var(--font-sm);
      color: #f3e8ff;
    }
    .card-ai__list {
      display: flex;
      flex-direction: column;
      gap: var(--space-2);
      margin-top: var(--space-2);
    }
    .rec-item {
      background: rgba(255,255,255,.10);
      border-radius: var(--radius-md);
      padding: var(--space-3);
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 68px;
    }
    .rec-item__name {
      font-size: var(--font-sm);
      font-weight: 700;
      color: var(--color-white);
    }
    .rec-item__office {
      font-size: var(--font-xs);
      color: #f3e8ff;
    }
    .rec-item__match {
      text-align: right;
    }
    .rec-item__pct {
      font-size: var(--font-lg);
      font-weight: 700;
      color: var(--color-white);
    }
    .rec-item__label {
      font-size: var(--font-xs);
      color: #f3e8ff;
    }

    /* =============================================
       IMAGE LIGHTBOX  ← FIXED: moved OUT of media query
    ============================================= */
    .image-lightbox {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(0, 0, 0, 0.75);
      z-index: 600;
      padding: 20px;
    }
    .image-lightbox.is-open {
      display: flex;
    }
    .image-lightbox__panel {
      max-width: 95%;
      max-height: 95%;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
    }
    .image-lightbox__panel img {
      max-width: 100%;
      max-height: 85vh;
      border-radius: 8px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, .5);
    }
    .image-lightbox__close {
      position: fixed;
      top: 18px;
      right: 18px;
      width: 40px;
      height: 40px;
      border-radius: 9999px;
      background: rgba(255, 255, 255, 0.9);
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 18px;
      color: #111827;
      z-index: 601;
    }

    /* =============================================
       SIDEBAR MOBILE OVERLAY
    ============================================= */
    .sidebar-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.4);
      z-index: 99;
    }

    /* =============================================
       RESPONSIVE – TABLET (≤1024px)
    ============================================= */
    @media (max-width: 1024px) {
      .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        bottom: 0;
        z-index: 100;
        transform: translateX(-100%);
        transition: transform .3s;
      }
      .sidebar.is-open { transform: translateX(0); }
      .sidebar-overlay.is-open { display: block; }
      .sidebar-toggle { display: flex; }
      .topbar { padding-left: 64px; }
      .info-grid { grid-template-columns: 1fr; }
    }

    /* =============================================
       RESPONSIVE – MOBILE (≤768px)
    ============================================= */
    @media (max-width: 768px) {
      .content { padding: var(--space-4); gap: var(--space-4); }
      .page-alert { margin-bottom: 12px; }
      .toolbar { flex-direction: column; align-items: stretch; }
      .toolbar__left { flex-wrap: wrap; }
      .toolbar__search input { width: 100%; }
      .btn-export { justify-content: center; }
      .topbar { padding: 0 var(--space-4) 0 64px; }
      .topbar__title { font-size: var(--font-lg); }
      .topbar__user-info { display: none; }
      .toolbar__left { gap: 8px; }
      .filter-btn { width: 100%; justify-content: center; }
      .filter-btn__count { margin-left: auto; }
      .table-card { border-radius: 14px; }
      .table-wrap { overflow: visible; }
      table { min-width: 0; }
      thead { display: none; }
      tbody tr {
        display: block;
        padding: 12px;
      }
      tbody td {
        display: block;
        height: auto;
        padding: 8px 0;
      }
      tbody td::before {
        content: attr(data-label);
        display: block;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--color-muted);
        margin-bottom: 4px;
      }
      tbody td:first-child::before {
        margin-bottom: 8px;
      }
      tbody td:last-child::before {
        margin-bottom: 8px;
      }
      .actions { flex-wrap: wrap; }
      .action-btn { width: 36px; height: 36px; }
      .detail-grid { grid-template-columns: 1fr; }
      .modal { padding: 12px; }
      .modal__panel {
        max-height: calc(100vh - 24px);
        border-radius: 16px;
      }
      .modal__header,
      .modal__body,
      .modal__footer {
        padding-left: 16px;
        padding-right: 16px;
      }
      .modal__footer { justify-content: stretch; }
      .modal__action,
      .modal__button { width: 100%; }
      .modal__footer { flex-direction: column; }
      .modal__close { flex-shrink: 0; }
      .card-auto__stats { grid-template-columns: 1fr; }
    }
  </style>
  <style>
    /* Attendance-style visual overrides */
    body {
      background: #f9fafb;
      color: #101828;
    }

    .app {
      min-height: 100vh;
      height: auto;
      overflow: hidden;
    }

    .sidebar {
      background: #ffffff;
      border-right: 1px solid #e5e7eb;
      overflow: hidden;
    }

    .nav-item {
      border-radius: 10px;
      height: 48px;
      padding: 0 16px;
      gap: 12px;
    }

    .nav-item:hover {
      background: #f9fafb;
    }

    .nav-item--active {
      background: #155dfc;
      color: #ffffff;
    }

    .nav-item--active:hover {
      background: #155dfc;
    }

    .nav-item__label {
      color: inherit;
      line-height: 24px;
    }

    .nav-item__badge {
      background: #dbeafe;
      color: #155dfc;
    }

    .main {
      min-width: 0;
    }

    .topbar {
      height: 89px;
      padding: 0 32px;
      background: #ffffff;
      border-bottom: 1px solid #e5e7eb;
    }

    .topbar__title {
      font-size: 24px;
      line-height: 32px;
    }

    .topbar__notif:hover {
      background: #f9fafb;
    }

    .page-content {
      padding: 32px;
      gap: 24px;
    }

    .page-alert {
      margin-bottom: 0;
    }

    .toolbar {
      align-items: flex-start;
      gap: 16px;
    }

    .toolbar__left {
      gap: 12px;
    }

    .toolbar__search input,
    .filter-select {
      height: 42px;
      border-radius: 10px;
    }

    .toolbar__search input {
      width: 320px;
      background: #ffffff;
    }

    .filter-btn {
      height: 40px;
      border-radius: 10px;
    }

    .btn-export {
      height: 40px;
      border-radius: 10px;
    }

    .stats-row {
      gap: 16px;
    }

    .stat-card {
      border-radius: 16px;
      min-height: 142px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.10), 0 1px 2px rgba(0, 0, 0, 0.06);
    }

    .table-card {
      border-radius: 16px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.10), 0 1px 2px rgba(0, 0, 0, 0.06);
    }

    .table-card__header {
      min-height: 101px;
      padding: 24px;
    }

    .att-table thead th {
      padding: 16px 24px;
    }

    .att-table tbody td {
      padding: 0 24px;
    }

    .info-grid {
      gap: 24px;
    }

    .card-auto,
    .card-ai {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.10), 0 1px 2px rgba(0, 0, 0, 0.06);
      border-radius: 16px;
      padding: 24px;
      min-height: 220px;
    }

    .card-auto__title,
    .card-ai__title {
      color: #101828;
      font-size: 18px;
    }

    .card-auto__desc,
    .card-ai__desc,
    .rec-item__office,
    .stat-box__label {
      color: #4a5565;
    }

    .card-auto__stats {
      gap: 16px;
    }

    .stat-box,
    .rec-item {
      background: #f9fafb;
      border-radius: 12px;
    }

    .stat-box__num,
    .rec-item__name,
    .rec-item__pct {
      color: #101828;
    }

    .stat-box__label,
    .rec-item__label {
      color: #4a5565;
    }

    .modal__panel {
      border-radius: 16px;
    }

    .modal__header,
    .modal__body,
    .modal__footer {
      padding-left: 24px;
      padding-right: 24px;
    }

    @media (max-width: 1024px) {
      .page-content {
        padding: 24px;
      }
    }

    @media (max-width: 768px) {
      .page-content {
        padding: 16px;
      }

      .toolbar__search input {
        width: 100%;
      }

      .info-grid,
      .stats-row {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>

<div class="app">

  <!-- ============================================
       SIDEBAR
  ============================================= -->
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">

    <!-- Brand -->
    <div class="sidebar__brand">
      <div class="sidebar__logo" aria-hidden="true">NU</div>
      <div>
        <div class="sidebar__brand-name">SA System</div>
        <div class="sidebar__brand-sub">Admin Panel</div>
      </div>
    </div>

    <!-- Nav items -->
    <nav class="sidebar__nav" aria-label="Site navigation">
      <a class="nav-item" href="dashboard.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="2" y="2" width="7" height="7" rx="1.5" fill="#364153"/>
            <rect x="11" y="2" width="7" height="7" rx="1.5" fill="#364153"/>
            <rect x="2" y="11" width="7" height="7" rx="1.5" fill="#364153"/>
            <rect x="11" y="11" width="7" height="7" rx="1.5" fill="#364153"/>
          </svg>
        </span>
        <span class="nav-item__label">Dashboard</span>
      </a>
      <a class="nav-item nav-item--active" href="application.php" aria-current="page">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M6 2h8a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V4a2 2 0 012-2z" stroke="white" stroke-width="1.5"/>
            <path d="M7 7h6M7 10h6M7 13h4" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
        </span>
        <span class="nav-item__label">Applications</span>
        <span class="nav-item__badge"><?= (int) $applicationCounts['pending'] ?></span>
      </a>
      <a class="nav-item" href="scheduling.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="2" y="4" width="16" height="14" rx="2" stroke="#364153" stroke-width="1.5"/>
            <path d="M6 2v4M14 2v4" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M2 9h16" stroke="#364153" stroke-width="1.2"/>
          </svg>
        </span>
        <span class="nav-item__label">Scheduling</span>
      </a>
      <a class="nav-item" href="attendance.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="10" cy="10" r="8" stroke="#364153" stroke-width="1.5"/>
            <path d="M6.5 10.5l2.5 2.5 4.5-5" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        <span class="nav-item__label">Attendance</span>
      </a>
      <a class="nav-item" href="evaluation.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 2l2.09 4.26L17 7.27l-3.5 3.41.83 4.82L10 13.27l-4.33 2.23.83-4.82L3 7.27l4.91-.71L10 2z" stroke="#364153" stroke-width="1.5" stroke-linejoin="round"/>
          </svg>
        </span>
        <span class="nav-item__label">Evaluation</span>
      </a>
      <a class="nav-item" href="reports.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="3" y="12" width="3" height="6" rx="1" fill="#364153"/>
            <rect x="8.5" y="8" width="3" height="10" rx="1" fill="#364153"/>
            <rect x="14" y="4" width="3" height="14" rx="1" fill="#364153"/>
          </svg>
        </span>
        <span class="nav-item__label">Reports</span>
      </a>
      <a class="nav-item" href="announcements.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 1c-1.5 0-2.5 1.5-2.5 3v4H4c-1.1 0-2 .9-2 2v4c0 1.1.9 2 2 2h1v2c0 1.1.9 2 2 2s2-.9 2-2v-2h4v2c0 1.1.9 2 2 2s2-.9 2-2v-2h1c1.1 0 2-.9 2-2v-4c0-1.1-.9-2-2-2h-3.5V4c0-1.5-1-3-2.5-3Z" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        <span class="nav-item__label">Announcements</span>
      </a>
      <a class="nav-item" href="students.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="10" cy="6.5" r="3" stroke="#364153" stroke-width="1.5"/>
            <path d="M3.5 17c0-3.5 2.9-6 6.5-6s6.5 2.5 6.5 6" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
        </span>
        <span class="nav-item__label">Students</span>
      </a>
    </nav>

    <!-- Footer nav -->
    <div class="sidebar__footer">
      <a class="nav-item" href="dashboard.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M8.325 2.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37a1.724 1.724 0 002.572-1.065z" stroke="#364153" stroke-width="1.3"/>
            <circle cx="10" cy="10" r="3" stroke="#364153" stroke-width="1.3"/>
          </svg>
        </span>
        <span class="nav-item__label">Settings</span>
      </a>
      <a class="nav-item" href="logout.php">
        <span class="nav-item__icon" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M7 3H4a1 1 0 00-1 1v12a1 1 0 001 1h3" stroke="#364153" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M13 14l3-4-3-4M16 10H7" stroke="#364153" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        <span class="nav-item__label">Sign Out</span>
      </a>
    </div>
  </aside>

  <!-- Mobile sidebar overlay -->
  <div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>

  <!-- Hamburger toggle -->
  <button class="sidebar-toggle" id="sidebar-toggle"
    aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">
    <span class="sidebar-toggle__bar"></span>
    <span class="sidebar-toggle__bar"></span>
    <span class="sidebar-toggle__bar"></span>
  </button>

  <!-- ============================================
       MAIN
  ============================================= -->
  <div class="main">

    <!-- Top bar -->
    <header class="topbar" role="banner">
      <div>
        <div class="topbar__title">Application Management</div>
        <div class="topbar__sub">NU Lipa - Student Development and Activities Office</div>
      </div>
      <div class="topbar__user">
        <div class="topbar__notif" aria-label="Notifications">
          <svg class="topbar__notif-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z" fill="#4A5565"/>
          </svg>
          <span class="topbar__notif-dot" aria-label="New notifications"></span>
        </div>
        <div class="topbar__user-info">
          <div class="topbar__user-name">Zaira Joy S. Enayo</div>
          <div class="topbar__user-role">SDAO Head</div>
        </div>
        <div class="topbar__avatar">
          <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="10" cy="7" r="4" fill="white" opacity=".9"/>
            <path d="M2 17c0-3.314 3.582-6 8-6s8 2.686 8 6" fill="white" opacity=".9"/>
          </svg>
        </div>
      </div>
    </header>

    <!-- Page content -->
    <main class="content" role="main">

      <?php if ($flashMessage !== ''): ?>
        <div class="page-alert page-alert--success" role="status"><?= htmlspecialchars($flashMessage) ?></div>
      <?php endif; ?>
      <?php if ($flashError !== ''): ?>
        <div class="page-alert page-alert--error" role="alert"><?= htmlspecialchars($flashError) ?></div>
      <?php endif; ?>

      <!-- ---- Toolbar ---- -->
      <div class="toolbar">
        <div class="toolbar__left">
          <!-- Search -->
          <div class="toolbar__search">
            <span class="toolbar__search-icon" aria-hidden="true">
              <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="9" cy="9" r="6" stroke="#6A7282" stroke-width="1.6"/>
                <path d="M13.5 13.5L17 17" stroke="#6A7282" stroke-width="1.6" stroke-linecap="round"/>
              </svg>
            </span>
            <input type="search" id="search-input" placeholder="Search applicants..."
              aria-label="Search applicants" />
          </div>
          <!-- Filter buttons -->
          <?php foreach ($statusFilterOptions as $filterKey => $filterLabel): ?>
          <button class="filter-btn <?= $filterKey === 'all' ? 'filter-btn--active' : 'filter-btn--inactive' ?>"
            data-filter="<?= htmlspecialchars($filterKey) ?>"
            aria-pressed="<?= $filterKey === 'all' ? 'true' : 'false' ?>">
            <?= htmlspecialchars($filterLabel) ?>
            <span class="filter-btn__count">(<?= (int) ($filterKey === 'all' ? $applicationCounts['all'] : $applicationCounts[$filterKey]) ?>)</span>
          </button>
          <?php endforeach; ?>
        </div>
        <a class="btn-export" href="#" aria-label="Export applicant list">
          <svg class="btn-export__icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M10 3v9M10 12L6.5 8.5M10 12l3.5-3.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M3 14.5v1A1.5 1.5 0 004.5 17h11A1.5 1.5 0 0017 15.5v-1" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
          Export List
        </a>
      </div>

      <!-- ---- Table ---- -->
      <div class="table-card">
        <div class="table-wrap">
          <table aria-label="Applications table">
            <thead>
              <tr>
                <th scope="col">Applicant</th>
                <th scope="col">Student ID</th>
                <th scope="col">Program</th>
                <th scope="col">Preferred Office</th>
                <th scope="col">Skills</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody id="table-body">

              <?php if (empty($applications)): ?>
              <tr>
                <td colspan="7" style="padding: 32px; text-align: center; color: var(--color-muted);">
                  No applications have been submitted yet.
                </td>
              </tr>
              <?php else: ?>
                <?php foreach ($applications as $application): ?>
                <?php
                  $fullName = trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? ''));
                  $avatar = sams_application_avatar($fullName);
                  $skills = sams_application_skill_tags($application['skills'] ?? null);
                  $status = (string) ($application['status'] ?? 'pending');
                  $submittedAt = $application['submitted_at'] ? date('M j, Y', strtotime((string) $application['submitted_at'])) : 'N/A';
                  $skillsText = implode(', ', $skills);
                ?>
              <tr data-status="<?= htmlspecialchars($status) ?>">
                <td data-label="Applicant">
                  <div class="applicant">
                    <div class="applicant__avatar" aria-hidden="true"><?= htmlspecialchars($avatar) ?></div>
                    <div>
                      <div class="applicant__name"><?= htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Applicant') ?></div>
                      <div class="applicant__date">Applied <?= htmlspecialchars($submittedAt) ?></div>
                    </div>
                  </div>
                </td>
                <td data-label="Student ID"><?= htmlspecialchars((string) ($application['student_id_number'] ?? '')) ?></td>
                <td data-label="Program"><?= htmlspecialchars((string) ($application['program'] ?? '')) ?></td>
                <td data-label="Preferred Office"><?= htmlspecialchars((string) ($application['preferred_office'] ?? '')) ?></td>
                <td data-label="Skills">
                  <div class="skills">
                    <?php if (empty($skills)): ?>
                      <span class="skill-tag">No skills listed</span>
                    <?php else: ?>
                      <?php foreach (array_slice($skills, 0, 3) as $skill): ?>
                        <span class="skill-tag"><?= htmlspecialchars($skill) ?></span>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td data-label="Status"><span class="badge <?= htmlspecialchars(sams_application_status_class($status)) ?>"><?= htmlspecialchars(sams_application_status_label($status)) ?></span></td>
                <td data-label="Actions">
                  <div class="actions">
                    <button
                      class="action-btn action-btn--view"
                      type="button"
                      title="View application"
                      aria-label="View <?= htmlspecialchars($fullName !== '' ? $fullName : 'applicant') ?>"
                      data-application-id="<?= (int) $application['application_id'] ?>"
                      data-name="<?= htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Applicant') ?>"
                      data-email="<?= htmlspecialchars((string) ($application['email'] ?? '')) ?>"
                      data-student-id="<?= htmlspecialchars((string) ($application['student_id_number'] ?? '')) ?>"
                      data-program="<?= htmlspecialchars((string) ($application['program'] ?? '')) ?>"
                      data-year-level="<?= htmlspecialchars((string) ($application['year_level'] ?? '')) ?>"
                      data-office="<?= htmlspecialchars((string) ($application['preferred_office'] ?? '')) ?>"
                      data-status="<?= htmlspecialchars(sams_application_status_label($status)) ?>"
                      data-submitted-at="<?= htmlspecialchars($submittedAt) ?>"
                      data-skills="<?= htmlspecialchars($skillsText !== '' ? $skillsText : 'No skills listed') ?>"
                      data-avatar="<?= htmlspecialchars($avatar) ?>"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </button>
                    <form class="action-form" method="post">
                      <input type="hidden" name="application_id" value="<?= (int) $application['application_id'] ?>" />
                      <input type="hidden" name="review_action" value="approve" />
                      <button class="action-btn action-btn--approve" type="submit" title="Approve" aria-label="Approve <?= htmlspecialchars($fullName !== '' ? $fullName : 'applicant') ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                      </button>
                    </form>
                    <form class="action-form" method="post">
                      <input type="hidden" name="application_id" value="<?= (int) $application['application_id'] ?>" />
                      <input type="hidden" name="review_action" value="reject" />
                      <button class="action-btn action-btn--reject" type="submit" title="Reject" aria-label="Reject <?= htmlspecialchars($fullName !== '' ? $fullName : 'applicant') ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                      </button>
                    </form>
                    <button class="action-btn action-btn--msg" title="Message" aria-label="Message <?= htmlspecialchars($fullName !== '' ? $fullName : 'applicant') ?>">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    </button>
                  </div>
                </td>
              </tr>
                <?php endforeach; ?>
              <?php endif; ?>

            </tbody>
          </table>
        </div>
      </div>

      <!-- ---- Bottom info grid ---- -->
      <div class="info-grid">

        <!-- Auto-Filtering card -->
        <div class="card-auto">
          <div class="card-auto__title">🤖 Live Applications</div>
          <div class="card-auto__desc">Applications are loaded directly from the database. Only submitted applicants are shown above.</div>
          <div class="card-auto__stats">
            <div class="stat-box">
              <div class="stat-box__num"><?= (int) $applicationCounts['all'] ?></div>
              <div class="stat-box__label">Total Applications</div>
            </div>
            <div class="stat-box">
              <div class="stat-box__num"><?= (int) $applicationCounts['pending'] ?></div>
              <div class="stat-box__label">Pending</div>
            </div>
          </div>
        </div>

        <!-- AI Recommendations card -->
        <div class="card-ai">
          <div class="card-ai__title">✨ AI Recommendations</div>
          <div class="card-ai__desc">Based on the latest submitted application records</div>
          <div class="card-ai__list">
            <?php if (empty($applications)): ?>
              <div class="rec-item">
                <div>
                  <div class="rec-item__name">No applications yet</div>
                  <div class="rec-item__office">Wait for student submissions</div>
                </div>
              </div>
            <?php else: ?>
              <?php foreach (array_slice($applications, 0, 2) as $application): ?>
                <?php $fullName = trim((string) ($application['first_name'] ?? '') . ' ' . (string) ($application['last_name'] ?? '')); ?>
                <div class="rec-item">
                  <div>
                    <div class="rec-item__name"><?= htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Applicant') ?></div>
                    <div class="rec-item__office"><?= htmlspecialchars((string) ($application['preferred_office'] ?? '')) ?></div>
                  </div>
                  <div class="rec-item__match">
                    <div class="rec-item__pct"><?= htmlspecialchars(strtoupper((string) ($application['status'] ?? 'PENDING'))) ?></div>
                    <div class="rec-item__label">Status</div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>

    </main>
  </div><!-- /.main -->

  <!-- ============================================
       APPLICATION DETAIL MODAL
  ============================================= -->
  <div class="modal" id="application-modal" aria-hidden="true" role="dialog" aria-labelledby="application-modal-title">
    <div class="modal__panel" role="document">
      <div class="modal__header">
        <div>
          <h2 class="modal__title" id="application-modal-title">Applicant Details</h2>
          <div class="modal__subtitle" id="application-modal-subtitle">Review the submission before making a decision.</div>
        </div>
        <button class="modal__close" type="button" id="application-modal-close" aria-label="Close details panel">&times;</button>
      </div>
      <div class="modal__body">
        <div class="detail-grid">
          <div class="detail-item">
            <span class="detail-item__label">Applicant</span>
            <div class="detail-item__value" id="modal-name"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Student ID</span>
            <div class="detail-item__value" id="modal-student-id"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Email</span>
            <div class="detail-item__value" id="modal-email"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Program / Year Level</span>
            <div class="detail-item__value" id="modal-program"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Preferred Office</span>
            <div class="detail-item__value" id="modal-office"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Status</span>
            <div class="detail-item__value" id="modal-status"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Submitted At</span>
            <div class="detail-item__value" id="modal-submitted-at"></div>
          </div>
          <div class="detail-item">
            <span class="detail-item__label">Skills</span>
            <div class="detail-item__value modal__skills" id="modal-skills"></div>
          </div>
          <div class="detail-item" style="grid-column: 1 / -1;">
            <span class="detail-item__label">Documents</span>
            <div class="detail-item__value" id="modal-documents">Loading documents…</div>
          </div>
          <div class="detail-item" style="grid-column: 1 / -1;">
            <span class="detail-item__label">Availability</span>
            <div class="detail-item__value" id="modal-availability">Loading availability…</div>
          </div>
        </div>
      </div>
      <div class="modal__footer">
        <button class="modal__button modal__button--ghost" type="button" id="modal-cancel">Close</button>
        <form class="modal__action" method="post" id="modal-approve-form">
          <input type="hidden" name="application_id" id="modal-approve-id" value="" />
          <input type="hidden" name="review_action" value="approve" />
          <button class="modal__button modal__button--approve" type="submit">Approve</button>
          <button id="resendEmailBtn" class="modal__button" type="button" style="margin-left:8px;background:#f3f4f6;color:#111;border:1px solid var(--color-border);">Resend Email</button>
        </form>
        <form class="modal__action" method="post" id="modal-reject-form">
          <input type="hidden" name="application_id" id="modal-reject-id" value="" />
          <input type="hidden" name="review_action" value="reject" />
          <button class="modal__button modal__button--reject" type="submit">Reject</button>
        </form>
      </div>
    </div>
  </div>

</div><!-- /.app -->

<!-- ============================================
     IMAGE LIGHTBOX
============================================= -->
<div class="image-lightbox" id="image-lightbox" aria-hidden="true" role="dialog" aria-label="Document preview">
  <div class="image-lightbox__panel" id="image-lightbox-panel">
    <button class="image-lightbox__close" id="image-lightbox-close" aria-label="Close image">&times;</button>
    <img id="image-lightbox-img" src="" alt="" />
  </div>
</div>

<script>
  (function () {
    'use strict';

    /* ---- Sidebar toggle (mobile/tablet) ---- */
    var toggle  = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');

    function openSidebar() {
      sidebar.classList.add('is-open');
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
      toggle.setAttribute('aria-expanded', 'true');
    }
    function closeSidebar() {
      sidebar.classList.remove('is-open');
      overlay.classList.remove('is-open');
      overlay.setAttribute('aria-hidden', 'true');
      toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
      sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar();
    });
    overlay.addEventListener('click', closeSidebar);

    /* ---- Filter buttons ---- */
    var filterBtns = document.querySelectorAll('.filter-btn');
    var rows = document.querySelectorAll('#table-body tr');

    filterBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var filter = btn.getAttribute('data-filter');

        filterBtns.forEach(function (b) {
          b.classList.remove('filter-btn--active');
          b.classList.add('filter-btn--inactive');
          b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('filter-btn--active');
        btn.classList.remove('filter-btn--inactive');
        btn.setAttribute('aria-pressed', 'true');

        rows.forEach(function (row) {
          var status = row.getAttribute('data-status');
          row.style.display = (filter === 'all' || status === filter) ? '' : 'none';
        });
      });
    });

    /* ---- Live search ---- */
    var searchInput = document.getElementById('search-input');
    searchInput.addEventListener('input', function () {
      var q = this.value.toLowerCase().trim();
      rows.forEach(function (row) {
        var text = row.textContent.toLowerCase();
        row.style.display = (!q || text.includes(q)) ? '' : 'none';
      });
    });

    /* ---- Image lightbox ---- */
    var lightbox      = document.getElementById('image-lightbox');
    var lightboxClose = document.getElementById('image-lightbox-close');
    var lightboxPanel = document.getElementById('image-lightbox-panel');
    var lightboxImg   = document.getElementById('image-lightbox-img');

    function showImageModal(src, alt) {
      if (!lightbox || !lightboxImg) return;
      lightboxImg.src = src;
      lightboxImg.alt = alt || '';
      lightboxImg.onerror = function () {
        lightboxImg.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="120"%3E%3Crect fill="%23f3f4f6" width="200" height="120"/%3E%3Ctext x="50%25" y="50%25" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="13" fill="%236b7280"%3EImage not found%3C/text%3E%3C/svg%3E';
      };
      lightbox.classList.add('is-open');
      lightbox.setAttribute('aria-hidden', 'false');
    }

    function closeImageModal() {
      if (!lightbox || !lightboxImg) return;
      lightbox.classList.remove('is-open');
      lightbox.setAttribute('aria-hidden', 'true');
      lightboxImg.src = '';
      lightboxImg.alt = '';
    }

    if (lightboxClose) {
      lightboxClose.addEventListener('click', function (e) {
        e.stopPropagation();
        closeImageModal();
      });
    }
    if (lightbox) {
      lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox) closeImageModal();
      });
    }
    if (lightboxPanel) {
      lightboxPanel.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    }

    /* ---- Applicant details modal ---- */
    var modal       = document.getElementById('application-modal');
    var modalClose  = document.getElementById('application-modal-close');
    var modalCancel = document.getElementById('modal-cancel');
    var modalButtons = document.querySelectorAll('.action-btn--view');
    var approveId   = document.getElementById('modal-approve-id');
    var rejectId    = document.getElementById('modal-reject-id');

    var modalFields = {
      name:        document.getElementById('modal-name'),
      studentId:   document.getElementById('modal-student-id'),
      email:       document.getElementById('modal-email'),
      program:     document.getElementById('modal-program'),
      office:      document.getElementById('modal-office'),
      status:      document.getElementById('modal-status'),
      submittedAt: document.getElementById('modal-submitted-at'),
      skills:      document.getElementById('modal-skills')
    };

    function setSkills(value) {
      modalFields.skills.innerHTML = '';
      var items = (value || '').split(',').map(function (i) { return i.trim(); }).filter(Boolean);
      if (items.length === 0) { items = ['No skills listed']; }
      items.forEach(function (item) {
        var span = document.createElement('span');
        span.className = 'skill-tag';
        span.textContent = item;
        modalFields.skills.appendChild(span);
      });
    }

    function renderDocuments(docs) {

  var container = document.getElementById('modal-documents');

  if (!container) return;

  container.innerHTML = '';

  if (!Array.isArray(docs) || docs.length === 0) {

    container.innerHTML = `
      <div style="
        padding:12px;
        border-radius:10px;
        background:#f9fafb;
        color:#6b7280;
        font-size:14px;
      ">
        No documents uploaded.
      </div>
    `;

    return;
  }

  docs.forEach(function (doc) {

    var wrapper = document.createElement('div');

    wrapper.style.background = '#f8fafc';
    wrapper.style.border = '1px solid #e5e7eb';
    wrapper.style.borderRadius = '14px';
    wrapper.style.padding = '16px';
    wrapper.style.marginBottom = '16px';

    /* ---------------- TITLE ---------------- */

    var title = document.createElement('div');

    title.style.fontSize = '14px';
    title.style.fontWeight = '700';
    title.style.marginBottom = '12px';
    title.style.color = '#111827';

    title.textContent =
      (doc.document_type || 'Document') +
      ' • ' +
      (doc.original_filename || 'file');

    wrapper.appendChild(title);

    /* ---------------- FILE ---------------- */

    var mime = (doc.mime_type || '').toLowerCase();

    var filePath = '';

    if (doc.file_path) {
      filePath = '../' + doc.file_path;
    }

    /* ---------------- IMAGE ---------------- */

    if (mime.startsWith('image/') && filePath) {

      var image = document.createElement('img');

      image.src = filePath;

      image.alt =
        doc.original_filename || 'Uploaded Document';

      image.style.width = '160px';
      image.style.height = '160px';
      image.style.objectFit = 'cover';
      image.style.borderRadius = '12px';
      image.style.border = '1px solid #d1d5db';
      image.style.cursor = 'pointer';
      image.style.transition = '0.2s ease';
      image.style.display = 'block';
      image.style.background = '#ffffff';

      image.addEventListener('mouseover', function () {
        image.style.transform = 'scale(1.03)';
      });

      image.addEventListener('mouseout', function () {
        image.style.transform = 'scale(1)';
      });

      image.addEventListener('click', function (e) {

        e.preventDefault();
        e.stopPropagation();

        var lightbox =
          document.getElementById('image-lightbox');

        var lightboxImg =
          document.getElementById('image-lightbox-img');

        if (!lightbox || !lightboxImg) return;

        lightboxImg.src = filePath;

        lightboxImg.alt =
          doc.original_filename || 'Preview';

        lightbox.classList.add('is-open');

        lightbox.setAttribute(
          'aria-hidden',
          'false'
        );
      });

      image.onerror = function () {

        image.src =
          'https://via.placeholder.com/160x160?text=Image+Not+Found';
      };

      wrapper.appendChild(image);

    }

    /* ---------------- PDF / FILE ---------------- */

    else if (filePath) {

      var fileLink = document.createElement('a');

      fileLink.href = filePath;

      fileLink.target = '_blank';

      fileLink.textContent =
        '📄 Open ' +
        (doc.original_filename || 'Document');

      fileLink.style.display = 'inline-block';
      fileLink.style.padding = '10px 14px';
      fileLink.style.background = '#2563eb';
      fileLink.style.color = '#ffffff';
      fileLink.style.borderRadius = '10px';
      fileLink.style.fontSize = '14px';
      fileLink.style.fontWeight = '600';
      fileLink.style.textDecoration = 'none';

      wrapper.appendChild(fileLink);

    }

    /* ---------------- NO FILE ---------------- */

    else {

      var unavailable = document.createElement('div');

      unavailable.textContent =
        'File not available.';

      unavailable.style.color = '#dc2626';

      wrapper.appendChild(unavailable);
    }

    container.appendChild(wrapper);
  });
}

    function renderAvailability(list) {
      var container = document.getElementById('modal-availability');
      if (!container) return;
      container.innerHTML = '';

      if (!Array.isArray(list) || list.length === 0) {
        container.textContent = 'No availability provided.';
        return;
      }

      var ul = document.createElement('ul');
      ul.style.cssText = 'list-style:none;padding:0;margin:0;';
      list.forEach(function (row) {
        var li = document.createElement('li');
        li.style.padding = '6px 0';
        var start = (row.time_start || row.start_time || '').substr(0, 5);
        var end   = (row.time_end || row.end_time || '').substr(0, 5);
        li.textContent = (row.day_of_week || '') + ': ' + start + ' — ' + end;
        ul.appendChild(li);
      });
      container.appendChild(ul);
    }

    function openModal(button) {
      modalFields.name.textContent        = button.getAttribute('data-name') || '';
      modalFields.studentId.textContent   = button.getAttribute('data-student-id') || '';
      modalFields.email.textContent       = button.getAttribute('data-email') || '';
      modalFields.program.textContent     = ((button.getAttribute('data-program') || '') + ' • ' + (button.getAttribute('data-year-level') || '')).trim();
      modalFields.office.textContent      = button.getAttribute('data-office') || '';
      modalFields.status.textContent      = button.getAttribute('data-status') || '';
      modalFields.submittedAt.textContent = button.getAttribute('data-submitted-at') || '';
      setSkills(button.getAttribute('data-skills') || '');

      var appId = button.getAttribute('data-application-id') || '';
      approveId.value = appId;
      rejectId.value  = appId;

      document.getElementById('modal-documents').innerHTML  = 'Loading documents…';
      document.getElementById('modal-availability').innerHTML = 'Loading availability…';

      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');

      if (appId) {
        fetch('application_detail.php?application_id=' + encodeURIComponent(appId), { credentials: 'same-origin' })
          .then(function (res) {
            if (!res.ok) throw new Error('Failed to load details');
            return res.json();
          })
          .then(function (json) {
            renderDocuments(json.documents || []);
            renderAvailability(json.availability || []);
          })
          .catch(function () {
            document.getElementById('modal-documents').textContent  = 'Unable to load documents.';
            document.getElementById('modal-availability').textContent = 'Unable to load availability.';
          });
      }
    }

    function closeModal() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }

    modalButtons.forEach(function (btn) {
      btn.addEventListener('click', function () { openModal(btn); });
    });

    modalClose.addEventListener('click', closeModal);
    modalCancel.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });

    /* ---- Global Escape key handler ---- */
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (lightbox && lightbox.classList.contains('is-open')) {
        closeImageModal();
      } else if (modal.classList.contains('is-open')) {
        closeModal();
      }
    });

    // Resend approval email button handler
    var resendBtn = document.getElementById('resendEmailBtn');
    if (resendBtn) {
      resendBtn.addEventListener('click', function () {
        var id = parseInt(document.getElementById('modal-approve-id').value || '0', 10);
        if (!id) return alert('No application selected');
        resendBtn.disabled = true;
        fetch('resend_application_email.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ application_id: id })
        }).then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.success) {
            alert('Email resent');
          } else {
            alert('Failed to resend: ' + (d && d.message ? d.message : 'Unknown'));
          }
        }).catch(function (e) { console.error(e); alert('Request failed'); }).finally(function () { resendBtn.disabled = false; });
      });
    }

  }());
</script>

</body>
</html>