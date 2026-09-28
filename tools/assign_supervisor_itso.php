<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();

echo "Listing supervisors:\n";
$rows = $pdo->query('SELECT s.supervisor_id, s.user_id, s.office_name, u.email FROM supervisors s LEFT JOIN users u ON u.user_id = s.user_id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo " - supervisor_id={$r['supervisor_id']} user_id={$r['user_id']} email={$r['email']} office=" . ($r['office_name'] ?? 'NULL') . "\n";
}
if (empty($rows)) {
    echo "No supervisors found.\n";
    exit(1);
}

$target = $rows[0];
$supervisorId = (int) $target['supervisor_id'];
$userId = (int) $target['user_id'];

echo "\nUpdating supervisor_id={$supervisorId} and user_id={$userId} to office 'ITSO'...\n";
$upd = $pdo->prepare('UPDATE supervisors SET office_name = :office WHERE supervisor_id = :sid');
$upd->execute(['office' => 'ITSO', 'sid' => $supervisorId]);
// Update users.office_name only if the column exists
$colStmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'office_name'");
$colStmt->execute();
if ($colStmt->fetch()) {
    $upd2 = $pdo->prepare('UPDATE users SET office_name = :office WHERE user_id = :uid');
    $upd2->execute(['office' => 'ITSO', 'uid' => $userId]);
    echo "Updated users.office_name for user_id={$userId}\n";
} else {
    echo "users.office_name column not present; skipped updating users table.\n";
}

// Normalize applications that mention ITSO
$before = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE LOWER(COALESCE(preferred_office,'') ) LIKE '%itso%'")->fetchColumn();
echo "Applications mentioning ITSO before: {$before}\n";
$norm = $pdo->prepare("UPDATE applications SET preferred_office = 'ITSO' WHERE LOWER(COALESCE(preferred_office,'')) LIKE '%itso%'");
$norm->execute();
$after = (int) $pdo->query("SELECT COUNT(*) FROM applications WHERE preferred_office = 'ITSO'")->fetchColumn();

echo "Applications set to ITSO: {$after}\n";

// Verify supervisor row
$sv = $pdo->prepare('SELECT s.supervisor_id, s.office_name, u.email FROM supervisors s LEFT JOIN users u ON u.user_id = s.user_id WHERE s.supervisor_id = :sid LIMIT 1');
$sv->execute(['sid' => $supervisorId]);
$svr = $sv->fetch(PDO::FETCH_ASSOC);
if ($svr) {
    echo "Supervisor now: id={$svr['supervisor_id']} email={$svr['email']} office={$svr['office_name']}\n";
}

echo "Done.\n";
