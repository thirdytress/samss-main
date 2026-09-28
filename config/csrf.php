<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function sams_csrf_token(): string
{
    if (empty($_SESSION['sams_csrf_token'])) {
        $_SESSION['sams_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['sams_csrf_token'];
}

function sams_csrf_input_field(): string
{
    $token = sams_csrf_token();
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function sams_verify_csrf(?string $token): bool
{
    if (empty($token)) return false;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return hash_equals((string)($_SESSION['sams_csrf_token'] ?? ''), (string)$token);
}
