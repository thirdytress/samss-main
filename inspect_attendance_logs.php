<?php
require_once 'config/database.php';

$pdo = sams_pdo();

// Get actual table structure
$stmt = $pdo->query("DESCRIBE attendance_logs");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== ACTUAL attendance_logs COLUMNS ===\n";
foreach ($columns as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ") " . ($col['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
}

// Also check with INFORMATION_SCHEMA for more detail
echo "\n=== FULL COLUMN INFO ===\n";
$stmt2 = $pdo->prepare("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'sams_db' AND TABLE_NAME = 'attendance_logs'
");
$stmt2->execute();
$colInfo = $stmt2->fetchAll(PDO::FETCH_ASSOC);
foreach ($colInfo as $col) {
    echo $col['COLUMN_NAME'] . ": " . $col['COLUMN_TYPE'] . " (Null: " . $col['IS_NULLABLE'] . ", Default: " . ($col['COLUMN_DEFAULT'] ?? 'none') . ")\n";
}
