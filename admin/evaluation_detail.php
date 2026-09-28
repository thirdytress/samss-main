<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$evaluationId = (int) ($_GET['evaluation_id'] ?? 0);
if ($evaluationId <= 0) {
    header('Location: evaluation.php');
    exit;
}

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
        s.student_id_number,
        u.first_name,
        u.last_name,
        sp.office_name AS supervisor_office,
        sp.user_id AS supervisor_user_id,
        t.term_name,
        t.term_year
     FROM evaluations e
     INNER JOIN applications a ON a.application_id = e.application_id
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     INNER JOIN supervisors sp ON sp.supervisor_id = e.supervisor_id
     INNER JOIN terms t ON t.term_id = e.term_id
     WHERE e.evaluation_id = :evaluation_id
     LIMIT 1'
);
$statement->execute(['evaluation_id' => $evaluationId]);
$eval = $statement->fetch(PDO::FETCH_ASSOC);

if (!$eval) {
    header('Location: evaluation.php');
    exit;
}

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$studentName = trim((string) ($eval['first_name'] ?? '') . ' ' . (string) ($eval['last_name'] ?? '')) ?: 'Unassigned Student';
$avg = ((float)($eval['performance_rating']??0) + (float)($eval['reliability_rating']??0) + (float)($eval['professionalism_rating']??0)) / 3;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Evaluation Detail — <?php echo h($studentName); ?></title>
  <link rel="stylesheet" href="../assets/css/sams-shell.css" />
  <link rel="stylesheet" href="../assets/css/sams-theme-admin.css" />
  <style>body{font-family:Inter,Arial,Helvetica,sans-serif;background:var(--color-bg-app);color:var(--color-heading);}
  .card{background:#fff;padding:20px;border-radius:12px;border:1px solid var(--color-border);max-width:900px;margin:24px auto}
  .meta{color:#6b7280}
  .btn{display:inline-block;padding:8px 12px;border-radius:8px;border:0;background:var(--gradient-brand);color:#fff;text-decoration:none}
  </style>
</head>
<body>
  <div class="card">
    <h2>Evaluation Detail</h2>
    <p class="meta">Student: <strong><?php echo h($studentName); ?></strong> — <em><?php echo h((string)($eval['student_id_number'] ?? '')); ?></em></p>
    <p class="meta">Office: <?php echo h((string)($eval['preferred_office'] ?? '')); ?> • Term: <?php echo h((string)($eval['term_name'] ?? '') . ' ' . (string)($eval['term_year'] ?? '')); ?></p>
    <hr />
    <h3>Ratings</h3>
    <ul>
      <li>Performance: <?php echo (int)($eval['performance_rating'] ?? 0); ?></li>
      <li>Reliability: <?php echo (int)($eval['reliability_rating'] ?? 0); ?></li>
      <li>Professionalism: <?php echo (int)($eval['professionalism_rating'] ?? 0); ?></li>
      <li>Average: <?php echo number_format($avg,1); ?>/5</li>
    </ul>
    <h3>Supervisor Comments</h3>
    <div style="white-space:pre-wrap;border:1px solid var(--color-border);padding:12px;border-radius:8px;background:#fbfdff"><?php echo nl2br(h((string)($eval['comments'] ?? ''))); ?></div>
    <p class="meta" style="margin-top:12px">Submitted: <?php echo h((string)($eval['submitted_at'] ?? '')); ?></p>

    <div style="margin-top:16px">
      <a class="btn" href="evaluation.php">Back to Evaluations</a>
    </div>
  </div>
</body>
</html>
