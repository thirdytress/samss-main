<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Authenticate user is admin
$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Validate input
$studentId = (int) ($_POST['student_id'] ?? 0);
$nfcUid = trim((string) ($_POST['nfc_uid'] ?? ''));

if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

$pdo = sams_pdo();
try {
    // Check if student exists
    $studentStmt = $pdo->prepare('SELECT student_id, student_id_number FROM students WHERE student_id = :student_id LIMIT 1');
    $studentStmt->execute(['student_id' => $studentId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    if ($nfcUid === '') {
        // If empty, clear the NFC UID
        $update = $pdo->prepare('UPDATE students SET nfc_uid = NULL WHERE student_id = :student_id');
        $update->execute(['student_id' => $studentId]);
        echo json_encode(['success' => true, 'message' => 'Card unregistered successfully.']);
        exit;
    }

    // Check if this NFC UID is already registered to another student
    $checkStmt = $pdo->prepare('
        SELECT student_id, student_id_number, u.first_name, u.last_name 
        FROM students s
        INNER JOIN users u ON u.user_id = s.user_id
        WHERE s.nfc_uid = :nfc_uid LIMIT 1
    ');
    $checkStmt->execute(['nfc_uid' => $nfcUid]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ((int)$existing['student_id'] === $studentId) {
            echo json_encode(['success' => true, 'message' => 'This card is already registered to this student account.']);
            exit;
        } else {
            $existingName = trim(($existing['first_name'] ?? '') . ' ' . ($existing['last_name'] ?? ''));
            echo json_encode([
                'success' => false, 
                'message' => 'This card is already registered to ' . ($existingName ?: 'another student') . ' (Student ID: ' . $existing['student_id_number'] . ').'
            ]);
            exit;
        }
    }

    // Update NFC UID for targeted student
    $update = $pdo->prepare('UPDATE students SET nfc_uid = :nfc_uid WHERE student_id = :student_id');
    $update->execute([
        'nfc_uid' => $nfcUid,
        'student_id' => $studentId
    ]);

    echo json_encode(['success' => true, 'message' => 'Card registered successfully!']);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
    exit;
}
