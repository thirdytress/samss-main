<?php
require __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
try {
    $c1 = $pdo->exec("DELETE FROM availability WHERE day_of_week = 'Sunday'");
    $c2 = $pdo->exec("DELETE FROM duty_schedules WHERE day_of_week = 'Sunday'");

    $pdo->exec("ALTER TABLE availability MODIFY day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL");
    $pdo->exec("ALTER TABLE duty_schedules MODIFY day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL");

    echo json_encode(['deleted_availability' => $c1, 'deleted_duty_schedules' => $c2]) . PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
