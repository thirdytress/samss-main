<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function normalize_day_of_week(string $day): string
{
    $normalized = trim($day);

    return match (strtolower($normalized)) {
        'monday', 'mon' => 'Monday',
        'tuesday', 'tue' => 'Tuesday',
        'wednesday', 'wed' => 'Wednesday',
        'thursday', 'thu' => 'Thursday',
        'friday', 'fri' => 'Friday',
        'saturday', 'sat' => 'Saturday',
        'sunday', 'sun' => 'Sunday',
        default => $normalized,
    };
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

$applicationId = (int) ($data['application_id'] ?? 0);
$studentId = (int) ($data['student_id'] ?? 0);
$termId = (int) ($data['term_id'] ?? 0);
$entries = $data['availability'] ?? [];

if ($applicationId <= 0 || $studentId <= 0 || $termId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing application, student, or term ID']);
    exit;
}

if (!is_array($entries) || empty($entries)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No availability entries provided']);
    exit;
}

try {
    $pdo = sams_pdo();

    $checkApplication = $pdo->prepare(
        'SELECT application_id FROM applications WHERE application_id = :application_id AND student_id = :student_id AND term_id = :term_id LIMIT 1'
    );

    $checkApplication->execute([
        'application_id' => $applicationId,
        'student_id' => $studentId,
        'term_id' => $termId,
    ]);

    if (!$checkApplication->fetch()) {
        throw new RuntimeException('Application record not found.');
    }

    $pdo->beginTransaction();

    $deleteStatement = $pdo->prepare(
        'DELETE FROM availability WHERE application_id = :application_id'
    );

    $deleteStatement->execute([
        'application_id' => $applicationId,
    ]);

    $insertStatement = $pdo->prepare(
        'INSERT INTO availability 
            (application_id, term_id, day_of_week, start_time, end_time)
         VALUES 
            (:application_id, :term_id, :day_of_week, :time_start, :time_end)'
    );

    foreach ($entries as $entry) {
        $day = normalize_day_of_week((string) ($entry['day_of_week'] ?? ''));
        $timeStart = trim((string) ($entry['time_start'] ?? ''));
        $timeEnd = trim((string) ($entry['time_end'] ?? ''));

        if ($day === '' || $timeStart === '' || $timeEnd === '') {
            continue;
        }

        if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true)) {
            continue;
        }

        $insertStatement->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
            'day_of_week' => $day,
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Availability saved successfully.'
    ]);
    exit;

} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage()
    ]);
    exit;
}