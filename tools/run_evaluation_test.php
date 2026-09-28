<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

// Find a supervisor and an eligible application
$supervisor = $pdo->query('SELECT user_id, supervisor_id, office_name FROM supervisors LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$supervisor) {
    echo "No supervisor found, aborting test.\n";
    exit(1);
}
$office = $supervisor['office_name'];
$termId = (int) ($pdo->query('SELECT term_id FROM terms WHERE is_active = 1 ORDER BY term_id DESC LIMIT 1')->fetchColumn() ?: 0);
if ($termId <= 0) {
    echo "No active term found, aborting test.\n";
    exit(1);
}

$stmt = $pdo->prepare('SELECT a.application_id FROM applications a WHERE a.term_id = :term_id AND a.preferred_office = :office_name AND a.status = "approved" LIMIT 1');
$stmt->execute(['term_id' => $termId, 'office_name' => $office]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) { echo "No eligible application for office {$office}\n"; exit(1); }
$appId = (int) $app['application_id'];

// Insert test evaluation
$ins = $pdo->prepare('INSERT INTO evaluations (application_id, term_id, supervisor_id, performance_rating, reliability_rating, professionalism_rating, comments, submitted_at) VALUES (:application_id, :term_id, :supervisor_id, 5,5,5,:comments,NOW())');
$ins->execute(['application_id'=>$appId, 'term_id'=>$termId, 'supervisor_id'=>$supervisor['supervisor_id'], 'comments'=>'TEST: automated evaluation']);

echo "Inserted evaluation for application_id={$appId}\n";

// Verify
$chk = $pdo->prepare('SELECT COUNT(*) FROM evaluations WHERE application_id = :aid AND term_id = :tid');
$chk->execute(['aid'=>$appId, 'tid'=>$termId]);
$count = (int) $chk->fetchColumn();
echo "Evaluation count for this application/term: {$count}\n";
