<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

try {
    // ensure a session admin user for the endpoint auth check
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['sams_user'] = [
        'user_id' => 1,
        'role' => 'admin',
        'email' => 'admin@sams.local',
        'name' => 'Test Admin'
    ];

    $pdo = sams_pdo();
    $stmt = $pdo->query('SELECT application_id FROM applications ORDER BY application_id DESC LIMIT 1');
    $applicationId = (int) $stmt->fetchColumn();

    if ($applicationId <= 0) {
        echo "NO_APPLICATION\n";
        exit(0);
    }

    // simulate GET and include endpoint to capture JSON output
    $_GET['application_id'] = $applicationId;
    ob_start();
    include __DIR__ . '/application_detail.php';
    $json = ob_get_clean();

    echo "APPLICATION_ID: " . $applicationId . "\n";
    echo $json . "\n";
    // optionally pretty print
    $data = json_decode($json, true);
    if (is_array($data)) {
        echo "DOCUMENTS: " . (int) count($data['documents'] ?? []) . "\n";
        echo "AVAILABILITY: " . (int) count($data['availability'] ?? []) . "\n";
    }

    exit(0);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
