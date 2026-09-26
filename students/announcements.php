<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'student') {
  header('Location: ../login.php');
  exit;
}

$pdo = sams_pdo();

// Fetch recent announcements for students
$stmt = $pdo->prepare(
  'SELECT a.id, a.title, a.body, a.created_at, 
          EXISTS(SELECT 1 FROM announcement_reads r WHERE r.announcement_id = a.id AND r.user_id = :user_id) AS is_read
   FROM announcements a
   WHERE a.is_active = 1 AND a.audience IN ("students","all")
   ORDER BY a.created_at DESC'
);
$stmt->execute(['user_id' => (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0)]);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function escape($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Announcements</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    body{font-family:Inter,Arial,sans-serif;background:#f7fbff;padding:28px;color:#102030}
    .card{background:#fff;border-radius:12px;padding:18px;border:1px solid #eef3fb;max-width:900px;margin:0 auto}
    .announcement{border-left:4px solid #155dfc;padding:14px;border-radius:8px;margin-bottom:12px;background:#f0f6ff}
    .announcement--yellow{background:#fff8e8;border-left-color:#ffb81c}
    .announcement--green{background:#f0fdf4;border-left-color:#00c950}
    .announcement__time{font-weight:700;color:#155dfc;margin-bottom:6px}
    .announcement__title{font-weight:900;font-size:18px;color:#0b2a66}
    .announcement__body{color:#344050;margin-top:6px}
    .read{opacity:0.6}
    .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
    .back{font-weight:700;color:#155dfc;text-decoration:none}
  </style>
<link rel="stylesheet" href="../assets/css/sams-dark-mode.css?v=20260926" />
</head>
<body>
  <div class="card">
    <div class="top">
      <h1 style="margin:0">Announcements</h1>
      <a class="back" href="dashboard.php">← Back to dashboard</a>
    </div>

    <div id="anns">
      <?php if (empty($announcements)): ?>
        <div style="padding:20px;color:#666">No announcements yet.</div>
      <?php else: ?>
        <?php foreach ($announcements as $i => $a): ?>
          <?php $cls = $i % 3 === 0 ? 'announcement' : ($i % 3 === 1 ? 'announcement announcement--yellow' : 'announcement announcement--green'); ?>
          <div class="<?php echo $cls; ?> <?php echo ((int)($a['is_read'] ?? 0) ? 'read' : ''); ?>" data-id="<?php echo (int)$a['id']; ?>">
            <div class="announcement__time"><?php echo escape(date('M j, Y g:i A', strtotime((string)$a['created_at']))); ?></div>
            <div class="announcement__title"><?php echo escape($a['title']); ?></div>
            <div class="announcement__body"><?php echo nl2br(escape($a['body'])); ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // Clicking an announcement marks it as read (idempotent)
    document.querySelectorAll('.announcement').forEach(function(el){
      el.addEventListener('click', function(){
        var id = parseInt(el.getAttribute('data-id'), 10);
        fetch('../api/announcements/mark_read.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.SAMS_CSRF || '' },
          body: JSON.stringify({ announcement_id: id })
        }).then(function(){ el.classList.add('read'); }).catch(function(){ /* ignore */ });
      });
    });
  </script>
<script src="../assets/js/sams-theme.js?v=20260926"></script>
</body>
</html>
