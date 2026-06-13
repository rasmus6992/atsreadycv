<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use CvTailor\Database\Connection;
use CvTailor\Repositories\CvTransactionRepository;
use CvTailor\Services\DocxGenerator;
use CvTailor\Support\Security;

Security::startSession();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($id === false || $id === null) {
    http_response_code(400);
    exit('Invalid document ID.');
}

if (!Security::canDownload((int) $id)) {
    http_response_code(403);
    exit('This document is not available in your current session.');
}

try {
    $pdo = Connection::make(cv_config('database'));
    $repository = new CvTransactionRepository($pdo);
    $tailoredCv = $repository->findTailoredCv((int) $id);
} catch (Throwable $exception) {
    error_log('CV download query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Could not prepare the document.');
}

if ($tailoredCv === null) {
    http_response_code(404);
    exit('Document not found.');
}

$tempFile = tempnam(sys_get_temp_dir(), 'cv_docx_');

if ($tempFile === false) {
    http_response_code(500);
    exit('Could not create a temporary document file.');
}

try {
    $generator = new DocxGenerator();
    $generator->create($tailoredCv, $tempFile);

    $filename = 'tailored_cv.docx';
    $fileSize = filesize($tempFile);

    if ($fileSize === false || $fileSize < 1) {
        throw new RuntimeException('The generated DOCX file is empty.');
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    readfile($tempFile);
} catch (Throwable $exception) {
    error_log('DOCX generation failed: ' . $exception->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
    }

    exit(
        $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Could not generate the DOCX file.'
    );
} finally {
    if (is_file($tempFile)) {
        @unlink($tempFile);
    }
}
