<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "Users with role LIKE '%assist%':\n";
$cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
$nameExpr = 'email';
if (in_array('name', $cols, true)) {
    $nameExpr = 'name';
} elseif (in_array('first_name', $cols, true) && in_array('last_name', $cols, true)) {
    $nameExpr = "CONCAT(first_name, ' ', last_name) AS name";
} elseif (in_array('full_name', $cols, true)) {
    $nameExpr = 'full_name AS name';
}

$stmt = $pdo->query("SELECT user_id, email, $nameExpr, role, is_active, created_at FROM users WHERE role LIKE '%assist%' OR role LIKE '%assistant%' ORDER BY created_at DESC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r) . "\n";
}

echo "\nUsers with role 'student' and is_active=1 (recent 100):\n";
$stmt2 = $pdo->query("SELECT user_id, email, $nameExpr, role, is_active, created_at FROM users WHERE role = 'student' AND is_active = 1 ORDER BY created_at DESC LIMIT 100");
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}

echo "\nStudents table entries for emails matching users (join):\n";
$stmt3 = $pdo->query("SELECT s.*, u.email AS user_email FROM students s LEFT JOIN users u ON u.user_id = s.user_id ORDER BY COALESCE(s.student_id, 0) DESC LIMIT 200");
foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r) . "\n";
}

// Check for duplicate student_code or duplicate user associations
echo "\nDuplicate student_code occurrences:\n";
$studentCols = $pdo->query('SHOW COLUMNS FROM students')->fetchAll(PDO::FETCH_COLUMN);
$studentCodeCol = 'student_code';
if (in_array('student_id_number', $studentCols, true)) {
    $studentCodeCol = 'student_id_number';
} elseif (in_array('student_code', $studentCols, true)) {
    $studentCodeCol = 'student_code';
}
$dup = $pdo->query("SELECT $studentCodeCol AS student_code, COUNT(*) c FROM students GROUP BY $studentCodeCol HAVING c > 1");
foreach ($dup->fetchAll(PDO::FETCH_ASSOC) as $d) {
    echo json_encode($d) . "\n";
}

echo "\nUsers with identical email duplicates:\n";
$dup2 = $pdo->query("SELECT email, COUNT(*) c FROM users GROUP BY email HAVING c > 1");
foreach ($dup2->fetchAll(PDO::FETCH_ASSOC) as $d) {
    echo json_encode($d) . "\n";
}

echo "\nDone.\n";
