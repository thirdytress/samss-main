<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "Starting evaluation seed-and-test\n";

// Find a supervisor and office
$sup = $pdo->query('SELECT supervisor_id, user_id, office_name FROM supervisors LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$sup) {
    $row = $pdo->query('SELECT user_id, office_name FROM users WHERE role = "supervisor" LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $office = $row['office_name'] ?? '';
        $supervisorUserId = (int) $row['user_id'];
        // try to find supervisor_id in supervisors table
        $supRow = $pdo->prepare('SELECT supervisor_id FROM supervisors WHERE user_id = :uid LIMIT 1');
        $supRow->execute(['uid' => $supervisorUserId]);
        $supId = $supRow->fetchColumn() ?: 0;
        $supervisorId = (int) $supId;
    } else {
        echo "No supervisor found in DB; aborting.\n";
        exit(1);
    }
} else {
    $office = $sup['office_name'] ?? '';
    $supervisorId = (int) $sup['supervisor_id'];
}

if (!$office) {
    echo "Supervisor office not found; aborting.\n";
    exit(1);
}

$termId = (int) ($pdo->query('SELECT term_id FROM terms WHERE is_active = 1 ORDER BY term_id DESC LIMIT 1')->fetchColumn() ?: 0);
if ($termId <= 0) {
    echo "No active term found; aborting.\n";
    exit(1);
}

$uniq = time();
$userEmail = "temp.eval.{$uniq}@sams.local";
$passwordHash = password_hash('tempeval', PASSWORD_DEFAULT);

// Insert user
$pwdCol = sams_first_existing_column($pdo, 'users', ['password', 'password_hash']);
if ($pwdCol === null) { echo "Users table has no password column; aborting.\n"; exit(1); }

$userCols = ['email', $pwdCol, 'role', 'first_name', 'last_name', 'is_active'];
$userParams = [':email', ':pwd', ':role', ':first_name', ':last_name', ':is_active'];
$userSql = 'INSERT INTO users (' . implode(', ', $userCols) . ') VALUES (' . implode(', ', $userParams) . ')';
$stmt = $pdo->prepare($userSql);
$stmt->execute(['email'=>$userEmail, 'pwd'=>$passwordHash, 'role'=>'student', 'first_name'=>'Temp', 'last_name'=>'Eval', 'is_active'=>1]);
$userId = (int) $pdo->lastInsertId();

// Insert student
$studentStmt = $pdo->prepare("INSERT INTO students (user_id, student_id_number, program, year_level, is_enrolled, is_good_standing) VALUES (:uid, :num, :prog, :yr, 1, 1)");
$studentCode = 'TEMP' . $uniq;
$studentStmt->execute(['uid'=>$userId, 'num'=>$studentCode, 'prog'=>'Test', 'yr'=>1]);
$studentId = (int) $pdo->lastInsertId();

// Insert approved application
$appStmt = $pdo->prepare('INSERT INTO applications (student_id, term_id, status, preferred_office, skills, available_hours_per_week, submitted_at) VALUES (:sid, :term, :status, :office, :skills, :hours, NOW())');
$appStmt->execute(['sid'=>$studentId, 'term'=>$termId, 'status'=>'approved', 'office'=>$office, 'skills'=>'testing', 'hours'=>4]);
$applicationId = (int) $pdo->lastInsertId();

echo "Created temp user/student/application: user_id={$userId}, student_id={$studentId}, application_id={$applicationId}\n";

// Run evaluation_save.php via CLI
$cmd = escapeshellcmd(PHP_BINARY) . ' "' . __DIR__ . '\\..\\supervisor\\evaluation_save.php" application_id=' . $applicationId . ' supervisor_id=' . $supervisorId . ' rating=5 reliability=5 professionalism=5 comments="Automated test"';
echo "Running: {$cmd}\n";
exec($cmd, $out, $rc);
foreach ($out as $line) echo $line . "\n";
if ($rc !== 0) {
    echo "evaluation_save failed with code {$rc}\n";
}

// Verify evaluation
$chk = $pdo->prepare('SELECT COUNT(*) FROM evaluations WHERE application_id = :aid AND term_id = :tid');
$chk->execute(['aid'=>$applicationId, 'tid'=>$termId]);
$count = (int) $chk->fetchColumn();
echo "Evaluations recorded for application: {$count}\n";

// Cleanup: delete the evaluation(s), application, student, user
$delEval = $pdo->prepare('DELETE FROM evaluations WHERE application_id = :aid AND term_id = :tid'); $delEval->execute(['aid'=>$applicationId, 'tid'=>$termId]);
$delApp = $pdo->prepare('DELETE FROM applications WHERE application_id = :aid'); $delApp->execute(['aid'=>$applicationId]);
$delStudent = $pdo->prepare('DELETE FROM students WHERE student_id = :sid'); $delStudent->execute(['sid'=>$studentId]);
$delUser = $pdo->prepare('DELETE FROM users WHERE user_id = :uid'); $delUser->execute(['uid'=>$userId]);

echo "Cleaned up temporary rows.\nDone.\n";
