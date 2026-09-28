<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pdo = sams_pdo();
$admin = $pdo->query('SELECT user_id, email, role FROM users WHERE role="admin" LIMIT 1')->fetch(PDO::FETCH_ASSOC);

$_SESSION['sams_user'] = [
    'user_id' => $admin['user_id'],
    'email' => $admin['email'],
    'role' => 'admin'
];

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['nfc_uid'] = '1144250055'; // Martyn Joseph Seloterio

ob_start();
include __DIR__ . '/../admin/nfc_clock_process.php';
$response = ob_get_clean();

echo "Response from nfc_clock_process.php:\n$response\n";
