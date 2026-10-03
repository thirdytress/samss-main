<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$currentUser = sams_authenticated_user();
if (!$currentUser || ($currentUser['role'] ?? null) !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$applicationId = (int) ($_GET['application_id'] ?? 0);
if ($applicationId <= 0) {
    http_response_code(400);
    exit('Invalid application.');
}

try {
    $pdo = sams_pdo();
    $columnExists = static function (string $column) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $statement->execute([
            'table_name' => 'document_uploads',
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() > 0;
    };

    if (!$columnExists('application_id') || !$columnExists('document_type') || !$columnExists('file_path')) {
        http_response_code(404);
        exit('COR not found.');
    }

    $orderBy = $columnExists('uploaded_at')
        ? 'uploaded_at DESC'
        : ($columnExists('upload_id') ? 'upload_id DESC' : 'application_id DESC');
    $documentStatement = $pdo->prepare(
        "SELECT file_path
         FROM document_uploads
         WHERE application_id = :application_id
           AND LOWER(document_type) IN ('class_schedule', 'cor', 'certificate_of_registration')
         ORDER BY {$orderBy}
         LIMIT 1"
    );
    $documentStatement->execute(['application_id' => $applicationId]);
    $document = $documentStatement->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        http_response_code(404);
        exit('COR not found.');
    }

    $configuredDocumentsRoot = trim((string) (getenv('SAMS_DOCUMENTS_ROOT') ?: ''));
    $documentsRoot = $configuredDocumentsRoot !== ''
        ? $configuredDocumentsRoot
        : __DIR__ . '/../uploads/documents';
    $uploadsRoot = realpath($documentsRoot);
    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $document['file_path']);
    $storedPrefix = 'uploads' . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR;
    if (strncmp($relativePath, $storedPrefix, strlen($storedPrefix)) === 0) {
        $relativePath = substr($relativePath, strlen($storedPrefix));
    }
    $filePath = $uploadsRoot !== false
        ? realpath($uploadsRoot . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR))
        : false;
    if ($uploadsRoot === false || $filePath === false || !is_file($filePath)) {
        http_response_code(404);
        exit('COR file not found.');
    }

    $documentsPrefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($filePath, $documentsPrefix, strlen($documentsPrefix)) !== 0) {
        http_response_code(404);
        exit('COR file not found.');
    }

    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo ? finfo_file($fileInfo, $filePath) : false;
    if ($fileInfo) {
        finfo_close($fileInfo);
    }

    if (!in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
        http_response_code(415);
        exit('Unsupported COR file type.');
    }

    $filename = basename($filePath);
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . (string) filesize($filePath));
    readfile($filePath);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    exit('Unable to load COR preview.');
}