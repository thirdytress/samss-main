<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$pdo = sams_pdo();

// Detect password column
$passwordColumn = null;
$stmt = $pdo->prepare('SHOW COLUMNS FROM users');
$stmt->execute();
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['Field'] === 'password' || $row['Field'] === 'password_hash') {
        $passwordColumn = $row['Field'];
        break;
    }
}

if ($passwordColumn === null) {
    die("ERROR: Could not find password column in users table\n");
}

echo "Using password column: $passwordColumn\n\n";

// Update admin and specific named supervisors
$updates = [
    'admin@sams.local' => 'admin123',
    'supervisor@sams.local' => 'supervisor123',
];

$statement = $pdo->prepare(
    "UPDATE users 
     SET $passwordColumn = :password 
     WHERE email = :email"
);

foreach ($updates as $email => $password) {
    $result = $statement->execute([
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    
    if ($result) {
        echo "✓ Updated {$email}\n";
    } else {
        echo "✗ No rows updated for {$email} (may not exist)\n";
    }
}

// Update all supervisors with supervisor123
echo "\nUpdating ALL supervisors with password 'supervisor123'...\n";
$supervisorStmt = $pdo->prepare(
    "UPDATE users 
     SET $passwordColumn = :password 
     WHERE role = 'supervisor'"
);

$result = $supervisorStmt->execute([
    'password' => password_hash('supervisor123', PASSWORD_DEFAULT),
]);

echo "✓ Updated all supervisor accounts\n";

// Verify
echo "\n=== Verification ===\n";
foreach ($pdo->query('SELECT email, role FROM users WHERE role IN ("admin", "supervisor") ORDER BY email') as $row) {
    echo "  {$row['email']} ({$row['role']})\n";
}
