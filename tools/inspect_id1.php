<?php
declare(strict_types=1);
require __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$tables = ['users','admins','supervisors','students','applications','availability','duty_schedules'];
foreach ($tables as $table) {
    $idColumn = in_array($table, ['users']) ? 'user_id' : 'id';
    try {
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$idColumn} = 1 LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo "TABLE={$table}\n";
            print_r($row);
            echo "\n";
        }
    } catch (Throwable $e) {
        // ignore tables where the guessed PK column is not correct
    }
}
