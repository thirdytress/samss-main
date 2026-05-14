<?php
declare(strict_types=1);
require __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
foreach (['users','students'] as $table) {
    echo "TABLE=$table\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo $row['Field'] . ':' . $row['Type'] . PHP_EOL;
    }
    echo PHP_EOL;
}
