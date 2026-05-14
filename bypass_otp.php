<?php
require_once __DIR__ . '/config/bootstrap.php';
$pdo = sams_pdo();

// Disable OTP requirement for test student
$stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = 0 WHERE email = 'test.student@sams.local'");
$stmt->execute();

// Or just create the verification record to bypass
$userStmt = $pdo->query("SELECT user_id FROM users WHERE email = 'test.student@sams.local'");
$userId = $userStmt->fetchColumn();

if ($userId) {
    // Set email as verified
    $stmt = $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE user_id = :uid");
    $stmt->execute(['uid' => $userId]);
    echo "✓ Test student OTP bypassed\n";
}
?>
