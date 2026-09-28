<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod !== 'POST') {
    $applicationId = isset($_GET['application_id']) ? (int) $_GET['application_id'] : 0;
    $redirect = 'reports.php';
    if ($applicationId > 0) {
        $redirect .= '?application_id=' . $applicationId;
    }
    header('Location: ' . $redirect);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = sams_pdo();
$supervisorStatement = $pdo->prepare(
    'SELECT s.office_name
     FROM supervisors s
     WHERE s.user_id = :user_id
     LIMIT 1'
);
$supervisorStatement->execute(['user_id' => (int) ($user['user_id'] ?? 0)]);
$supervisorRow = $supervisorStatement->fetch(PDO::FETCH_ASSOC) ?: [];
$supervisorOffice = (string) ($supervisorRow['office_name'] ?? ($user['office_name'] ?? 'Assigned Office'));

// Accept JSON body or form POST
$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$post = $_POST;
$data = is_array($json) ? $json : $post;
$application_id = isset($data['application_id']) ? (int)$data['application_id'] : 0;
$duty_id = isset($data['duty_id']) ? (int)$data['duty_id'] : 0;
$student_code = isset($data['student_code']) ? trim((string)$data['student_code']) : '';
$title = isset($data['title']) ? trim((string)$data['title']) : '';
$notes = isset($data['notes']) ? trim((string)$data['notes']) : '';

// CSRF protection: accept token in header `X-CSRF-Token` or in JSON payload `_csrf` or POST
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $data['_csrf'] ?? $_POST['_csrf'] ?? null;
if (!sams_verify_csrf($csrfToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if ($application_id <= 0 && $student_code === '') {
    echo json_encode(['success' => false, 'message' => 'Missing identifiers']);
    exit;
}
if ($notes === '') {
    echo json_encode(['success' => false, 'message' => 'Notes/body required']);
    exit;
}

// Ensure reports table exists before insert
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS student_reports (
        report_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NULL,
        duty_id INT NULL,
        student_code VARCHAR(128) NULL,
        reporter_id INT NOT NULL,
        title VARCHAR(255) NULL,
        notes TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'open',
        is_new TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$insert = $pdo->prepare(
    "INSERT INTO student_reports (application_id, duty_id, student_code, reporter_id, title, notes, status, is_new)
     VALUES (:application_id, :duty_id, :student_code, :reporter_id, :title, :notes, 'open', 1)"
);

// Validate that the supervisor may report this application/student (must belong to their office)
if ($application_id > 0) {
    $appStmt = $pdo->prepare(
        'SELECT a.preferred_office,
                EXISTS(
                    SELECT 1
                    FROM duty_schedules ds
                    WHERE ds.application_id = a.application_id
                      AND ds.office_name = :office
                ) AS has_office
         FROM applications a
         WHERE a.application_id = :id'
    );
    $appStmt->execute(['id' => $application_id, 'office' => $supervisorOffice]);
    $appRow = $appStmt->fetch(PDO::FETCH_ASSOC);
    if (!$appRow) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown application']);
        exit;
    }
    $pref = (string)($appRow['preferred_office'] ?? '');
    $hasOffice = (int)($appRow['has_office'] ?? 0) === 1;
    if ($supervisorOffice !== '' && $pref !== '' && $pref !== $supervisorOffice && !$hasOffice) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: application not in your office']);
        exit;
    }
} else {

// If duty_id provided, validate it belongs to the application and active term and office
if ($duty_id > 0) {
    $currentTerm = sams_current_term($pdo);
    $activeTermId = (int) ($currentTerm['term_id'] ?? 0);
    $ds = $pdo->prepare('SELECT ds.application_id, ds.term_id, ds.office_name, a.preferred_office FROM duty_schedules ds JOIN applications a ON a.application_id = ds.application_id WHERE ds.duty_id = :duty_id LIMIT 1');
    $ds->execute(['duty_id' => $duty_id]);
    $dsRow = $ds->fetch(PDO::FETCH_ASSOC);
    if (!$dsRow) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown duty']);
        exit;
    }
    if ($application_id > 0 && (int)$dsRow['application_id'] !== $application_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Duty does not belong to application']);
        exit;
    }
    if ($activeTermId > 0 && (int)$dsRow['term_id'] !== $activeTermId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Duty not in active term']);
        exit;
    }
    $scheduleOffice = (string)($dsRow['office_name'] ?? '');
    $preferredOffice = (string)($dsRow['preferred_office'] ?? '');
    if ($supervisorOffice !== '' && $scheduleOffice !== '' && $scheduleOffice !== $supervisorOffice && ($preferredOffice === '' || $preferredOffice !== $supervisorOffice)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: duty not in your office']);
        exit;
    }
}
    // If no application_id provided, attempt to resolve student->application in this office
    if ($student_code !== '') {
        $studentStmt = $pdo->prepare('SELECT student_id FROM students WHERE student_id = :sid');
        $studentStmt->execute(['sid' => $student_code]);
        $stu = $studentStmt->fetchColumn();
        if ($stu === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown student']);
            exit;
        }
        $appResolve = $pdo->prepare(
            'SELECT a.application_id, a.preferred_office
             FROM applications a
             WHERE a.student_id = :sid
               AND EXISTS (
                   SELECT 1
                   FROM duty_schedules ds
                   WHERE ds.application_id = a.application_id
                                         AND (ds.office_name = :office1 OR a.preferred_office = :office2)
               )
             ORDER BY a.application_id DESC
             LIMIT 1'
        );
                $appResolve->execute(['sid' => $student_code, 'office1' => $supervisorOffice, 'office2' => $supervisorOffice]);
        $appRow = $appResolve->fetch(PDO::FETCH_ASSOC);
        if ($appRow && isset($appRow['application_id'])) {
            if ((string)($appRow['preferred_office'] ?? '') !== '' && $supervisorOffice !== '' && (string)$appRow['preferred_office'] !== $supervisorOffice) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Forbidden: student not assigned to your office']);
                exit;
            }
            // set application_id for insertion
            $application_id = (int)$appRow['application_id'];
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No application found for student']);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing identifiers']);
        exit;
    }
}

$res = $insert->execute([
    'application_id' => $application_id > 0 ? $application_id : null,
    'duty_id' => $duty_id > 0 ? $duty_id : null,
    'student_code' => $student_code !== '' ? $student_code : null,
    'reporter_id' => (int)($user['user_id'] ?? 0),
    'title' => $title !== '' ? $title : null,
    'notes' => $notes,
]);

if ($res) {
    $reportId = (int)$pdo->lastInsertId();

    echo json_encode(['success' => true, 'report_id' => $reportId]);
} else {
    echo json_encode(['success' => false, 'message' => 'DB insert failed']);
}
