<?php
declare(strict_types=1);
require __DIR__ . '/../config/bootstrap.php';

$email = 'pogi.lord@gmail.com';
$pdo = sams_pdo();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT user_id, email, first_name, last_name, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('User not found.');
    }

    // Find student_id for this user (if any)
    $studentId = 0;
    $sStmt = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1');
    $sStmt->execute(['user_id' => (int) $user['user_id']]);
    $studentId = (int) ($sStmt->fetchColumn() ?: 0);

    $applicationIds = [];
    if ($studentId > 0) {
        $apps = $pdo->prepare('SELECT application_id FROM applications WHERE student_id = :student_id');
        $apps->execute(['student_id' => $studentId]);
        $applicationIds = array_map('intval', $apps->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    if (!empty($applicationIds)) {
        $in = implode(',', array_fill(0, count($applicationIds), '?'));

        // Remove all student-facing records tied to this account
        $pdo->prepare("DELETE FROM attendance_logs WHERE application_id IN ($in)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM duty_schedules WHERE application_id IN ($in)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM evaluations WHERE application_id IN ($in)")->execute($applicationIds);
        $pdo->prepare("DELETE FROM student_reports WHERE application_id IN ($in)")->execute($applicationIds);

        // Documents can be stored per application in this project
        try {
            $pdo->prepare("DELETE FROM document_uploads WHERE application_id IN ($in)")->execute($applicationIds);
        } catch (Throwable $e) {
            // table or column may differ across local environments
        }

        $pdo->prepare("DELETE FROM applications WHERE application_id IN ($in)")->execute($applicationIds);
    }

    if ($studentId > 0) {
        $pdo->prepare('DELETE FROM students WHERE student_id = :student_id')->execute(['student_id' => $studentId]);
    }

    // Clear any reset tokens or login artifacts tied to the user
    try {
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')->execute(['user_id' => (int) $user['user_id']]);
    } catch (Throwable $e) {
        // table may not exist
    }

    // Delete any authored reports, if present, before deleting the user row
    try {
        $pdo->prepare('DELETE FROM student_reports WHERE reporter_id = :user_id')->execute(['user_id' => (int) $user['user_id']]);
    } catch (Throwable $e) {
        // table may not exist
    }

    $delete = $pdo->prepare('DELETE FROM users WHERE user_id = :user_id');
    $delete->execute(['user_id' => (int) $user['user_id']]);

    $pdo->commit();

    echo json_encode([
        'deleted_user_id' => (int) $user['user_id'],
        'email' => $user['email'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'role' => $user['role'],
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
