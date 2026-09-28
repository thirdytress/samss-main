<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'supervisor') {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
    http_response_code(400);
    exit('Invalid request');
}

$pdo = sams_pdo();
$supervisorStmt = $pdo->prepare('SELECT supervisor_id, office_name FROM supervisors WHERE user_id = :user_id LIMIT 1');
$supervisorStmt->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisor = $supervisorStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$fromStudentId = (int) ($_POST['from_student_id'] ?? 0);
$toStudentId = (int) ($_POST['to_student_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

try {
    if (!$supervisor || $fromStudentId <= 0 || $reason === '') {
        throw new RuntimeException('Please select a student and provide a reason.');
    }

    $term = sams_current_term($pdo);
    $termId = (int) ($term['term_id'] ?? 0);
    if ($termId <= 0) {
        throw new RuntimeException('No active term is configured.');
    }

    $accessStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM duty_schedules ds
         INNER JOIN applications a ON a.application_id = ds.application_id
         WHERE a.student_id = :student_id AND ds.term_id = :term_id
           AND ds.status = "deployed"
           AND COALESCE(NULLIF(TRIM(ds.office_name), ""), a.preferred_office) = :office'
    );
    $accessStmt->execute(['student_id' => $fromStudentId, 'term_id' => $termId, 'office' => $supervisor['office_name']]);
    if ((int) $accessStmt->fetchColumn() === 0) {
        throw new RuntimeException('That student is not assigned to your office for the active term.');
    }

    if ($toStudentId === $fromStudentId) {
        throw new RuntimeException('Replacement student must be different.');
    }

    if ($toStudentId > 0) {
        $replacementStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM applications a
             WHERE a.student_id = :student_id AND a.term_id = :term_id
               AND a.status = "approved" AND a.preferred_office = :office'
        );
        $replacementStmt->execute(['student_id' => $toStudentId, 'term_id' => $termId, 'office' => $supervisor['office_name']]);
        if ((int) $replacementStmt->fetchColumn() === 0) {
            throw new RuntimeException('Replacement student must have an approved application in the same office.');
        }
    }

    $duplicateStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM shuffle_requests
         WHERE term_id = :term_id AND from_student_id = :from_student_id AND status = "pending"'
    );
    $duplicateStmt->execute(['term_id' => $termId, 'from_student_id' => $fromStudentId]);
    if ((int) $duplicateStmt->fetchColumn() > 0) {
        throw new RuntimeException('There is already a pending shuffle request for this student.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO shuffle_requests (term_id, supervisor_id, from_student_id, to_student_id, reason)
         VALUES (:term_id, :supervisor_id, :from_student_id, :to_student_id, :reason)'
    );
    $insert->execute([
        'term_id' => $termId,
        'supervisor_id' => $supervisor['supervisor_id'],
        'from_student_id' => $fromStudentId,
        'to_student_id' => $toStudentId > 0 ? $toStudentId : null,
        'reason' => $reason,
    ]);

    $_SESSION['supervisor_shuffle_flash'] = 'Shuffle request sent to Admin for review.';
} catch (Throwable $exception) {
    $_SESSION['supervisor_shuffle_flash'] = $exception->getMessage();
}

header('Location: students.php');
exit;