<?php
require_once __DIR__ . '/../config/bootstrap.php';

try {
    $res = sams_authenticate('admin@sams.local', 'student123');
    var_export($res);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
