<?php
require_once 'config/database.php';
$pdo = sams_pdo();
$stmt = $pdo->query('DESCRIBE students');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . " (" . $row['Type'] . ") " . $row['Null'] . "\n";
}
