<?php
require_once 'config/database.php';

$pdo = sams_pdo();

// Check today's attendance logs
echo "=== TODAY'S ATTENDANCE LOGS ===\n";
$stmt = $pdo->query(
    "SELECT COUNT(*) as total FROM attendance_logs WHERE DATE(created_at) = CURDATE()"
);
$count = $stmt->fetchColumn();
echo "Total attendance records today: " . $count . "\n\n";

if ($count > 0) {
    $stmt2 = $pdo->query(
        "SELECT al.log_id, al.status, al.clock_in_time, al.clock_out_time, 
                a.preferred_office, s.student_id, u.first_name, u.last_name
         FROM attendance_logs al
         LEFT JOIN applications a ON a.application_id = al.application_id
         LEFT JOIN students s ON s.student_id = a.student_id
         LEFT JOIN users u ON u.user_id = s.user_id
         WHERE DATE(al.created_at) = CURDATE()
         LIMIT 5"
    );
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "Sample attendance records:\n";
    foreach ($rows as $row) {
        echo "- " . ($row['first_name'] ?? 'N/A') . " " . ($row['last_name'] ?? 'N/A') 
             . " at " . ($row['preferred_office'] ?? 'N/A')
             . " - Status: " . ($row['status'] ?? 'N/A') . "\n";
    }
}

// Check metrics
echo "\n=== METRICS ===\n";
$stmt3 = $pdo->query(
    "SELECT COUNT(*) FROM attendance_logs 
     WHERE DATE(created_at) = CURDATE() 
     AND (status = 'present' OR status = 'active' OR status = 'late') 
     AND clock_out_time IS NULL"
);
$activeNow = $stmt3->fetchColumn();
echo "Active now (clock in, no clock out): " . $activeNow . "\n";

$stmt4 = $pdo->query(
    "SELECT COUNT(*) FROM attendance_logs 
     WHERE DATE(created_at) = CURDATE() 
     AND status IN ('completed', 'present') 
     AND clock_out_time IS NOT NULL"
);
$completed = $stmt4->fetchColumn();
echo "Completed today: " . $completed . "\n";

// Check applications count
echo "\n=== APPLICATIONS ===\n";
$stmt5 = $pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'pending'");
$pending = $stmt5->fetchColumn();
echo "Pending applications: " . $pending . "\n";

// Check students
echo "\n=== STUDENTS ===\n";
$stmt6 = $pdo->query("SELECT COUNT(*) FROM students WHERE is_enrolled = 1");
$active = $stmt6->fetchColumn();
echo "Active students: " . $active . "\n";
