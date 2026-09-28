<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

$stmt = $pdo->query('
    SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, EXTRA
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE "%on update%"
');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
