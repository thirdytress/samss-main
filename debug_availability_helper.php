<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/availability.php';

try {
    $pdo = sams_pdo();
    $applicationIdColumn = sams_availability_first_existing_column($pdo, 'applications', ['id', 'application_id']);

    if ($applicationIdColumn === null) {
        throw new RuntimeException('Applications table does not expose a supported identifier column.');
    }

    $latest = $pdo->query('SELECT ' . $applicationIdColumn . ' AS application_id, term_id FROM applications ORDER BY ' . $applicationIdColumn . ' DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

    if (!$latest) {
        throw new RuntimeException('No application record found.');
    }

    $result = sams_save_availability((int) $latest['application_id'], (int) $latest['term_id'], [
        ['day_of_week' => 'Monday', 'start_time' => '08:00', 'end_time' => '12:00', 'enabled' => true],
        ['day_of_week' => 'Wednesday', 'start_time' => '13:00', 'end_time' => '17:00', 'enabled' => true],
    ]);

    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    echo 'ERROR: ' . $exception->getMessage() . PHP_EOL;
}
