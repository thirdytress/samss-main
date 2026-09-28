<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();

// Find target users
$targets = ['martyn', 'joseph', 'seloterio', 'pogi.lord@yahoo.com'];

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.email, s.student_id 
     FROM users u 
     LEFT JOIN students s ON s.user_id = u.user_id 
     WHERE u.email IN (" . implode(',', array_fill(0, count($targets), '?')) . ")
        OR u.first_name IN (" . implode(',', array_fill(0, count($targets), '?')) . ")"
);

$allTargets = array_merge($targets, $targets);
$stmt->execute($allTargets);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($users)) {
    echo "No matching users found.\n";
    exit(0);
}

echo "Found " . count($users) . " user(s) to delete:\n";
foreach ($users as $u) {
    echo "  - user_id=" . $u['user_id'] . ", email=" . $u['email'] . ", student_id=" . ($u['student_id'] ?? 'NULL') . "\n";
}

echo "\nDeleting related records...\n";

foreach ($users as $user) {
    $userId = (int) $user['user_id'];
    $studentId = (int) ($user['student_id'] ?? 0);

    // Delete related records in order (respecting FK)
    if ($studentId > 0) {
        // attendance_logs
        $pdo->prepare("DELETE FROM attendance_logs WHERE application_id IN (SELECT application_id FROM applications WHERE student_id = ?)")->execute([$studentId]);
        
        // duty_schedules
        $pdo->prepare("DELETE FROM duty_schedules WHERE application_id IN (SELECT application_id FROM applications WHERE student_id = ?)")->execute([$studentId]);
        
        // applications
        $pdo->prepare("DELETE FROM applications WHERE student_id = ?")->execute([$studentId]);
        
        // students
        $pdo->prepare("DELETE FROM students WHERE student_id = ?")->execute([$studentId]);
    }

    // password_reset_tokens
    try {
        $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$userId]);
    } catch (PDOException $e) {
        // table may not exist
    }
    
    // users
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$userId]);
    
    echo "  Deleted user_id={$userId}\n";
}

echo "Done. All records deleted.\n";
