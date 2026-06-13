<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use CvTailor\Database\Connection;
use CvTailor\Services\RateLimiter;
use CvTailor\Support\Http;
use CvTailor\Support\Security;

Http::sendSecurityHeaders();
Http::sendNoCacheHeaders();
Security::startSession();

$csrfToken = Security::csrfToken();
$rateLimitStatus = null;

// Reading the current limit on page load lets the countdown survive refreshes.
// A database/configuration problem must not prevent the form from rendering.
try {
    $appConfig = cv_config('app');
    $rateConfig = is_array($appConfig['rate_limit'] ?? null)
        ? $appConfig['rate_limit']
        : [];
    $pdo = Connection::make(cv_config('database'));
    $rateLimiter = new RateLimiter($pdo, $rateConfig);
    $clientIp = Security::clientIp(
        (bool) ($rateConfig['trust_cloudflare_ip_header'] ?? false)
    );
    $rateLimitStatus = $rateLimiter->status($clientIp);
} catch (Throwable $exception) {
    error_log('Could not read rate-limit status: ' . $exception->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>​</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    boxShadow: {
                        soft: '0 18px 55px -28px rgba(15, 23, 42, 0.35)'
                    }
                }
            }
        };
    </script>

    <link rel="stylesheet" href="assets/css/app.css?v=4.0.0">
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <div class="pointer-events-none fixed inset-x-0 top-0 h-72 bg-gradient-to-br from-indigo-100 via-sky-50 to-transparent"></div>

    <main class="relative mx-auto max-w-[1500px] px-4 py-8 sm:px-6 lg:px-8">
        <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">
                    Tailor your CV for the role
                </h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base">
                    Paste your existing CV and the target job description. The portal will create a truthful, ATS-friendly version without inventing experience.
                </p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-900 sm:max-w-sm">
                CV and JD data are sent to the OpenAI API and stored in your configured MySQL database.<br>
                <span class="font-semibold">Usage limit:</span> 5 generation attempts per IP every hour.
            </div>
        </header>

        <div id="alertBox" class="mb-5 hidden rounded-2xl border px-4 py-3 text-sm" role="alert"></div>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-soft sm:p-7">
                <div class="mb-6 flex items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-indigo-600">Step 1</p>
                        <h2 class="mt-1 text-xl font-bold text-slate-950">Provide source content</h2>
                    </div>
                    <div class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-medium text-slate-600">
                        Plain text works best
                    </div>
                </div>

                <form id="cvForm" class="space-y-5" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <label for="original_cv" class="text-sm font-bold text-slate-800">Original CV</label>
                            <span id="cvCounter" class="text-xs text-slate-400">0 characters</span>
                        </div>
                        <textarea
                            id="original_cv"
                            name="original_cv"
                            rows="17"
                            maxlength="100000"
                            required
                            spellcheck="true"
                            placeholder="Paste the complete original CV here..."
                            class="w-full rounded-2xl border border-slate-300 bg-slate-50/70 px-4 py-3 text-sm leading-6 text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
                        ></textarea>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <label for="job_description" class="text-sm font-bold text-slate-800">Target Job Description</label>
                            <span id="jdCounter" class="text-xs text-slate-400">0 characters</span>
                        </div>
                        <textarea
                            id="job_description"
                            name="job_description"
                            rows="14"
                            maxlength="100000"
                            required
                            spellcheck="true"
                            placeholder="Paste the complete job description here..."
                            class="w-full rounded-2xl border border-slate-300 bg-slate-50/70 px-4 py-3 text-sm leading-6 text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
                        ></textarea>
                    </div>

                    <button
                        id="submitButton"
                        type="submit"
                        class="group flex w-full items-center justify-center gap-3 rounded-2xl bg-slate-950 px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-slate-300 transition hover:-translate-y-0.5 hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-200 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:translate-y-0"
                    >
                        <svg id="buttonIcon" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 3v18M3 12h18" />
                        </svg>
                        <svg id="buttonSpinner" class="hidden h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span id="buttonText">Generate tailored CV</span>
                    </button>

                    <p id="rateLimitNotice" class="text-center text-xs leading-5 text-slate-500" aria-live="polite">
                        Up to 5 generation attempts are available per IP every hour.
                    </p>
                </form>
            </section>

            <section id="resultCard" class="min-h-[720px] rounded-3xl border border-slate-200 bg-white p-5 shadow-soft sm:p-7">
                <div id="resultToolbar" class="mb-6 flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-600">Step 2</p>
                        <h2 class="mt-1 text-xl font-bold text-slate-950">Tailored CV</h2>
                    </div>

                    <div id="resultActions" class="hidden flex-wrap gap-2">
                        <button id="copyButton" type="button" class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50">
                            Copy Markdown
                        </button>
                        <button id="printButton" type="button" title="For a clean PDF, disable Headers and footers in the browser print dialog." class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50">
                            Print / Save PDF
                        </button>
                        <a id="downloadButton" href="#" class="rounded-xl bg-indigo-600 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-indigo-700">
                            Download .DOCX
                        </a>
                    </div>
                </div>

                <div id="emptyState" class="flex min-h-[590px] items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/70 p-8 text-center">
                    <div class="max-w-sm">
                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-indigo-600 shadow-sm ring-1 ring-slate-200">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                                <path d="M14 2v6h6M8 13h8M8 17h8M8 9h2" />
                            </svg>
                        </div>
                        <h3 class="mt-4 text-base font-bold text-slate-800">Your tailored CV will appear here</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">
                            Generation may take a little longer for lengthy CVs and job descriptions.
                        </p>
                    </div>
                </div>

                <article id="resultContent" class="resume-output hidden text-sm text-slate-700"></article>
            </section>
        </div>
    </main>

    <div id="loadingOverlay" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm" aria-hidden="true">
        <div class="w-full max-w-sm rounded-3xl bg-white p-7 text-center shadow-2xl">
            <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-indigo-100 border-t-indigo-600"></div>
            <h2 class="mt-5 text-lg font-bold text-slate-950">Tailoring your CV</h2>
            <p class="mt-2 text-sm leading-6 text-slate-500">
                Matching verified experience to the role and improving ATS readability.
            </p>
        </div>
    </div>

    <script>
        window.CV_TAILOR_INITIAL_RATE_LIMIT = <?= json_encode(
            $rateLimitStatus,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?>;
    </script>
    <script src="assets/js/app.js?v=4.0.0" defer></script>
    <!-- Build: CV Tailor v4 - structured app + IP rate limiting -->
</body>
</html>
