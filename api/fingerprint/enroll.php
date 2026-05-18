<?php
// Fingerprint enrollment API: accept and save template and finger name
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/bootstrap.php';
$input = file_get_contents('php://input');
$data = json_decode($input, true);
$template = $data['template'] ?? null;
$finger = $data['finger'] ?? null;
$user = sams_authenticated_user();
if (!$user || ($user['role'] ?? null) !== 'student') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}
$pdo = sams_pdo();
$studentStmt = $pdo->prepare('SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1');
$studentStmt->execute(['user_id' => $user['user_id'] ?? $user['id']]);
$studentRow = $studentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$studentId = (int) ($studentRow['student_id'] ?? 0);
if ($template && $finger && $studentId > 0) {
    // Upsert: if already enrolled, update; else insert
    $check = $pdo->prepare('SELECT id FROM student_fingerprints WHERE student_id = :sid AND finger = :finger LIMIT 1');
    $check->execute(['sid' => $studentId, 'finger' => $finger]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $upd = $pdo->prepare('UPDATE student_fingerprints SET template = :tpl, enrolled_at = NOW() WHERE id = :id');
        $upd->execute(['tpl' => $template, 'id' => $row['id']]);
    } else {
        $ins = $pdo->prepare('INSERT INTO student_fingerprints (student_id, finger, template) VALUES (:sid, :finger, :tpl)');
        $ins->execute(['sid' => $studentId, 'finger' => $finger, 'tpl' => $template]);
    }
    echo json_encode([
        'status' => 'ok',
        'message' => 'Template for ' . $finger . ' saved. Length: ' . strlen($template),
        'finger' => $finger,
        'template_preview' => substr($template, 0, 32) . (strlen($template) > 32 ? '...' : '')
    ]);
} elseif ($template) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Finger name missing.'
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'No template received.'
    ]);
}
