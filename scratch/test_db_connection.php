<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== SAMS DATABASE DIAGNOSTIC ===\n";

// Load configuration
if (!file_exists(__DIR__ . '/../config/database.php')) {
    echo "ERROR: config/database.php not found.\n";
    exit(1);
}
require_once __DIR__ . '/../config/database.php';

try {
    $config = sams_db_config();
    echo "Configured DSN: " . ($config['dsn'] ?: 'default') . "\n";
    echo "Host: " . $config['host'] . "\n";
    echo "Port: " . $config['port'] . "\n";
    echo "Database Name: " . $config['name'] . "\n";
    echo "User: " . $config['user'] . "\n";

    // Attempt PDO connection
    $pdo = sams_pdo();
    echo "✓ Database Connection Successful via sams_pdo()!\n";

    // Query list of tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Tables in '" . $config['name'] . "':\n";
    foreach ($tables as $table) {
        // Query record count for table
        $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "  - $table ($count records)\n";
    }

    // Verify if students table has nfc_uid column
    $colStmt = $pdo->query("DESCRIBE students");
    $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    $hasNfcUid = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'nfc_uid') {
            $hasNfcUid = true;
            break;
        }
    }
    if ($hasNfcUid) {
        echo "✓ 'nfc_uid' column exists in 'students' table!\n";
    } else {
        echo "✗ 'nfc_uid' column is missing in 'students' table.\n";
    }

} catch (Throwable $e) {
    echo "✗ DATABASE ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
}
?>
