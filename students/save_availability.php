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
        default => $normalized,
    };
}

function calculate_slot_hours(string $timeStart, string $timeEnd): float
{
    $start = strtotime('1970-01-01 ' . $timeStart);
    $end = strtotime('1970-01-01 ' . $timeEnd);

    if ($start === false || $end === false || $end <= $start) {
        return 0.0;
    }

    return ($end - $start) / 3600;
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

        // Basic same-origin / XHR check to reduce CSRF risk (keeps compatibility with AJAX)
        $originOk = true;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!empty($_SERVER['HTTP_ORIGIN'])) {
            $originHost = parse_url((string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
            if ($originHost !== $host) {
                $originOk = false;
            }
        }
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $refHost = parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST);
            if ($refHost !== $host) {
                $originOk = false;
            }
        }
        if (!$originOk && (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest')) {
            throw new RuntimeException('Invalid request origin.');
        }

        // CSRF token validation (expects `_csrf` in JSON payload)
        $csrfToken = (string) ($data['_csrf'] ?? '');
        if (!sams_verify_csrf($csrfToken)) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        // Authorization: ensure the current session user is allowed to modify this application OR
        // they have an active registration submission in their session that matches this application.
        $currentUser = sams_authenticated_user();
        $isAuthorized = false;

        if ($currentUser) {
            // Admins may update any application; students may only update their own application
            $isAdmin = isset($currentUser['role']) && $currentUser['role'] === 'admin';
            if ($isAdmin) {
                $isAuthorized = true;
            } else {
                $ownerStmt = $pdo->prepare('SELECT student_id FROM applications WHERE application_id = :application_id LIMIT 1');
                $ownerStmt->execute(['application_id' => $applicationId]);
                $ownerStudentId = (int) ($ownerStmt->fetchColumn() ?: 0);

                if ($ownerStudentId <= 0) {
                    throw new RuntimeException('Application record not found.');
                }

                $userId = (int) ($currentUser['id'] ?? $currentUser['user_id'] ?? 0);
                $studentStmt = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1');
                $studentStmt->execute(['user_id' => $userId]);
                $currentStudentId = (int) ($studentStmt->fetchColumn() ?: 0);

                if ($currentStudentId > 0 && $currentStudentId === $ownerStudentId) {
                    $isAuthorized = true;
                }
            }
        } else {
            // Check if there is an active registration submission session that matches this application
            $submission = $_SESSION['registration_submission'] ?? null;
            if ($submission &&
                (int) ($submission['application_id'] ?? 0) === $applicationId &&
                (int) ($submission['student_id'] ?? 0) === $studentId &&
                (int) ($submission['term_id'] ?? 0) === $termId
            ) {
                $isAuthorized = true;
            }
        }

        if (!$isAuthorized) {
            throw new RuntimeException($currentUser ? 'Unauthorized to modify this application.' : 'Authentication required.');
        }

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

    // Ensure notes column exists. Do not alter schema at runtime; require migration.
    $checkColumn = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = 'availability' 
         AND COLUMN_NAME = 'notes'"
    );
    $checkColumn->execute();

    if ((int) $checkColumn->fetchColumn() === 0) {
        throw new RuntimeException('Database missing `availability.notes` column. Run migrations/m20260602_add_availability_notes.sql to add it.');
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
            (application_id, term_id, day_of_week, start_time, end_time, notes)
         VALUES 
            (:application_id, :term_id, :day_of_week, :time_start, :time_end, :notes)'
    );

    $totalHours = 0.0;
    $insertedSlots = 0;

    foreach ($entries as $entry) {
        $day = normalize_day_of_week((string) ($entry['day_of_week'] ?? ''));
        $timeStart = trim((string) ($entry['time_start'] ?? ''));
        $timeEnd = trim((string) ($entry['time_end'] ?? ''));
        $slotNotes = trim((string) ($entry['notes'] ?? ''));

        if ($day === '' || $timeStart === '' || $timeEnd === '') {
            continue;
        }

        if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true)) {
            continue;
        }

        $hours = calculate_slot_hours($timeStart, $timeEnd);

        if ($hours <= 0) {
            throw new RuntimeException(sprintf('Invalid availability time range for %s. End time must be later than start time.', $day));
        }

        if ($hours < 2) {
            throw new RuntimeException(sprintf('Each availability slot must be at least 2 hours. %s slot is %.1f hours.', $day, $hours));
        }

        $totalHours += $hours;
        $insertedSlots++;

        $insertStatement->execute([
            'application_id' => $applicationId,
            'term_id' => $termId,
            'day_of_week' => $day,
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
            'notes' => $slotNotes !== '' ? substr($slotNotes, 0, 500) : null,
        ]);
    }

    if ($insertedSlots === 0) {
        throw new RuntimeException('Please choose at least one availability slot.');
    }

    if ($totalHours < 10) {
        throw new RuntimeException('Minimum required availability is 10 hours per week.');
    }

    // Update the applications table with the total available hours per week
    $updateAppStmt = $pdo->prepare(
        "UPDATE applications 
         SET available_hours_per_week = :available_hours,
             submitted_at = CASE WHEN status = 'draft' THEN NOW() ELSE submitted_at END,
             status = CASE WHEN status = 'draft' THEN 'pending' ELSE status END
         WHERE application_id = :application_id"
    );
    $updateAppStmt->execute([
        'available_hours' => (int) round($totalHours),
        'application_id' => $applicationId
    ]);

    if (isset($_SESSION['registration_submission'])) {
        $_SESSION['registration_submission']['status'] = 'PENDING';
        unset($_SESSION['registration_submission']['success']);
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