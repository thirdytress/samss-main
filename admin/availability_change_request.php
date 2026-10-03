<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'admin') {
    http_response_code(401);
    exit('Unauthorized');
}

$requestId = (int) ($_POST['request_id'] ?? 0);
$action = (string) ($_POST['request_action'] ?? '');
$returnTo = (string) ($_POST['return_to'] ?? 'scheduling');
if ($requestId <= 0 || !in_array($action, ['approve', 'decline'], true)) {
    $_SESSION['sams_app_error'] = 'Invalid availability request.';
    header('Location: scheduling.php');
    exit;
}

try {
    $pdo = sams_pdo();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "SELECT * FROM availability_change_requests
         WHERE request_id = :request_id AND status = 'pending' FOR UPDATE"
    );
    $stmt->execute(['request_id' => $requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new RuntimeException('Availability request is no longer pending.');
    }

    if ($action === 'approve') {
        $entries = json_decode((string) $request['proposed_availability'], true);
        if (!is_array($entries) || empty($entries)) {
            throw new RuntimeException('The proposed availability is invalid.');
        }
        $pdo->prepare('DELETE FROM availability WHERE application_id = :application_id AND term_id = :term_id')
            ->execute(['application_id' => (int) $request['application_id'], 'term_id' => (int) $request['term_id']]);
        $insert = $pdo->prepare(
            'INSERT INTO availability (application_id, term_id, day_of_week, start_time, end_time, notes)
             VALUES (:application_id, :term_id, :day_of_week, :start_time, :end_time, :notes)'
        );
        $hours = 0.0;
        foreach ($entries as $entry) {
            $start = strtotime('1970-01-01 ' . (string) ($entry['time_start'] ?? ''));
            $end = strtotime('1970-01-01 ' . (string) ($entry['time_end'] ?? ''));
            if ($start === false || $end === false || $end <= $start) {
                throw new RuntimeException('The proposed availability contains an invalid time range.');
            }
            $hours += ($end - $start) / 3600;
            $insert->execute([
                'application_id' => (int) $request['application_id'],
                'term_id' => (int) $request['term_id'],
                'day_of_week' => (string) $entry['day_of_week'],
                'start_time' => (string) $entry['time_start'],
                'end_time' => (string) $entry['time_end'],
                'notes' => trim((string) ($entry['notes'] ?? '')) ?: null,
            ]);
        }
        $pdo->prepare('UPDATE applications SET available_hours_per_week = :hours WHERE application_id = :application_id')
            ->execute(['hours' => (int) round($hours), 'application_id' => (int) $request['application_id']]);

        $appStatusStmt = $pdo->prepare('SELECT status, preferred_office FROM applications WHERE application_id = :application_id');
        $appStatusStmt->execute(['application_id' => (int) $request['application_id']]);
        $application = $appStatusStmt->fetch() ?: [];
        if (in_array((string) ($application['status'] ?? ''), ['approved', 'deployed'], true)) {
            $pdo->prepare('DELETE FROM duty_schedules WHERE application_id = :application_id')
                ->execute(['application_id' => (int) $request['application_id']]);
            $hasOfficeColumn = sams_column_exists($pdo, 'duty_schedules', 'office_name');
            $scheduleInsert = $hasOfficeColumn
                ? $pdo->prepare('INSERT INTO duty_schedules (application_id, office_name, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :office_name, :term_id, :day_of_week, :start_time, :end_time, "assigned")')
                : $pdo->prepare('INSERT INTO duty_schedules (application_id, term_id, day_of_week, start_time, end_time, status) VALUES (:application_id, :term_id, :day_of_week, :start_time, :end_time, "assigned")');
            foreach ($entries as $entry) {
                $params = [
                    'application_id' => (int) $request['application_id'],
                    'term_id' => (int) $request['term_id'],
                    'day_of_week' => (string) $entry['day_of_week'],
                    'start_time' => (string) $entry['time_start'],
                    'end_time' => (string) $entry['time_end'],
                ];
                if ($hasOfficeColumn) {
                    $params['office_name'] = (string) ($application['preferred_office'] ?? '');
                }
                $scheduleInsert->execute($params);
            }
        }
    }

    $pdo->prepare(
        'UPDATE availability_change_requests
         SET status = :status, reviewed_at = NOW(), reviewed_by = :reviewed_by
         WHERE request_id = :request_id'
    )->execute([
        'status' => $action === 'approve' ? 'approved' : 'declined',
        'reviewed_by' => (int) ($user['user_id'] ?? $user['id'] ?? 0),
        'request_id' => $requestId,
    ]);
    $pdo->commit();
    $_SESSION['sams_app_flash'] = $action === 'approve'
        ? 'Availability change approved successfully.'
        : 'Availability change declined.';
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['sams_app_error'] = $exception->getMessage();
}

$applicationId = (int) ($_POST['application_id'] ?? 0);
if ($returnTo === 'application' && $applicationId > 0) {
    header('Location: application_view.php?application_id=' . $applicationId);
} else {
    header('Location: scheduling.php');
}
exit;
