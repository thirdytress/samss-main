<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['_csrf'] ?? '');
    if (!sams_verify_csrf($postedToken)) {
        http_response_code(400);
        echo 'Invalid CSRF token';
        exit;
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $audience = in_array((string) ($_POST['audience'] ?? ''), ['students', 'supervisors', 'all'], true)
        ? (string) $_POST['audience']
        : 'students';

    if ($title !== '' && $body !== '') {
        $stmt = $pdo->prepare(
            'INSERT INTO announcements (title, body, audience, is_active, created_by)
             VALUES (:title, :body, :audience, 1, :created_by)'
        );
        $stmt->execute([
            'title' => $title,
            'body' => $body,
            'audience' => $audience,
            'created_by' => (int) ($currentUser['user_id'] ?? $currentUser['id'] ?? 0),
        ]);

        header('Location: announcements.php?created=1');
        exit;
    }
}

$listStmt = $pdo->query('SELECT id, title, body, audience, is_active, created_at FROM announcements ORDER BY created_at DESC LIMIT 50');
$announcements = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$department = 'NU Lipa - Student Development and Activities Office';
$activeAdminNav = 'announcements';
$pendingApplications = 0;

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sams_admin_dashboard_initials(?string $firstName, ?string $lastName): string
{
    $first = strtoupper(substr(trim((string) $firstName), 0, 1));
    $last = strtoupper(substr(trim((string) $lastName), 0, 1));
    $initials = trim($first . $last);

    return $initials !== '' ? $initials : 'SA';
}

$adminName = trim((string) ($currentUser['first_name'] ?? '') . ' ' . (string) ($currentUser['last_name'] ?? ''));
if ($adminName === '') {
    $adminName = 'Admin User';
}

