<?php
declare(strict_types=1);
// Force apply migrations with detailed output
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();

// 1. Apply create_announcements.sql
$sql1 = file_get_contents(__DIR__ . '/create_announcements.sql');
try {
    $pdo->exec($sql1);
    echo "✓ create_announcements.sql applied\n";
} catch (PDOException $e) {
    echo "✗ create_announcements.sql error: " . $e->getMessage() . "\n";
}

// 2. Apply 002_add_ann_constraints.sql
$sql2 = file_get_contents(__DIR__ . '/migrations/002_add_ann_constraints.sql');
try {
    $pdo->exec($sql2);
    echo "✓ 002_add_ann_constraints.sql applied\n";
} catch (PDOException $e) {
    echo "✗ 002_add_ann_constraints.sql error: " . $e->getMessage() . "\n";
}

// 3. Verify tables exist
$stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='sams_db' AND TABLE_NAME LIKE 'announcement%'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "\nTables created:\n";
foreach ($tables as $table) {
    echo "  - $table\n";
}

echo "\nMigrations complete.\n";
?>
