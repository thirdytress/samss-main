<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

// Create announcements table directly
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `body` LONGTEXT NOT NULL,
    `audience` ENUM('students','supervisors','all') NOT NULL DEFAULT 'students',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Create announcement_reads table
$pdo->exec("
  CREATE TABLE IF NOT EXISTS `announcement_reads` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `announcement_id` INT UNSIGNED NOT NULL,
    `user_id` INT NOT NULL,
    `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY (`announcement_id`),
    KEY (`user_id`),
    UNIQUE KEY `ux_announcement_user` (`announcement_id`, `user_id`),
    CONSTRAINT `fk_ann_read_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Add index to announcements
$pdo->exec("ALTER TABLE `announcements` ADD INDEX `idx_ann_active_audience_created_at` (`is_active`, `audience`, `created_at`)");

echo "Tables created successfully.\n";
?>
