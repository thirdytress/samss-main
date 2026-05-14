<?php
require_once __DIR__ . '/config/bootstrap.php';

echo "=== Announcements DB Setup ===\n\n";

try {
    $pdo = sams_pdo();
    
    // Check if tables exist
    $result = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='sams_db' AND TABLE_NAME IN ('announcements', 'announcement_reads')");
    $existingTables = $result->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('announcements', $existingTables)) {
        echo "[1/2] Creating announcements table...\n";
        $pdo->exec("
            CREATE TABLE `announcements` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `title` VARCHAR(255) NOT NULL,
                `body` LONGTEXT NOT NULL,
                `audience` ENUM('students','supervisors','all') NOT NULL DEFAULT 'students',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_ann_active_audience_created_at` (`is_active`, `audience`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✓ announcements table created\n";
    } else {
        echo "✓ announcements table already exists\n";
    }
    
    if (!in_array('announcement_reads', $existingTables)) {
        echo "[2/2] Creating announcement_reads table...\n";
        $pdo->exec("
            CREATE TABLE `announcement_reads` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `announcement_id` INT UNSIGNED NOT NULL,
                `user_id` INT NOT NULL,
                `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_announcement_user` (`announcement_id`, `user_id`),
                KEY `idx_ann_id` (`announcement_id`),
                KEY `idx_user_id` (`user_id`),
                CONSTRAINT `fk_ann_read_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✓ announcement_reads table created\n";
    } else {
        echo "✓ announcement_reads table already exists\n";
    }
    
    // Verify
    echo "\n=== Verification ===\n";
    $check = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='sams_db' AND TABLE_NAME IN ('announcements', 'announcement_reads')");
    $tables = $check->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables found: " . implode(', ', $tables) . "\n";
    echo "\n✓ Setup complete! Go to /admin/announcements.php\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
