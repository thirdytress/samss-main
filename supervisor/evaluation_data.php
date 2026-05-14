<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = sams_authenticated_user();
if (!$user || (($user['role'] ?? null) !== 'supervisor')) {
    // For CLI usage, allow unauthed access if run from CLI
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

$pdo = sams_pdo();
$officeName = '';
if ($user) {
    $stmt = $pdo->prepare('SELECT s.office_name FROM supervisors s WHERE s.user_id = :uid LIMIT 1');
    $stmt->execute(['uid' => (int) ($user['user_id'] ?? 0)]);
    $officeRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $officeName = (string) ($officeRow['office_name'] ?? ($user['office_name'] ?? ''));
}

// Fallback: if run from CLI and no supervisor, pick first supervisor office
if (PHP_SAPI === 'cli' && $officeName === '') {
    $r = $pdo->query('SELECT office_name FROM supervisors LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    $officeName = (string) ($r['office_name'] ?? '');
}

$activeTermId = (int) ($pdo->query("SELECT COALESCE(MAX(term_id), 0) FROM terms WHERE is_active = 1")->fetchColumn() ?: 0);

// Eligible students
$students = [];
if ($activeTermId > 0 && $officeName !== '') {
    $stmt = $pdo->prepare(
        'SELECT a.application_id, u.first_name, u.last_name, s.student_id_number AS student_code
         FROM applications a
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE a.term_id = :term_id
           AND a.preferred_office = :office_name
           AND a.status = "approved"
         ORDER BY u.last_name, u.first_name'
    );
    $stmt->execute(['term_id' => $activeTermId, 'office_name' => $officeName]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Recent evaluations for this office/term
$recent = [];
if ($activeTermId > 0 && $officeName !== '') {
    $stmt = $pdo->prepare(
        'SELECT e.*, u.first_name, u.last_name
         FROM evaluations e
         INNER JOIN applications a ON a.application_id = e.application_id
         INNER JOIN students s ON s.student_id = a.student_id
         INNER JOIN users u ON u.user_id = s.user_id
         WHERE e.term_id = :term_id
           AND a.preferred_office = :office_name
         ORDER BY e.submitted_at DESC
         LIMIT 10'
    );
    $stmt->execute(['term_id' => $activeTermId, 'office_name' => $officeName]);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

echo json_encode(['success' => true, 'students' => $students, 'recent' => $recent]);
