<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

// Accept JSON body or form POST. Also support CLI key=value args for testing.
$input = null;
if (PHP_SAPI === 'cli') {
    // parse key=value pairs from argv
    $args = $argv;
    array_shift($args);
    $input = [];
    foreach ($args as $a) {
        if (strpos($a, '=') !== false) {
            [$k, $v] = explode('=', $a, 2);
            $input[$k] = $v;
        }
    }
} else {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $input = $json;
    } else {
        $input = $_POST;
    }
}

$pdo = sams_pdo();

// Determine supervisor identity
$currentUser = sams_authenticated_user();
$supervisorId = 0;
if ($currentUser && ($currentUser['role'] ?? null) === 'supervisor') {
    $stmt = $pdo->prepare('SELECT supervisor_id FROM supervisors WHERE user_id = :uid LIMIT 1');
    $stmt->execute(['uid' => (int) ($currentUser['user_id'] ?? 0)]);
    $supervisorId = (int) ($stmt->fetchColumn() ?: 0);
}

// CLI override: allow --supervisor_id
if (PHP_SAPI === 'cli' && isset($input['supervisor_id'])) {
    $supervisorId = (int) $input['supervisor_id'];
}

if ($supervisorId <= 0) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

$applicationId = (int) ($input['application_id'] ?? 0);
$termId = (int) ($input['term_id'] ?? 0);
$performance = (int) ($input['performance_rating'] ?? ($input['rating'] ?? 0));
$reliability = (int) ($input['reliability_rating'] ?? ($input['reliability'] ?? 0));
$professionalism = (int) ($input['professionalism_rating'] ?? ($input['professionalism'] ?? 0));
$comments = trim((string) ($input['comments'] ?? ''));

if ($termId <= 0) {
    $termId = (int) ($pdo->query('SELECT term_id FROM terms WHERE is_active = 1 ORDER BY term_id DESC LIMIT 1')->fetchColumn() ?: 0);
}

// Basic validation
$errors = [];
if ($applicationId <= 0) $errors[] = 'Invalid application_id';
foreach (['performance' => $performance, 'reliability' => $reliability, 'professionalism' => $professionalism] as $k => $v) {
    if ($v < 1 || $v > 5) $errors[] = "$k rating must be 1-5";
}
if ($termId <= 0) $errors[] = 'No active term';

if ($errors) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => implode('; ', $errors)]);
        exit;
    } else {
        echo "ERROR: " . implode('; ', $errors) . "\n";
        exit(1);
    }
}

// Ensure evaluations table exists
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS evaluations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        application_id INT NOT NULL,
        term_id INT NOT NULL,
        supervisor_id INT NOT NULL,
        performance_rating TINYINT NOT NULL,
        reliability_rating TINYINT NOT NULL,
        professionalism_rating TINYINT NOT NULL,
        comments TEXT NULL,
        submitted_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (application_id),
        INDEX (supervisor_id),
        INDEX (term_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

try {
    $ins = $pdo->prepare(
        'INSERT INTO evaluations (application_id, term_id, supervisor_id, performance_rating, reliability_rating, professionalism_rating, comments, submitted_at)
         VALUES (:application_id, :term_id, :supervisor_id, :performance_rating, :reliability_rating, :professionalism_rating, :comments, NOW())'
    );
    $ins->execute([
        'application_id' => $applicationId,
        'term_id' => $termId,
        'supervisor_id' => $supervisorId,
        'performance_rating' => $performance,
        'reliability_rating' => $reliability,
        'professionalism_rating' => $professionalism,
        'comments' => $comments,
    ]);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo "Saved evaluation for application_id={$applicationId}\n";
        exit(0);
    }
} catch (Throwable $e) {
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    } else {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}
