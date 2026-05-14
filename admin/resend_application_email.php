<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mail.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$applicationId = isset($json['application_id']) ? (int)$json['application_id'] : (int)($_POST['application_id'] ?? 0);

if ($applicationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid application id']);
    exit;
}

$pdo = sams_pdo();
$stmt = $pdo->prepare(
    'SELECT a.application_id, a.status, u.email, u.first_name, u.last_name
     FROM applications a
     INNER JOIN students s ON s.student_id = a.student_id
     INNER JOIN users u ON u.user_id = s.user_id
     WHERE a.application_id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $applicationId]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'application not found']);
    exit;
}

$toEmail = (string)($app['email'] ?? '');
$toName = trim((string)($app['first_name'] ?? '') . ' ' . (string)($app['last_name'] ?? ''));
$status = (string)($app['status'] ?? 'pending');

try {
    sams_send_application_review_email($toEmail, $toName, $status);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
