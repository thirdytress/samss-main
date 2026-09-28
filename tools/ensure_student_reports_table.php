<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS student_reports (
        report_id INT AUTO_INCREMENT PRIMARY KEY,
        application_id INT NULL,
        duty_id INT NULL,
        student_code VARCHAR(128) NULL,
        reporter_id INT NOT NULL,
        title VARCHAR(255) NULL,
        notes TEXT,
        status VARCHAR(32) NOT NULL DEFAULT 'open',
        is_new TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "student_reports ensured\n";
$stmt = $pdo->query('SHOW COLUMNS FROM student_reports');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . ' | ' . $row['Default'] . PHP_EOL;
}
