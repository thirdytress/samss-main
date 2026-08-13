<?php
declare(strict_types=1);
// Simple migration runner to execute SQL files in tools/migrations
require_once __DIR__ . '/../config/bootstrap.php';

$dir = __DIR__ . '/migrations';
$pdo = sams_pdo();

foreach (glob($dir . '/*.sql') as $file) {
    echo "Running migration: $file\n";
    $sql = file_get_contents($file);
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (PDOException $e) {
        echo "Error: " . $e->getMessage() . "\n";
        // continue to next migration
    }
}

echo "Done.\n";
