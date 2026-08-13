<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

try {
    $pdo = sams_pdo();
    $stmt = $pdo->query("
        SELECT s.student_id, s.student_id_number, s.nfc_uid, u.first_name, u.last_name 
        FROM students s
        INNER JOIN users u ON u.user_id = s.user_id
        WHERE u.first_name LIKE '%pogi%' OR s.student_id_number = '2021-2'
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "=== Registered NFC details for pogi ===\n";
    if ($row) {
        print_r($row);
    } else {
        echo "No student found.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
