<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "=== RECENT ATTENDANCE LOGS ===\n";
$stmt = $pdo->query('SELECT log_id, application_id, duty_id, clock_in_time, clock_out_time, status, created_at FROM attendance_logs ORDER BY log_id DESC LIMIT 20');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) {
    $dur = 'N/A';
    if(!empty($r['clock_in_time']) && !empty($r['clock_out_time'])) {
        $inTs = strtotime($r['clock_in_time']);
        $outTs = strtotime($r['clock_out_time']);
        if($outTs > $inTs) $dur = round(($outTs-$inTs)/60,1) . ' min';
        else $dur = 'SAME OR REVERSED: in='.$r['clock_in_time'].' out='.$r['clock_out_time'];
    } elseif(!empty($r['clock_in_time'])) {
        $dur = 'No clock_out yet';
    } else {
        $dur = 'No clock_in';
    }
    echo "log_id={$r['log_id']} app={$r['application_id']} duty={$r['duty_id']} status={$r['status']}\n";
    echo "  in={$r['clock_in_time']}  out={$r['clock_out_time']}\n";
    echo "  duration=[$dur]\n";
    echo "  created_at={$r['created_at']}\n\n";
}

echo "\n=== STUDENTS WITH NFC (for Thirdy / Seloterio) ===\n";
$stmt2 = $pdo->query("SELECT s.student_id, s.nfc_uid, u.first_name, u.last_name FROM students s INNER JOIN users u ON u.user_id = s.user_id WHERE u.first_name LIKE '%thirdy%' OR u.last_name LIKE '%seloterio%' OR u.first_name LIKE '%martyn%'");
foreach($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "student_id={$r['student_id']} nfc={$r['nfc_uid']} name={$r['first_name']} {$r['last_name']}\n";
}
