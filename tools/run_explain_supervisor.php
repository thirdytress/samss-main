<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();

$supervisorOffice = null;
// pick first supervisor office for CLI testing
$row = $pdo->query('SELECT s.office_name FROM supervisors s LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$supervisorOffice = (string) ($row['office_name'] ?? '');
$currentDay = date('l');
$activeTermId = (int) ($pdo->query("SELECT COALESCE(MAX(term_id), 0) FROM terms WHERE start_date <= CURDATE() AND end_date >= CURDATE()")->fetchColumn() ?: 0);

$sql = "SELECT u.first_name, u.last_name, s.student_id AS student_code, a.preferred_office AS office_name,
            al.clock_in_time AS time_in, al.clock_out_time AS time_out, al.status, al.late_minutes, ds.start_time
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     LEFT JOIN students s ON s.student_id = a.student_id
     LEFT JOIN users u ON u.user_id = s.user_id
     LEFT JOIN (
         SELECT al1.* FROM attendance_logs al1
         INNER JOIN (
             SELECT application_id, duty_id, MAX(log_id) AS max_log_id
             FROM attendance_logs
             GROUP BY application_id, duty_id
         ) lm ON lm.max_log_id = al1.log_id
     ) al ON al.application_id = a.application_id AND al.duty_id = ds.duty_id
     WHERE ds.day_of_week = '" . $currentDay . "'
         AND ds.status = 'accepted'
         AND ds.term_id = " . $activeTermId . "
         AND a.preferred_office = " . $pdo->quote($supervisorOffice) . "
     ORDER BY ds.start_time ASC, al.log_id DESC";

echo "Running EXPLAIN for supervisor attendance query:\n\n";

$stmt = $pdo->query('EXPLAIN ' . $sql);
$plan = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($plan as $row) {
    echo implode(' | ', array_map(function($v){ return (string)$v; }, $row)) . "\n";
}

echo "\nNow running CLI smoke request to supervisor/attendance_data.php:\n\n";

// Run the attendance_data.php CLI path
passthru('php "' . __DIR__ . '/../supervisor/attendance_data.php"');

exit(0);
