<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$email = 'martynjosephseloterio@gmail.com';
$pdo = sams_pdo();

// First, find the user
$stmt = $pdo->prepare('SELECT user_id, email, role FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "User not found with email: $email\n";
    exit(1);
}

echo "Found user:\n";
echo "  user_id: " . $user['user_id'] . "\n";
echo "  email: " . $user['email'] . "\n";
echo "  role: " . $user['role'] . "\n";

// Find related student record
$studentStmt = $pdo->prepare('SELECT student_id FROM students WHERE user_id = ?');
$studentStmt->execute([$user['user_id']]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

if ($student) {
    echo "  student_id: " . $student['student_id'] . "\n";
}

echo "\nProceeding to delete...\n";

$pdo->beginTransaction();

try {
    // Delete related records if student
    if ($student) {
        $student_id = $student['student_id'];
        
        // Delete availability records
        $pdo->prepare('DELETE FROM availability WHERE application_id IN (SELECT application_id FROM applications WHERE student_id = ?)')->execute([$student_id]);
        
        // Delete applications
        $pdo->prepare('DELETE FROM applications WHERE student_id = ?')->execute([$student_id]);
        
        // Delete student
        $pdo->prepare('DELETE FROM students WHERE student_id = ?')->execute([$student_id]);
    }
    
    // Delete password reset tokens
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$user['user_id']]);
    
    // Delete user
    $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$user['user_id']]);
    
    $pdo->commit();
    
    echo "✓ User and all related records deleted successfully.\n";
    
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "✗ Error deleting user: " . $e->getMessage() . "\n";
    exit(1);
}
