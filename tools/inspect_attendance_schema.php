<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
foreach (['attendance_logs', 'students', 'applications'] as $table) {
    echo "=== $table ===\n";
    $stmt = $pdo->query("DESCRIBE `$table`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . '\n';
    }
    echo "\n";
}
