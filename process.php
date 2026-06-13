<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use CvTailor\Database\Connection;
use CvTailor\Repositories\CvTransactionRepository;
use CvTailor\Services\OpenAIClient;
use CvTailor\Services\RateLimiter;
use CvTailor\Support\Http;
use CvTailor\Support\Security;
use CvTailor\Support\Text;

Http::sendSecurityHeaders();
Http::sendNoCacheHeaders();
Security::startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::json(false, ['message' => 'Method not allowed.'], 405);
}

if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    Http::json(false, [
        'message' => 'Your session expired. Refresh the page and try again.',
    ], 419);
}

// Keep the existing browser-session protection against accidental double clicks.
$now = time();
$lastRequest = isset($_SESSION['last_generation_request'])
    ? (int) $_SESSION['last_generation_request']
    : 0;

if (($now - $lastRequest) < 5) {
    Http::json(false, [
        'message' => 'Please wait a few seconds before submitting again.',
    ], 429);
}

$_SESSION['last_generation_request'] = $now;

$originalCv = isset($_POST['original_cv']) && is_string($_POST['original_cv'])
    ? trim($_POST['original_cv'])
    : '';
$jobDescription = isset($_POST['job_description']) && is_string($_POST['job_description'])
    ? trim($_POST['job_description'])
    : '';

if ($originalCv === '' || $jobDescription === '') {
    Http::json(false, [
        'message' => 'Both the original CV and job description are required.',
    ], 422);
}

$appConfig = cv_config('app');
$maximumCharacters = (int) ($appConfig['max_input_characters'] ?? 100000);

if (
    Text::characterCount($originalCv) > $maximumCharacters
    || Text::characterCount($jobDescription) > $maximumCharacters
) {
    Http::json(false, [
        'message' => "Each input must be {$maximumCharacters} characters or fewer.",
    ], 422);
}

try {
    $pdo = Connection::make(cv_config('database'));
} catch (Throwable $exception) {
    Http::json(false, ['message' => $exception->getMessage()], 500);
}

$rateConfig = is_array($appConfig['rate_limit'] ?? null)
    ? $appConfig['rate_limit']
    : [];

try {
    $rateLimiter = new RateLimiter($pdo, $rateConfig);
    $clientIp = Security::clientIp(
        (bool) ($rateConfig['trust_cloudflare_ip_header'] ?? false)
    );
    $rateLimit = $rateLimiter->consume($clientIp);
} catch (Throwable $exception) {
    error_log('Rate-limit check failed: ' . $exception->getMessage());
    Http::json(false, [
        'message' => 'Rate limiting is not configured. Import database/migration_v4.sql and try again.',
    ], 500);
}

header('X-RateLimit-Limit: ' . (int) $rateLimit['limit']);
header('X-RateLimit-Remaining: ' . (int) $rateLimit['remaining']);
if (is_string($rateLimit['reset_at']) && $rateLimit['reset_at'] !== '') {
    $resetTimestamp = strtotime($rateLimit['reset_at']);
    if ($resetTimestamp !== false) {
        header('X-RateLimit-Reset: ' . $resetTimestamp);
    }
}

if (!$rateLimit['allowed']) {
    header('Retry-After: ' . max(1, (int) $rateLimit['retry_after']));

    Http::json(false, [
        'message' => 'This IP has reached the limit of 5 CV generation attempts. Please retry when the one-hour window resets.',
        'rate_limit' => $rateLimit,
    ], 429);
}

try {
    $openAI = new OpenAIClient(cv_config('openai'));
    $tailoredCv = $openAI->generateTailoredCv($originalCv, $jobDescription);
    $tailoredCv = Text::stripOuterMarkdownFence($tailoredCv);
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
    $configurationError = str_starts_with($message, 'OpenAI API key')
        || str_starts_with($message, 'The PHP cURL extension')
        || str_starts_with($message, 'Could not prepare');

    Http::json(false, [
        'message' => $message,
        'rate_limit' => $rateLimit,
    ], $configurationError ? 500 : 502);
}

try {
    $repository = new CvTransactionRepository($pdo);
    $transactionId = $repository->create(
        $originalCv,
        $jobDescription,
        $tailoredCv
    );
} catch (Throwable $exception) {
    error_log('CV transaction insert failed: ' . $exception->getMessage());
    Http::json(false, [
        'message' => 'The CV was generated but could not be saved. Please try again.',
        'rate_limit' => $rateLimit,
    ], 500);
}

Security::authorizeDownload(
    $transactionId,
    (int) ($appConfig['authorized_download_limit'] ?? 20)
);

Http::json(true, [
    'id' => $transactionId,
    'tailored_cv' => $tailoredCv,
    'download_url' => 'download.php?id=' . $transactionId,
    'rate_limit' => $rateLimit,
]);
