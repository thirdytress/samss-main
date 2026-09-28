<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "=== USER / STUDENT / APPLICATIONS FOR SELOTERIO ===\n";
$stmt = $pdo->query('
    SELECT u.user_id, u.first_name, u.last_name, u.email, u.role, u.is_active,
           s.student_id, s.student_id_number, s.nfc_uid,
           a.application_id, a.term_id, a.status as app_status, a.preferred_office
    FROM users u
    LEFT JOIN students s ON s.user_id = u.user_id
    LEFT JOIN applications a ON a.student_id = s.student_id
    WHERE u.first_name LIKE "%seloterio%" OR u.last_name LIKE "%seloterio%" 
       OR u.first_name LIKE "%martyn%" OR u.last_name LIKE "%martyn%"
');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($users);

foreach ($users as $u) {
    $sid = $u['student_id'] ?? null;
    $aid = $u['application_id'] ?? null;
    if ($aid) {
        echo "=== AVAILABILITY for application_id $aid ===\n";
        $avStmt = $pdo->prepare('SELECT * FROM availability WHERE application_id = :aid ORDER BY day_of_week, start_time');
        $avStmt->execute(['aid' => $aid]);
        print_r($avStmt->fetchAll(PDO::FETCH_ASSOC));

        echo "=== DUTY SCHEDULES for application_id $aid ===\n";
        $dsStmt = $pdo->prepare('SELECT * FROM duty_schedules WHERE application_id = :aid ORDER BY day_of_week, start_time');
        $dsStmt->execute(['aid' => $aid]);
        print_r($dsStmt->fetchAll(PDO::FETCH_ASSOC));

        echo "=== ATTENDANCE LOGS for application_id $aid ===\n";
        $attStmt = $pdo->prepare('SELECT * FROM attendance_logs WHERE application_id = :aid ORDER BY created_at DESC');
        $attStmt->execute(['aid' => $aid]);
        print_r($attStmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
