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
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Announcements – SAMS Student Portal | NU Lipa</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
  <style>
    body {
      background: var(--grad-page, #f8fafc);
      padding: 32px 20px;
      color: var(--color-body, #334155);
      min-height: 100vh;
    }
    .announcements-container {
      max-width: 860px;
      margin: 0 auto;
    }
    .announcements-card {
      background: #ffffff;
      border-radius: var(--radius-card, 16px);
      padding: 28px 32px;
      border: 1px solid var(--color-border, #e2e8f0);
      box-shadow: var(--shadow-card, 0 1px 3px rgba(15,23,42,0.06));
    }
    .top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 24px;
      padding-bottom: 18px;
      border-bottom: 1px solid var(--color-border, #e2e8f0);
    }
    .top h1 {
      font-family: var(--font-display, 'Poppins', sans-serif);
      font-size: 24px;
      font-weight: 700;
      color: var(--nu-navy, #003087);
      margin: 0;
    }
    .back {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      font-size: 14px;
      color: var(--nu-navy, #003087);
      text-decoration: none;
      padding: 8px 14px;
      border-radius: var(--radius-md, 10px);
      background: var(--nu-navy-subtle, #eff4fc);
      transition: all 0.18s ease;
    }
    .back:hover {
      background: var(--nu-navy-pale, #dbe6f8);
      color: var(--nu-navy-dark, #00205b);
      transform: translateX(-2px);
    }
    .announcement {
      border-left: 4px solid var(--nu-navy, #003087);
      padding: 18px 20px;
      border-radius: 12px;
      margin-bottom: 16px;
      background: #ffffff;
      border-top: 1px solid var(--neutral-200, #e2e8f0);
      border-right: 1px solid var(--neutral-200, #e2e8f0);
      border-bottom: 1px solid var(--neutral-200, #e2e8f0);
      box-shadow: 0 1px 2px rgba(15,23,42,0.04);
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .announcement:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md, 0 4px 8px -2px rgba(15,23,42,0.08));
    }
    .announcement--yellow {
      border-left-color: var(--nu-gold, #ffb81c);
      background: #fffdf8;
    }
    .announcement--green {
      border-left-color: var(--color-success, #10b981);
      background: #fcfdfd;
    }
    .announcement__header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
    }
    .announcement__time {
      font-size: 12px;
      font-weight: 600;
      color: var(--color-muted, #64748b);
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .announcement__status {
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 2px 8px;
      border-radius: 9999px;
    }
    .announcement__status--unread {
      background: var(--nu-gold-light, #fff7e6);
      color: var(--nu-gold-dark, #b37b00);
      border: 1px solid var(--nu-gold-subtle, #fff2d1);
    }
    .announcement__title {
      font-family: var(--font-display, 'Poppins', sans-serif);
      font-weight: 700;
      font-size: 17px;
      color: var(--color-heading, #0f172a);
      margin-bottom: 8px;
      line-height: 1.35;
    }
    .announcement__body {
      color: var(--color-body, #334155);
      font-size: 14px;
      line-height: 1.6;
    }
    .read {
      opacity: 0.68;
    }
    .read .announcement__status--unread {
      display: none;
    }
    .empty-state {
      padding: 48px 24px;
      text-align: center;
      color: var(--color-muted, #64748b);
      font-size: 15px;
    }
  </style>
<link rel="stylesheet" href="../assets/css/sams-dark-mode.css?v=20260926" />
</head>
<body>
  <div class="announcements-container">
    <div class="announcements-card">
      <div class="top">
        <h1>Announcements</h1>
        <a class="back" href="dashboard.php">← Back to Dashboard</a>
      </div>

      <div id="anns">
        <?php if (empty($announcements)): ?>
          <div class="empty-state">No announcements yet. Check back later for updates.</div>
        <?php else: ?>
          <?php foreach ($announcements as $i => $a): ?>
            <?php 
              $isRead = (bool)((int)($a['is_read'] ?? 0));
              $cls = $i % 3 === 0 ? 'announcement' : ($i % 3 === 1 ? 'announcement announcement--yellow' : 'announcement announcement--green'); 
            ?>
            <div class="<?php echo $cls; ?> <?php echo $isRead ? 'read' : ''; ?>" data-id="<?php echo (int)$a['id']; ?>">
              <div class="announcement__header">
                <span class="announcement__time">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                  <?php echo escape(date('M j, Y g:i A', strtotime((string)$a['created_at']))); ?>
                </span>
                <?php if (!$isRead): ?>
                  <span class="announcement__status announcement__status--unread">New</span>
                <?php endif; ?>
              </div>
              <div class="announcement__title"><?php echo escape($a['title']); ?></div>
              <div class="announcement__body"><?php echo nl2br(escape($a['body'])); ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
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
