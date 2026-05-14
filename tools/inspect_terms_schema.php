<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$stmt = $pdo->query('DESCRIBE terms');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . PHP_EOL;
}
