<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'method_not_allowed']);
    exit;
}

// Ensure same-origin session
if (empty($_SESSION)) {
    echo json_encode(['success' => false, 'message' => 'no_session']);
    exit;
}

// Clear the registration submission payload if present
if (isset($_SESSION['registration_submission'])) {
    unset($_SESSION['registration_submission']);
}

echo json_encode(['success' => true]);
