<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mail.php';

try {
    sams_send_application_review_email('martynjosephseloterio@gmail.com', 'Martyn', 'approved');
    echo "SENT_OK\n";
} catch (Throwable $e) {
    echo "SENT_ERROR: " . $e->getMessage() . "\n";
}
