<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || (($currentUser['role'] ?? null) !== 'admin')) {
    header('Location: ../login.php');
    exit;
}

$admin_name = (string) ($currentUser['name'] ?? 'SAMS Admin');
$admin_role = 'SDAO Head';
$department = 'NU Lipa - Student Development and Activities Office';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents | NU SA System</title>
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --border: #e2e8f0;
            --accent: #155dfc;
            --accent-soft: #dbeafe;
            --shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
            color: var(--text);
        }
        .shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
        }
        .card {
            width: 100%;
            max-width: 760px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: var(--shadow);
            padding: 32px;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 18px;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 32px;
            line-height: 1.15;
        }
        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .meta {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            display: grid;
            gap: 8px;
            color: var(--muted);
            font-size: 14px;
        }
        .actions {
            margin-top: 24px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
        }
        .btn-primary {
            background: var(--accent);
            color: #fff;
        }
        .btn-secondary {
            background: #fff;
            color: var(--accent);
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="card" aria-labelledby="page-title">
            <div class="eyebrow">Admin Documents</div>
            <h1 id="page-title">Document center removed</h1>
            <p>
                The document center no longer shows MOA, NOA, or recommendation-letter views.
                This section has been retired from the admin portal.
            </p>

            <div class="meta">
                <div><strong>Signed in as:</strong> <?= htmlspecialchars($admin_name) ?></div>
                <div><strong>Role:</strong> <?= htmlspecialchars($admin_role) ?></div>
                <div><strong>Office:</strong> <?= htmlspecialchars($department) ?></div>
            </div>

            <div class="actions">
                <a class="btn btn-primary" href="dashboard.php">Back to dashboard</a>
                <a class="btn btn-secondary" href="applications.php">View applications</a>
            </div>
        </section>
    </main>
</body>
</html>
