<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();
$userIdColumn = sams_first_existing_column($pdo, 'users', ['user_id', 'id']);
$emails = ['Martynjosephseloterio@gmail.com', 'pogi.lord@yahoo.com'];

foreach ($emails as $email) {
    $stmt = $pdo->prepare('SELECT ' . $userIdColumn . ' AS user_id FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $uid = (int) $user['user_id'];
        
        // Delete related records in order
        $pdo->prepare('DELETE FROM applications WHERE student_id IN (SELECT student_id FROM students WHERE user_id = :uid)')->execute(['uid' => $uid]);
        $pdo->prepare('DELETE FROM students WHERE user_id = :uid')->execute(['uid' => $uid]);
        $pdo->prepare('DELETE FROM supervisors WHERE user_id = :uid')->execute(['uid' => $uid]);
        $pdo->prepare('DELETE FROM users WHERE ' . $userIdColumn . ' = :uid')->execute(['uid' => $uid]);
        
        echo "✓ Deleted: $email\n";
    } else {
        echo "✗ Not found: $email\n";
    }
}

echo "\nCleanup complete. You can now re-apply with those accounts.\n";
?>
