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
require_once __DIR__ . '/admin_meetings.php';

function sams_attendance_late_threshold(): int
{
    return 10;
}

function sams_office_options(): array
{
    return [
        'PHYSICAL FACILITIES MANAGEMENT OFFICE',
        'ASSET MANAGEMENT OFFICE',
        'ADMISSION OFFICE',
        'MARKETING OFFICE',
        'ACADEME INDUSTRY LINKAGES AND PARTNERSHIP OFFICE',
        'STUDENT DEVELOPMENT AND ACTIVITIES OFFICE',
        'STUDENT DISCIPLINE OFFICE',
        'COMMUNITY EXTENSION OFFICE',
        'TREASURY OFFICE',
        'ACCOUNTING OFFICE',
        'REGISTRAR OFFICE',
        'EXECUTIVE DIRECTOR',
        'ACADEMIC DIRECTOR',
        'RESEARCH OFFICE',
        'FACULTY ADMINISTRATION OFFICE',
        'HUMAN RESOURCE OFFICE',
        'LEARNING RESOURCE CENTER',
        'GUIDANCE SERVICE OFFICE',
        'QUALITY MANAGEMENT OFFICE',
        'SABM',
        'SACE',
        'SAHS - RLE',
        'SAHS LABTECH',
        'BSMT LABTECH',
        'BSCE LABTECH',
        'HEALTH SERVICES OFFICE',
        'INFORMATION TECHNOLOGY SERVICES OFFICE',
    ];
}
