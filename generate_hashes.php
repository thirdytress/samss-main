<?php
// Generate proper password hashes
$admin_password = 'admin123';
$supervisor_password = 'supervisor123';

$admin_hash = password_hash($admin_password, PASSWORD_DEFAULT);
$supervisor_hash = password_hash($supervisor_password, PASSWORD_DEFAULT);

echo "Admin hash: $admin_hash\n";
echo "Supervisor hash: $supervisor_hash\n";
?>
