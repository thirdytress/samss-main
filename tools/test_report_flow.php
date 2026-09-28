<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();

$supervisor = $pdo->query("SELECT s.user_id, s.office_name FROM supervisors s JOIN users u ON u.user_id = s.user_id WHERE u.email = 'supervisor@sams.local' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$supervisor) {
    die("Supervisor row not found\n");
}
$office = (string)($supervisor['office_name'] ?? '');

$student = $pdo->query("SELECT a.application_id, s.student_id_number, s.student_id, s.user_id FROM applications a JOIN students s ON s.student_id = a.student_id WHERE a.preferred_office = 'ITSO' AND s.student_id_number = '2021-1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    die("Martyn application not found\n");
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS student_reports (
        report_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NULL,
        duty_id INT NULL,
        student_code VARCHAR(128) NULL,
        reporter_id INT NOT NULL,
        title VARCHAR(255) NULL,
        notes TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'open',
        is_new TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$before = (int) $pdo->query("SELECT COUNT(*) FROM student_reports WHERE status = 'open' AND is_new = 1")->fetchColumn();

$insert = $pdo->prepare(
    "INSERT INTO student_reports (application_id, duty_id, student_code, reporter_id, title, notes, status, is_new)
     VALUES (:application_id, :duty_id, :student_code, :reporter_id, :title, :notes, 'open', 1)"
);
$insert->execute([
    'application_id' => (int)$student['application_id'],
    'duty_id' => null,
    'student_code' => (string)$student['student_id_number'],
    'reporter_id' => (int)$supervisor['user_id'],
    'title' => 'Test Report',
    'notes' => 'Automated test report for Martyn / ITSO to verify admin notifications.',
]);

$reportId = (int)$pdo->lastInsertId();
$after = (int) $pdo->query("SELECT COUNT(*) FROM student_reports WHERE status = 'open' AND is_new = 1")->fetchColumn();

$rows = $pdo->query("SELECT report_id, application_id, student_code, reporter_id, title, status, is_new, created_at FROM student_reports WHERE report_id = {$reportId} LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);

echo "office={$office}\n";
echo "before={$before}\n";
echo "after={$after}\n";
echo json_encode($rows[0] ?? [], JSON_UNESCAPED_SLASHES) . "\n";
