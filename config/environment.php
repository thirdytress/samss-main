<?php
declare(strict_types=1);

// Simple .env loader: parses KEY=VALUE lines and sets getenv/$_ENV/$_SERVER
$envPath = __DIR__ . '/../.env';
if (!file_exists($envPath)) {
    return;
}
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (!str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    // Remove optional surrounding quotes
    if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
        $v = substr($v, 1, -1);
    }
    putenv($k . '=' . $v);
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}
