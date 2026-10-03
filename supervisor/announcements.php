<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$stmt = $pdo->prepare("SELECT id, title, body, audience, is_active, created_at FROM announcements WHERE is_active = 1 AND audience IN ('supervisors','all') ORDER BY created_at DESC LIMIT 100");
$stmt->execute();
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Announcements — Supervisor</title>
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
  <style>
    body{font-family:Inter,Arial,Helvetica,sans-serif;background:var(--color-bg-app)}
    .container{max-width:900px;margin:36px auto;padding:0 16px}
    .ann{background:#fff;border:1px solid var(--color-border);padding:16px;border-radius:10px;margin-bottom:12px}
    .ann h3{margin:0 0 6px}
    .ann .meta{color:#6b7280;font-size:13px;margin-bottom:8px}
  </style>
</head>
<body>
  <div class="container">
    <h1>Announcements</h1>
    <?php if (empty($announcements)): ?>
      <p>No announcements at this time.</p>
    <?php else: ?>
      <?php foreach ($announcements as $a): ?>
        <article class="ann">
          <h3><?php echo h($a['title']); ?></h3>
          <div class="meta"><?php echo h($a['created_at']); ?> • Audience: <?php echo h($a['audience']); ?></div>
          <div class="body"><?php echo nl2br(h($a['body'])); ?></div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <script src="../assets/js/admin-notifications.js?v=20260922"></script>
</body>
</html>
