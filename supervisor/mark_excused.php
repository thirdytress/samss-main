<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$rawBody = file_get_contents('php://input');
$jsonBody = json_decode($rawBody, true);
$payload = is_array($jsonBody) ? $jsonBody : $_POST;

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['_csrf'] ?? null);
if (!sams_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$applicationId = (int) ($payload['application_id'] ?? 0);
$dutyId = (int) ($payload['duty_id'] ?? 0);
if ($applicationId <= 0 || $dutyId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid schedule']);
    exit;
}

$pdo = sams_pdo();
$officeStmt = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$officeStmt->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$officeRow = $officeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorOffice = trim((string) ($officeRow['office_name'] ?? ($user['office_name'] ?? '')));

$scheduleStmt = $pdo->prepare(
    'SELECT ds.duty_id, ds.term_id, ds.status, ds.office_name, a.preferred_office
     FROM duty_schedules ds
     INNER JOIN applications a ON a.application_id = ds.application_id
     WHERE ds.application_id = :application_id
       AND ds.duty_id = :duty_id
       AND ds.status = "accepted"
       AND (COALESCE(NULLIF(TRIM(ds.office_name), ""), NULLIF(TRIM(a.preferred_office), "")) = :supervisor_office OR a.preferred_office = :supervisor_office)
     LIMIT 1'
);
$scheduleStmt->execute([
    'application_id' => $applicationId,
    'duty_id' => $dutyId,
    'supervisor_office' => $supervisorOffice,
]);
$schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$schedule) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Schedule not found or not accessible']);
    exit;
}

$existingStmt = $pdo->prepare(
    'SELECT log_id, status
     FROM attendance_logs
     WHERE application_id = :application_id
       AND duty_id = :duty_id
     ORDER BY log_id DESC
     LIMIT 1'
);
$existingStmt->execute([
    'application_id' => $applicationId,
    'duty_id' => $dutyId,
]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($existing) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Attendance already exists for this schedule']);
    exit;
}

$termId = (int) ($schedule['term_id'] ?? 0);
if ($termId <= 0) {
    $currentTerm = sams_current_term($pdo);
    $termId = (int) ($currentTerm['term_id'] ?? 0);
}

try {
    $insertStmt = $pdo->prepare(
        'INSERT INTO attendance_logs (application_id, term_id, duty_id, clock_in_time, clock_out_time, status, late_minutes, notes, created_at, updated_at)
         VALUES (:application_id, :term_id, :duty_id, NULL, NULL, :status, 0, :notes, NOW(), NOW())'
    );
    $insertStmt->execute([
        'application_id' => $applicationId,
        'term_id' => $termId,
        'duty_id' => $dutyId,
        'status' => 'excused',
        'notes' => 'Marked excused by supervisor',
    ]);

    echo json_encode(['success' => true, 'message' => 'Schedule marked as excused']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to mark excused', 'error' => $e->getMessage()]);
    exit;
}