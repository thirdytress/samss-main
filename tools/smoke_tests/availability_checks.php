<?php
// Smoke checks for availability-related schema and basic invariants.
// Run from CLI: php tools/smoke_tests/availability_checks.php

require_once __DIR__ . '/../../config/bootstrap.php';

function smoke_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

$errors = [];
try {
    $pdo = sams_pdo();

    // Check availability table exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'availability'");
    $exists = (int) $stmt->fetchColumn();
    if ($exists === 0) {
        $errors[] = "Table 'availability' does not exist in the current database.";
    } else {
        // Check required columns, accepting both naming variants used by the app.
        $required = ['application_id','term_id','day_of_week','notes'];
        foreach ($required as $col) {
            if (!smoke_column_exists($pdo, 'availability', $col)) {
                $errors[] = "Missing column 'availability.{$col}'.";
            }
        }

        $hasTimeStart = smoke_column_exists($pdo, 'availability', 'time_start') || smoke_column_exists($pdo, 'availability', 'start_time');
        $hasTimeEnd = smoke_column_exists($pdo, 'availability', 'time_end') || smoke_column_exists($pdo, 'availability', 'end_time');
        if (!$hasTimeStart) {
            $errors[] = "Missing availability start-time column (expected 'time_start' or 'start_time').";
        }
        if (!$hasTimeEnd) {
            $errors[] = "Missing availability end-time column (expected 'time_end' or 'end_time').";
        }

        // If day_of_week is enum, keep it compatible with the Mon-Sat availability policy.
        $colStmt = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'availability' AND COLUMN_NAME = 'day_of_week'");
        $colStmt->execute();
        $colType = $colStmt->fetchColumn();
        if ($colType) {
            if (stripos($colType, 'enum(') !== false) {
                if (stripos($colType, "'Sunday'") !== false) {
                    $errors[] = "'day_of_week' enum still contains 'Sunday'. The availability flow is Mon-Sat only; consider cleaning the schema if you want strict enforcement.";
                }
            }
        }

        // Basic check: at least one application exists
        $appCount = (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();
        if ($appCount === 0) {
            $errors[] = "No applications found in the database — create a test application to run full integration checks.";
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Exception while checking DB: ' . $e->getMessage();
}

if (!empty($errors)) {
    echo "Smoke check found issues:\n";
    foreach ($errors as $err) {
        echo " - $err\n";
    }
    exit(1);
}

echo "Smoke checks passed. Schema and basic invariants look OK.\n";
return 0;
