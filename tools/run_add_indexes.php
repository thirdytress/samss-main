<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$pdo = sams_pdo();

$indexes = [
    [
        'table' => 'applications',
        'name' => 'idx_app_office_term',
        'cols' => 'preferred_office, term_id',
    ],
    [
        'table' => 'duty_schedules',
        'name' => 'idx_ds_term_day_status',
        'cols' => 'term_id, day_of_week, status',
    ],
    [
        'table' => 'attendance_logs',
        'name' => 'idx_al_app_duty_log',
        'cols' => 'application_id, duty_id, log_id',
    ],
];

echo "Running safe index migration\n";

foreach ($indexes as $idx) {
    $table = $idx['table'];
    $name = $idx['name'];
    $cols = $idx['cols'];

    echo "\nChecking {$table}.{$name}... ";

    $check = $pdo->prepare('SHOW INDEX FROM ' . $table . ' WHERE Key_name = :key');
    $check->execute(['key' => $name]);
    if ($check->fetch(PDO::FETCH_ASSOC)) {
        echo "already exists — skipping\n";
        continue;
    }

    // Try online DDL first (inplace, lock none) then fall back to plain ALTER
    $sqlOnline = sprintf("ALTER TABLE %s ADD INDEX %s (%s) ALGORITHM=INPLACE, LOCK=NONE", $table, $name, $cols);
    $sqlPlain = sprintf("ALTER TABLE %s ADD INDEX %s (%s)", $table, $name, $cols);

    try {
        $pdo->exec($sqlOnline);
        echo "added (online)\n";
    } catch (PDOException $e) {
        echo "online DDL failed: {$e->getMessage()}\n";
        try {
            $pdo->exec($sqlPlain);
            echo "added (plain)\n";
        } catch (PDOException $e2) {
            echo "FAILED to add index {$name} on {$table}: {$e2->getMessage()}\n";
            echo "You can manually run: {$sqlPlain}\n";
            continue;
        }
    }

    // Verify
    $verify = $pdo->prepare('SHOW INDEX FROM ' . $table . ' WHERE Key_name = :key');
    $verify->execute(['key' => $name]);
    $row = $verify->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "Verified: {$table}.{$name} present\n";
    } else {
        echo "Warning: verification failed for {$table}.{$name}\n";
    }
}

echo "\nCompleted index migration run.\n";

echo "\nTo rollback (DROP) run these commands if needed:\n";
foreach ($indexes as $idx) {
    printf("ALTER TABLE %s DROP INDEX %s;\n", $idx['table'], $idx['name']);
}

// Show indexes summary
echo "\nIndexes summary:\n";
foreach ($indexes as $idx) {
    $stmt = $pdo->query('SHOW INDEX FROM ' . $idx['table']);
    $found = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['Key_name'] === $idx['name']) {
            $found = true;
            break;
        }
    }
    echo sprintf(" - %s.%s: %s\n", $idx['table'], $idx['name'], $found ? 'present' : 'missing');
}

exit(0);
