<?php
require_once 'config/database.php';

$pdo = sams_pdo();

// Check if supervisors table exists and get its structure
$stmt = $pdo->query(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = 'sams_db' AND TABLE_NAME = 'supervisors'"
);
$supervisorsExist = $stmt->fetch() ? true : false;

if ($supervisorsExist) {
    echo "=== supervisors TABLE EXISTS ===\n";
    $stmt2 = $pdo->query("DESCRIBE supervisors");
    $columns = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ") " . ($col['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    }
} else {
    echo "supervisors table DOES NOT EXIST\n";
}

// Also check evaluations table
echo "\n=== evaluations TABLE ===\n";
$stmt3 = $pdo->query("DESCRIBE evaluations");
$columns3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns3 as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ") " . ($col['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
}
