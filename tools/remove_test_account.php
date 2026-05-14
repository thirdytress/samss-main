<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$email = $argv[1] ?? 'test.student@sams.local';

$pdo->beginTransaction();
try {
    $u = $pdo->prepare('SELECT user_id, email FROM users WHERE email = :email LIMIT 1');
    $u->execute(['email' => $email]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo "No user found with email: $email\n";
        $pdo->rollBack();
        exit(0);
    }
    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

    echo "Found user: {$user['email']} (user_id={$userId})\n";

    $students = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :uid');
    $students->execute(['uid' => $userId]);
    $studentRows = $students->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if (!empty($studentRows)) {
        echo "Linked student_id(s): " . implode(',', $studentRows) . "\n";
    } else {
        echo "No linked students for this user.\n";
    }

    // collect application ids to delete
    $applicationIds = [];
    if (!empty($studentRows)) {
        $in = implode(',', array_map('intval', $studentRows));
        $apps = $pdo->query("SELECT application_id FROM applications WHERE student_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $applicationIds = array_map('intval', $apps);
        echo "Found application_id(s): " . implode(',', $applicationIds) . "\n";

        if (!empty($applicationIds)) {
            $inApps = implode(',', $applicationIds);
            // Delete attendance logs tied to these applications
            $delAL = $pdo->exec("DELETE FROM attendance_logs WHERE application_id IN ($inApps)");
            echo "Deleted $delAL attendance_log row(s)\n";

            // Delete duty_schedules referencing these applications (if exists)
            try {
                $delDS = $pdo->exec("DELETE FROM duty_schedules WHERE application_id IN ($inApps)");
                echo "Deleted $delDS duty_schedules row(s)\n";
            } catch (Throwable $e) {
                // table may not exist in some setups
            }

            // Delete applications
            $delApps = $pdo->exec("DELETE FROM applications WHERE application_id IN ($inApps)");
            echo "Deleted $delApps application row(s)\n";
        }
    }

    // Delete students
    if (!empty($studentRows)) {
        $in = implode(',', array_map('intval', $studentRows));
        $delStudents = $pdo->exec("DELETE FROM students WHERE student_id IN ($in)");
        echo "Deleted $delStudents student row(s)\n";
    }

    // Finally delete user
    $delUser = $pdo->prepare('DELETE FROM users WHERE user_id = :uid');
    $delUser->execute(['uid' => $userId]);
    echo "Deleted user row (user_id={$userId})\n";

    $pdo->commit();
    echo "Removal complete.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
