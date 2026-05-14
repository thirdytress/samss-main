<?php
declare(strict_types=1);

function sams_first_existing_column(PDO $pdo, string $table, array $columns): ?string
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $stmt->execute();
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[] = $row['Field'];
    }

    foreach ($columns as $col) {
        if (in_array($col, $existing, true)) {
            return $col;
        }
    }

    return null;
}

function sams_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function sams_normalize_name(?string $firstName, ?string $lastName): string
{
    $fullName = trim((string) $firstName . ' ' . (string) $lastName);
    return $fullName !== '' ? $fullName : 'SAMS User';
}

function sams_authenticated_user(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    return $_SESSION['sams_user'] ?? null;
}

function sams_login(array $user): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['sams_user'] = [
        'user_id' => (int) $user['id'],
        'role' => $user['role'],
        'email' => $user['email'],
        'name' => sams_normalize_name($user['first_name'] ?? null, $user['last_name'] ?? null),
        'student_id' => $user['student_id'] ?? null,
        'office_name' => $user['office_name'] ?? null,
        'must_change_password' => (int) ($user['must_change_password'] ?? 0),
    ];

    if ($user['role'] === 'admin') {
        $_SESSION['admin_id'] = (int) $user['id'];
    }

    if ($user['role'] === 'supervisor') {
        $_SESSION['supervisor_id'] = (int) $user['id'];
    }

    if ($user['role'] === 'student') {
        $_SESSION['student_id'] = $user['student_id'] ?? null;
    }
}

function sams_authenticate(string $identifier, string $password): array
{
    $pdo = sams_pdo();
    $passwordColumn = sams_first_existing_column($pdo, 'users', ['password', 'password_hash']);
    $mustChangePasswordColumn = sams_first_existing_column($pdo, 'users', ['must_change_password']);
    $userIdColumn = sams_first_existing_column($pdo, 'users', ['id', 'user_id']);
    
    if ($passwordColumn === null) {
        throw new RuntimeException('Users table missing password column.');
    }

    if ($userIdColumn === null) {
        throw new RuntimeException('Users table missing id or user_id column.');
    }

    if (!sams_first_existing_column($pdo, 'students', ['student_id_number'])) {
        throw new RuntimeException('Students table missing student_id_number column.');
    }

    $mustChangePasswordSelect = $mustChangePasswordColumn !== null
        ? 'u.' . $mustChangePasswordColumn . ' AS must_change_password'
        : '0 AS must_change_password';

     $statement = $pdo->prepare(
          "SELECT
                u.{$userIdColumn} AS id,
                u.email,
                u.role,
                u.first_name,
                u.last_name,
                u.is_active,
                u.{$passwordColumn} AS password_stored,
                {$mustChangePasswordSelect},
                s.student_id,
                s.student_id_number
            FROM users u
            LEFT JOIN students s ON u.{$userIdColumn} = s.user_id
            WHERE u.email = :identifier_email
                OR s.student_id_number = :identifier_student
            LIMIT 1"
     );
    $statement->execute([
        'identifier_email' => $identifier,
        'identifier_student' => $identifier,
    ]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('Invalid email or student ID.');
    }

    if (!password_verify($password, (string) ($user['password_stored'] ?? ''))) {
        throw new RuntimeException('Invalid password.');
    }

    if (!$user['is_active']) {
        throw new RuntimeException('Your account is not active. Please contact support.');
    }

    return [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'student_id' => $user['student_id'] ?? null,
        'student_id_number' => $user['student_id_number'] ?? null,
        'must_change_password' => (int) ($user['must_change_password'] ?? 0),
    ];
}

function sams_dashboard_for_role(string $role): string
{
    return match ($role) {
        'admin' => 'admin/dashboard.php',
        'supervisor' => 'supervisor/dashboard.php',
        'student' => 'students/dashboard.php',
        default => 'index.php',
    };
}


