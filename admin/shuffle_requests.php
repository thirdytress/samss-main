<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$admin = sams_authenticated_user();
if (!$admin || ($admin['role'] ?? null) !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = sams_pdo();
$message = '';
$error = '';
$adminIdStmt = $pdo->prepare('SELECT admin_id FROM admins WHERE user_id = :user_id LIMIT 1');
$adminIdStmt->execute(['user_id' => (int) ($admin['user_id'] ?? 0)]);
$adminId = (int) ($adminIdStmt->fetchColumn() ?: 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!sams_verify_csrf((string) ($_POST['_csrf'] ?? ''))) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
        if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            throw new RuntimeException('Invalid shuffle request.');
        }

        $pdo->beginTransaction();
        $requestStmt = $pdo->prepare(
            'SELECT sr.*, aFrom.application_id AS from_application_id, aTo.application_id AS to_application_id
             FROM shuffle_requests sr
             INNER JOIN applications aFrom ON aFrom.student_id = sr.from_student_id AND aFrom.term_id = sr.term_id
             LEFT JOIN applications aTo ON aTo.student_id = sr.to_student_id AND aTo.term_id = sr.term_id AND aTo.status = "approved"
             WHERE sr.request_id = :request_id AND sr.status = "pending" LIMIT 1'
        );
        $requestStmt->execute(['request_id' => $requestId]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$request) {
            throw new RuntimeException('Request is no longer pending.');
        }

        if ($decision === 'reject') {
            $update = $pdo->prepare('UPDATE shuffle_requests SET status = "rejected", reviewed_by = :admin_id, reviewed_at = NOW(), review_notes = :notes WHERE request_id = :request_id');
            $update->execute(['admin_id' => $adminId ?: null, 'notes' => $reviewNotes, 'request_id' => $requestId]);
            $message = 'Shuffle request rejected.';
        } else {
            $toApplicationId = (int) ($request['to_application_id'] ?? 0);
            if ($toApplicationId <= 0) {
                throw new RuntimeException('Select an approved replacement student before approving this request.');
            }

            $scheduleStmt = $pdo->prepare('SELECT * FROM duty_schedules WHERE application_id = :application_id AND term_id = :term_id AND status = "deployed" ORDER BY duty_id');
            $scheduleStmt->execute(['application_id' => (int) $request['from_application_id'], 'term_id' => (int) $request['term_id']]);
            $oldSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$oldSchedules) {
                throw new RuntimeException('No deployed schedules were found for the current student.');
            }

            $pdo->prepare('UPDATE duty_schedules SET status = "declined", student_response_date = NOW(), updated_at = NOW() WHERE application_id = :application_id AND term_id = :term_id AND status = "deployed"')
                ->execute(['application_id' => (int) $request['from_application_id'], 'term_id' => (int) $request['term_id']]);

            $hasOffice = sams_column_exists($pdo, 'duty_schedules', 'office_name');
            $insertSql = $hasOffice
                ? 'INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :office_name, :term_id, :day, :start_time, :end_time, "deployed")'
                : 'INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :term_id, :day, :start_time, :end_time, "deployed")';
            $insert = $pdo->prepare($insertSql);
            foreach ($oldSchedules as $schedule) {
                $params = ['application_id' => $toApplicationId, 'term_id' => (int) $request['term_id'], 'day' => $schedule['day_of_week'], 'start_time' => $schedule['start_time'], 'end_time' => $schedule['end_time']];
                if ($hasOffice) {
                    $params['office_name'] = $schedule['office_name'] ?? null;
                }
                $insert->execute($params);
            }

            $history = $pdo->prepare('INSERT INTO shuffle_history (term_id, shuffled_by, old_schedule_data, new_schedule_data, reason) VALUES (:term_id, :admin_id, :old_data, :new_data, :reason)');
            $history->execute(['term_id' => (int) $request['term_id'], 'admin_id' => $adminId ?: null, 'old_data' => json_encode(['from_student_id' => (int) $request['from_student_id'], 'schedules' => $oldSchedules]), 'new_data' => json_encode(['to_student_id' => (int) $request['to_student_id'], 'application_id' => $toApplicationId]), 'reason' => $request['reason'] . ($reviewNotes !== '' ? "\nAdmin: " . $reviewNotes : '')]);
            $update = $pdo->prepare('UPDATE shuffle_requests SET status = "approved", reviewed_by = :admin_id, reviewed_at = NOW(), review_notes = :notes WHERE request_id = :request_id');
            $update->execute(['admin_id' => $adminId ?: null, 'notes' => $reviewNotes, 'request_id' => $requestId]);
            $message = 'Shuffle approved and schedules transferred.';
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$requests = $pdo->query(
    'SELECT sr.*, t.term_name, t.term_year, sup.office_name,
            CONCAT(uSup.first_name, " ", uSup.last_name) AS supervisor_name,
            CONCAT(uFrom.first_name, " ", uFrom.last_name) AS from_name,
            sFrom.student_id_number AS from_code,
            CONCAT(uTo.first_name, " ", uTo.last_name) AS to_name,
            sTo.student_id_number AS to_code
     FROM shuffle_requests sr
     INNER JOIN terms t ON t.term_id = sr.term_id
     INNER JOIN supervisors sup ON sup.supervisor_id = sr.supervisor_id
     INNER JOIN users uSup ON uSup.user_id = sup.user_id
     INNER JOIN students sFrom ON sFrom.student_id = sr.from_student_id
     INNER JOIN users uFrom ON uFrom.user_id = sFrom.user_id
     LEFT JOIN students sTo ON sTo.student_id = sr.to_student_id
     LEFT JOIN users uTo ON uTo.user_id = sTo.user_id
     ORDER BY FIELD(sr.status, "pending", "approved", "rejected", "cancelled"), sr.created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Shuffle Requests | SAMS</title></head><body>
<div class="shell"><?php $activeAdminNav = 'shuffle_requests'; require __DIR__ . '/_sidebar.php'; ?><div class="main"><header class="topbar"><div><div class="topbar__title">Shuffle Requests</div><div class="topbar__sub">Review supervisor requests to transfer student assignments</div></div></header><main class="page">
<?php if ($message !== ''): ?><div class="card" style="padding:14px;color:#027a48;font-weight:700;"><?= $h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="card" style="padding:14px;color:#b42318;font-weight:700;"><?= $h($error) ?></div><?php endif; ?>
<section class="card"><div style="padding:20px;border-bottom:1px solid #e5e7eb;"><h1 style="margin:0;font-size:20px;">Assignment change requests</h1><p style="margin:6px 0 0;color:#667085;">A shuffle is a support and assignment decision, not a punishment. Review the stated reason before approving.</p></div><div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:900px;"><thead><tr><th style="padding:12px;text-align:left;">Status</th><th style="padding:12px;text-align:left;">Office / Supervisor</th><th style="padding:12px;text-align:left;">Current student</th><th style="padding:12px;text-align:left;">Replacement</th><th style="padding:12px;text-align:left;">Reason</th><th style="padding:12px;text-align:left;">Action</th></tr></thead><tbody>
<?php foreach ($requests as $request): ?><tr style="border-top:1px solid #eef2f7;"><td style="padding:12px;"><strong><?= $h(ucfirst($request['status'])) ?></strong><div style="font-size:12px;color:#667085;"><?= $h($request['term_name'] . ' ' . $request['term_year']) ?></div></td><td style="padding:12px;"><?= $h($request['office_name']) ?><div style="font-size:12px;color:#667085;"><?= $h($request['supervisor_name']) ?></div></td><td style="padding:12px;"><?= $h($request['from_name']) ?><div style="font-size:12px;color:#667085;"><?= $h($request['from_code']) ?></div></td><td style="padding:12px;"><?= $h($request['to_name'] ?: 'Admin to choose') ?><div style="font-size:12px;color:#667085;"><?= $h($request['to_code'] ?? '') ?></div></td><td style="padding:12px;max-width:280px;"><?= nl2br($h($request['reason'])) ?></td><td style="padding:12px;"><?php if ($request['status'] === 'pending'): ?><form method="post" style="display:grid;gap:6px;"><?php echo sams_csrf_input_field(); ?><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><input name="review_notes" placeholder="Review note (optional)" style="padding:8px;border:1px solid #d1d5db;border-radius:6px;"><button name="decision" value="approve" type="submit" style="background:#003087;color:#fff;border:0;border-radius:6px;padding:8px;">Approve</button><button name="decision" value="reject" type="submit" style="background:#f3f4f6;border:0;border-radius:6px;padding:8px;">Reject</button></form><?php else: ?><span style="color:#667085;">Reviewed</span><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$requests): ?><tr><td colspan="6" style="padding:24px;text-align:center;color:#667085;">No shuffle requests yet.</td></tr><?php endif; ?></tbody></table></div></section></main></div></div></body></html>