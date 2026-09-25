<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$admin = sams_authenticated_user();
if (!$admin || ($admin['role'] ?? null) !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
    http_response_code(400);
    exit('Invalid request');
}

$pdo = sams_pdo();
$applicationId = (int) ($_POST['application_id'] ?? 0);
$newOffice = trim((string) ($_POST['preferred_office'] ?? ''));

try {
    if ($applicationId <= 0 || !in_array($newOffice, sams_office_options(), true)) {
        throw new RuntimeException('Please choose a valid office.');
    }

    $adminIdStmt = $pdo->prepare('SELECT admin_id FROM admins WHERE user_id = :user_id LIMIT 1');
    $adminIdStmt->execute(['user_id' => (int) ($admin['user_id'] ?? 0)]);
    $adminId = (int) ($adminIdStmt->fetchColumn() ?: 0);
    $lookup = $pdo->prepare(
        'SELECT a.application_id, a.student_id, a.term_id, a.status, a.preferred_office, u.user_id
         FROM applications a INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE a.application_id = :application_id LIMIT 1'
    );
    $lookup->execute(['application_id' => $applicationId]);
    $application = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$application || !in_array($application['status'], ['approved', 'pending'], true)) {
        throw new RuntimeException('Only pending or approved applications can be reassigned.');
    }

    $oldOffice = trim((string) ($application['preferred_office'] ?? ''));
    if ($oldOffice === $newOffice) {
        throw new RuntimeException('The student is already assigned to that office.');
    }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE applications SET preferred_office = :office, updated_at = NOW() WHERE application_id = :application_id')
        ->execute(['office' => $newOffice, 'application_id' => $applicationId]);

    if (sams_column_exists($pdo, 'duty_schedules', 'office_name')) {
        $pdo->prepare(
            'UPDATE duty_schedules SET office_name = :office, updated_at = NOW()
             WHERE application_id = :application_id AND term_id = :term_id
               AND status IN ("assigned", "pending", "accepted", "deployed")'
        )->execute(['office' => $newOffice, 'application_id' => $applicationId, 'term_id' => (int) $application['term_id']]);
    }

    if ($adminId > 0) {
        $pdo->prepare(
            'INSERT INTO audit_logs (application_id, admin_id, action, reason, status_before, status_after)
             VALUES (:application_id, :admin_id, "office_reassigned", :reason, :before, :after)'
        )->execute([
            'application_id' => $applicationId,
            'admin_id' => $adminId,
            'reason' => 'Preferred office changed from ' . $oldOffice . ' to ' . $newOffice,
            'before' => $oldOffice,
            'after' => $newOffice,
        ]);
    }

    if (sams_column_exists($pdo, 'notifications', 'user_id')) {
        $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, notification_type)
             VALUES (:user_id, :title, :message, "system")'
        )->execute([
            'user_id' => (int) $application['user_id'],
            'title' => 'Office assignment updated',
            'message' => 'Your preferred office was updated by Admin from ' . $oldOffice . ' to ' . $newOffice . '.',
        ]);
    }

    $pdo->commit();
    $_SESSION['sams_app_flash'] = 'Student office assignment updated successfully.';
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['sams_app_error'] = $exception->getMessage();
}

header('Location: application.php');
exit;