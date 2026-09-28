<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$email = $argv[1] ?? '';
if (!$email) {
    echo "Usage: php find_user_duplicates.php user@example.com\n";
    exit(1);
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    echo "No users found with email: $email\n";
    exit(0);
}

echo "Found " . count($rows) . " user(s) with email: $email\n\n";
foreach ($rows as $r) {
    echo "user_id: " . ($r['user_id'] ?? $r['id'] ?? 'N/A') . "\n";
    echo "email: " . ($r['email'] ?? '') . "\n";
    echo "name: " . ($r['name'] ?? '') . "\n";
    echo "role: " . ($r['role'] ?? '') . "\n";
    echo "created_at: " . ($r['created_at'] ?? '') . "\n";
    echo "is_active: " . ($r['is_active'] ?? '') . "\n";
    echo "----\n";
}

// Check linked students
$userIds = array_map(function($a){ return (int)($a['user_id'] ?? $a['id'] ?? 0); }, $rows);
$in = implode(',', array_map('intval', array_unique($userIds)));
if ($in) {
    $s = $pdo->query("SELECT * FROM students WHERE user_id IN ($in)");
    $students = $s->fetchAll(PDO::FETCH_ASSOC);
    echo "\nLinked students: " . count($students) . "\n";
    foreach ($students as $st) {
        echo "student_id: " . ($st['student_id'] ?? '') . ", user_id: " . ($st['user_id'] ?? '') . ", is_enrolled: " . ($st['is_enrolled'] ?? '') . "\n";
    }
}

// Check applications for those student_ids
$studentIds = array_map(function($a){ return isset($a['student_id']) ? (int)$a['student_id'] : 0; }, $students ?? []);
$in2 = implode(',', array_map('intval', array_unique(array_filter($studentIds))));
if ($in2) {
    $a = $pdo->query("SELECT * FROM applications WHERE student_id IN ($in2)");
    $apps = $a->fetchAll(PDO::FETCH_ASSOC);
    echo "\nApplications: " . count($apps) . "\n";
    foreach ($apps as $ap) {
        echo "application_id: " . ($ap['application_id'] ?? '') . ", student_id: " . ($ap['student_id'] ?? '') . ", preferred_office: " . ($ap['preferred_office'] ?? '') . "\n";
    }
}

// Also search users table for name/email similar
$like = $pdo->prepare('SELECT email, user_id, name, role, created_at FROM users WHERE email LIKE :like OR name LIKE :like');
$like->execute(['like' => '%' . substr($email, 0, strpos($email, '@')) . '%']);
$likeRows = $like->fetchAll(PDO::FETCH_ASSOC);
if (!empty($likeRows)) {
    echo "\nSimilar users: \n";
    foreach ($likeRows as $lr) {
        echo json_encode($lr) . "\n";
    }
}

echo "\nDone.\n";
