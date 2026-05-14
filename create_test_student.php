<?php
require_once __DIR__ . '/config/bootstrap.php';
$pdo = sams_pdo();

// Create a test user and student
$hash = password_hash('teststudent', PASSWORD_DEFAULT);

// Insert user
$stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, first_name, last_name, is_active) VALUES (:email, :hash, 'student', 'Test', 'Student', 1)");
$stmt->execute(['email' => 'test.student@sams.local', 'hash' => $hash]);

$userId = $pdo->lastInsertId();

// Get an application_id for the student
$appStmt = $pdo->query("SELECT application_id FROM applications LIMIT 1");
$appId = $appStmt->fetchColumn();

if ($appId) {
    // Insert into students table
    $studentStmt = $pdo->prepare("INSERT INTO students (user_id, student_id_number) VALUES (:uid, :num)");
    $studentStmt->execute(['uid' => $userId, 'num' => 'TEST001']);
    
    echo "✓ Test student created: test.student@sams.local / teststudent\n";
} else {
    echo "✗ No application found\n";
}
?>
