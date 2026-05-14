<?php
declare(strict_types=1);

// Load environment from .env (optional) so deployments can use a simple file
// for local/dev without touching system env vars.
if (file_exists(__DIR__ . '/environment.php')) {
    require_once __DIR__ . '/environment.php';
}

$timezone = getenv('APP_TIMEZONE') ?: 'Asia/Manila';
if (!date_default_timezone_set($timezone)) {
	date_default_timezone_set('UTC');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/attendance.php';

function sams_attendance_late_threshold(): int
{
    return 10;
}
