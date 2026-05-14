<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

$supervisor = $pdo->query("SELECT s.supervisor_id, s.user_id, s.office_name, u.email, u.first_name, u.last_name FROM supervisors s LEFT JOIN users u ON u.user_id = s.user_id WHERE u.email = 'martynjosephseloterio@gmail.com' OR u.email = 'supervisor@sams.local' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "== supervisors ==\n";
foreach ($supervisor as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== martyn / linked applications ==\n";
$martynApps = $pdo->query("SELECT a.application_id, a.student_id, a.preferred_office, a.status, s.student_id_number, s.user_id FROM applications a JOIN students s ON s.student_id = a.student_id WHERE s.student_id_number = '2021-1' OR s.user_id = (SELECT user_id FROM users WHERE email='martynjosephseloterio@gmail.com' LIMIT 1)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($martynApps as $row) {
    echo json_encode($row) . "\n";
}

echo "\n== martyn duty schedules ==\n";
$martynDuty = $pdo->query("SELECT ds.duty_id, ds.application_id, ds.office_name, ds.term_id, ds.status FROM duty_schedules ds WHERE ds.application_id IN (SELECT a.application_id FROM applications a JOIN students s ON s.student_id=a.student_id WHERE s.student_id_number='2021-1' OR s.user_id = (SELECT user_id FROM users WHERE email='martynjosephseloterio@gmail.com' LIMIT 1)) ORDER BY ds.duty_id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
foreach ($martynDuty as $row) {
    echo json_encode($row) . "\n";
}
