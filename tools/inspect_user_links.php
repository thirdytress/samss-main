<?php
declare(strict_types=1);
require __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$studentId = 1;
 $stmt = $pdo->prepare('SELECT application_id, student_id, term_id, status, preferred_office FROM applications WHERE student_id = :student_id');
$stmt->execute(['student_id' => $studentId]);
$apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['applications' => $apps], JSON_PRETTY_PRINT) . PHP_EOL;
