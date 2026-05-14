<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function sams_health_ok(string $message): void
{
    echo "[OK] {$message}\n";
}

function sams_health_warn(string $message): void
{
    echo "[WARN] {$message}\n";
}

function sams_health_fail(string $message): void
{
    echo "[FAIL] {$message}\n";
}

function sams_health_expect_columns(PDO $pdo, string $table, array $columns): array
{
    $existing = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[] = (string) ($row['Field'] ?? '');
    }

    $missing = [];
    foreach ($columns as $column) {
        if (!in_array($column, $existing, true)) {
            $missing[] = $column;
        }
    }

    return $missing;
}

function sams_health_run_query(PDO $pdo, string $label, string $sql, array $params = [], bool $warnWhenEmpty = false): bool
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($rows);

        if ($count > 0) {
            sams_health_ok("{$label} ({$count} row" . ($count === 1 ? '' : 's') . ")");
        } elseif ($warnWhenEmpty) {
            sams_health_warn("{$label} returned no rows");
        } else {
            sams_health_ok("{$label} ran successfully");
        }

        return true;
    } catch (Throwable $exception) {
        sams_health_fail("{$label}: " . $exception->getMessage());
        return false;
    }
}

$failed = false;

try {
    $pdo = sams_pdo();
    sams_health_ok('Database connection is available');
} catch (Throwable $exception) {
    sams_health_fail('Database connection failed: ' . $exception->getMessage());
    exit(1);
}

$schemaChecks = [
    'users' => ['user_id', 'email', 'password_hash', 'role', 'is_active'],
    'students' => ['student_id', 'user_id', 'student_id_number', 'is_enrolled'],
    'applications' => ['application_id', 'student_id', 'term_id', 'preferred_office', 'status'],
    'duty_schedules' => ['duty_id', 'application_id', 'office_name', 'term_id', 'status'],
    'attendance_logs' => ['log_id', 'application_id', 'term_id', 'duty_id', 'clock_in_time', 'clock_out_time', 'status', 'late_minutes', 'notes', 'created_at'],
    'student_reports' => ['report_id', 'application_id', 'duty_id', 'student_code', 'reporter_id', 'title', 'notes', 'status', 'is_new', 'created_at'],
];

foreach ($schemaChecks as $table => $columns) {
    try {
        $missing = sams_health_expect_columns($pdo, $table, $columns);
        if ($missing === []) {
            sams_health_ok("{$table} schema matches expected columns");
        } else {
            sams_health_fail("{$table} missing columns: " . implode(', ', $missing));
            $failed = true;
        }
    } catch (Throwable $exception) {
        sams_health_fail("{$table} schema check failed: " . $exception->getMessage());
        $failed = true;
    }
}

$roleCounts = [
    'admin' => 0,
    'supervisor' => 0,
    'student' => 0,
];

try {
    $stmt = $pdo->query("SELECT role, COUNT(*) AS total FROM users WHERE role IN ('admin', 'supervisor', 'student') GROUP BY role");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $role = (string) ($row['role'] ?? '');
        if (array_key_exists($role, $roleCounts)) {
            $roleCounts[$role] = (int) ($row['total'] ?? 0);
        }
    }

    foreach ($roleCounts as $role => $count) {
        if ($count > 0) {
            sams_health_ok("{$role} accounts available ({$count})");
        } else {
            sams_health_warn("No {$role} accounts found");
        }
    }
} catch (Throwable $exception) {
    sams_health_fail('Role count check failed: ' . $exception->getMessage());
    $failed = true;
}

$queryChecks = [
    [
        'label' => 'Admin can read student detail link path',
        'sql' => 'SELECT s.student_id, s.student_id_number, u.email, a.application_id, a.preferred_office
                FROM students s
                INNER JOIN users u ON u.user_id = s.user_id
                LEFT JOIN applications a ON a.student_id = s.student_id
                ORDER BY s.student_id DESC
                LIMIT 5',
        'warnWhenEmpty' => true,
    ],
    [
        'label' => 'Student dashboard can resolve schedules',
        'sql' => 'SELECT s.student_id, s.student_id_number, a.application_id, a.preferred_office, ds.duty_id, ds.office_name
                FROM students s
                LEFT JOIN applications a ON a.student_id = s.student_id
                LEFT JOIN duty_schedules ds ON ds.application_id = a.application_id
                ORDER BY s.student_id DESC
                LIMIT 5',
        'warnWhenEmpty' => true,
    ],
    [
        'label' => 'Supervisor dashboard can resolve office assignments',
        'sql' => 'SELECT sup.office_name, u.email, a.application_id, a.preferred_office, ds.duty_id
                FROM supervisors sup
                INNER JOIN users u ON u.user_id = sup.user_id
                LEFT JOIN applications a ON a.preferred_office = sup.office_name
                LEFT JOIN duty_schedules ds ON ds.application_id = a.application_id
                ORDER BY sup.supervisor_id DESC
                LIMIT 5',
        'warnWhenEmpty' => true,
    ],
    [
        'label' => 'Admin report views can read supervisor reports',
        'sql' => 'SELECT sr.report_id, sr.created_at, sr.status, u.email AS reporter_email, a.preferred_office
                FROM student_reports sr
                LEFT JOIN users u ON u.user_id = sr.reporter_id
                LEFT JOIN applications a ON a.application_id = sr.application_id
                ORDER BY sr.report_id DESC
                LIMIT 5',
        'warnWhenEmpty' => true,
    ],
    [
        'label' => 'Supervisor report path can link student applications',
        'sql' => 'SELECT s.student_id, s.student_id_number, a.application_id, a.preferred_office, ds.duty_id
                FROM students s
                INNER JOIN applications a ON a.student_id = s.student_id
                LEFT JOIN duty_schedules ds ON ds.application_id = a.application_id
                ORDER BY a.application_id DESC
                LIMIT 5',
        'warnWhenEmpty' => true,
    ],
];

foreach ($queryChecks as $check) {
    $passed = sams_health_run_query(
        $pdo,
        $check['label'],
        $check['sql'],
        [],
        (bool) $check['warnWhenEmpty']
    );

    if (!$passed) {
        $failed = true;
    }
}

if ($failed) {
    sams_health_fail('Daily health check completed with errors');
    exit(1);
}

sams_health_ok('Daily health check completed successfully');
exit(0);