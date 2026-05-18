<?php
require 'config/bootstrap.php';
$pdo = sams_pdo();
$stmt = $pdo->prepare('UPDATE users SET must_change_password = 0 WHERE user_id = 24');
$stmt->execute();
var_dump($stmt->rowCount());
