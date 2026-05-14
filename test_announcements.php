<?php
require_once 'config/bootstrap.php';
$pdo = sams_pdo();
$stmt = $pdo->query('SELECT id, title, audience, is_active, created_at FROM announcements ORDER BY created_at DESC LIMIT 10');
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($results, JSON_PRETTY_PRINT);