$adminRole = (string) ($currentUser['role'] ?? 'SDAO Head');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Announcements - NU SA System Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/css/sams-shell.css" />
    <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
    <style>
        .page.announcements-page {
            padding: 32px;
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .hero-card,
        .panel-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .hero-card {
            padding: 24px 28px;
        }

        .hero-kicker,
        .section-kicker {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .hero-card h1,
        .panel-title {
            margin: 0;
            color: #111827;
            letter-spacing: -0.02em;
        }

        .hero-card h1 {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 10px;
        }

        .hero-card p,
        .panel-subtitle {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }

        .announcements-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr);
            gap: 24px;
            align-items: start;
        }

        .panel-card {
            padding: 24px;
            min-width: 0;
        }

        .panel-header {
            margin-bottom: 18px;
        }

        .panel-title {
            font-size: 18px;
            font-weight: 800;
            line-height: 1.25;
        }

        .panel-subtitle {
            margin-top: 4px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
        }

        .form-label {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        .form-input,
        .form-textarea,
        .form-select {
            width: 100%;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            color: #111827;
            font: inherit;
            padding: 12px 14px;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .form-input::placeholder,
        .form-textarea::placeholder {
            color: #9ca3af;
        }

        .form-textarea {
            min-height: 180px;
            resize: vertical;
        }

        .form-input:focus,
        .form-textarea:focus,
        .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.10);
        }

        .announcement-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .announcement-item {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            background: #ffffff;
            transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }

        .announcement-item:hover {
            border-color: #d1d5db;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transform: translateY(-1px);
        }

        .announcement-title {
            font-size: 16px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 8px;
            line-height: 1.35;
        }

        .announcement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 10px;
        }

        .announcement-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 9999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
        }

        .announcement-body {
            color: #374151;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 14px;
        }

        .page-alert {
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 14px;
            font-weight: 600;
        }

        .page-alert--success {
            background: #ecfdf3;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .page-alert--error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .empty-state {
            padding: 18px;
            text-align: center;
            border: 1px dashed #d1d5db;
            border-radius: 12px;
            color: #6b7280;
            background: #ffffff;
        }

        .topbar__user {
            display: flex;
            align-items: center;
            gap: 12px;
            white-space: nowrap;
        }

        .topbar__user-info {
            text-align: right;
            white-space: nowrap;
        }

        .topbar__user-name {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            line-height: 1.2;
        }

        .topbar__user-role {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.2;
        }

        .topbar__avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
            font-weight: 700;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 16px;
            border: 1px solid transparent;
            border-radius: 8px;
            background: #3b82f6;
            color: #fff;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(59, 130, 246, .18);
        }

        @media (max-width: 1024px) {
            .announcements-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page.announcements-page {
                padding: 20px 16px 28px;
            }

            .hero-card,
            .panel-card {
                padding: 20px;
            }

            .hero-card h1 {
                font-size: 24px;
            }

            .topbar__user-info {
                display: none;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <?php require_once __DIR__ . '/_sidebar.php'; ?>

    <div class="main">
        <header class="topbar" role="banner">
            <div>
                <div class="topbar__title">Announcements</div>
                <div class="topbar__sub"><?= h($department) ?></div>
            </div>

            <div class="topbar__user" aria-label="Logged in user">
                <div class="topbar__user-info">
                    <div class="topbar__user-name"><?= h($adminName) ?></div>
                    <div class="topbar__user-role"><?= h($adminRole) ?></div>
                </div>
                <div class="topbar__avatar" aria-hidden="true"><?= h(sams_admin_dashboard_initials((string) ($currentUser['first_name'] ?? ''), (string) ($currentUser['last_name'] ?? ''))) ?></div>
            </div>
        </header>

        <main class="page announcements-page" role="main">
            <section class="hero-card">
                <div class="hero-kicker">Broadcast center</div>
                <h1>Create & Manage Announcements</h1>
                <p>Publish updates to students, supervisors, or everyone from one consistent admin workspace.</p>
            </section>

            <?php if (!empty($_GET['created'])): ?>
                <div class="page-alert page-alert--success">✓ Announcement published successfully and visible to users in real time.</div>
            <?php endif; ?>

            <?php if (!empty($_POST) && empty($_GET['created'])): ?>
                <div class="page-alert page-alert--error">Please complete both the title and message before publishing.</div>
            <?php endif; ?>

            <div class="announcements-grid">
                <section class="panel-card" aria-labelledby="announcement-form-title">
                    <div class="panel-header">
                        <div class="section-kicker">Compose</div>
                        <div class="panel-title" id="announcement-form-title">New announcement</div>
                        <div class="panel-subtitle">Use the same clean admin layout as the rest of the system.</div>
                    </div>

                    <form method="post">
                        <?= sams_csrf_input_field() ?>

                        <div class="form-group">
                            <label class="form-label" for="title">Announcement Title *</label>
                            <input type="text" id="title" name="title" class="form-input" placeholder="e.g., Meeting Schedule Updated" required />
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="body">Message *</label>
                            <textarea id="body" name="body" class="form-textarea" rows="8" placeholder="Enter your announcement message here..." required></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="audience">Audience *</label>
                            <select id="audience" name="audience" class="form-select">
                                <option value="students">Students Only</option>
                                <option value="supervisors">Supervisors Only</option>
                                <option value="all">All (Students & Supervisors)</option>
                            </select>
                        </div>

                        <button type="submit" class="btn-primary">
                            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="M10 2v16M2 10h16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Publish Announcement
                        </button>
                    </form>
                </section>

                <section class="panel-card" aria-labelledby="recent-announcements-title">
                    <div class="panel-header">
                        <div class="section-kicker">History</div>
                        <div class="panel-title" id="recent-announcements-title">Recent announcements</div>
                        <div class="panel-subtitle">Latest <?= (int) count($announcements) ?> announcements, up to 50 entries.</div>
                    </div>

                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">📢 No announcements yet. Create one above to get started!</div>
                    <?php else: ?>
                        <div class="announcement-list">
                            <?php foreach ($announcements as $announcement): ?>
                                <?php
                                    $audienceLabel = match ((string) ($announcement['audience'] ?? '')) {
                                        'students' => 'Students',
                                        'supervisors' => 'Supervisors',
                                        'all' => 'All Users',
                                        default => (string) ($announcement['audience'] ?? ''),
                                    };
                                    $createdAt = (string) ($announcement['created_at'] ?? '');
                                    $createdAtLabel = $createdAt !== '' ? date('M d, Y g:i A', strtotime($createdAt)) : 'Unknown date';
                                ?>
                                <article class="announcement-item">
                                    <div class="announcement-title"><?= h((string) ($announcement['title'] ?? 'Untitled')) ?></div>
                                    <div class="announcement-meta">
                                        <span class="announcement-badge"><?= h($audienceLabel) ?></span>
                                        <span><?= h($createdAtLabel) ?></span>
                                        <?php if (empty($announcement['is_active'])): ?>
                                            <span style="color:#999;font-style:italic;">(Inactive)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="announcement-body"><?= h((string) ($announcement['body'] ?? '')) ?></div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</div>
</body>
</html>