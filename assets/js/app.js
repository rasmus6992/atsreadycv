const form = document.getElementById('cvForm');
const originalCv = document.getElementById('original_cv');
const jobDescription = document.getElementById('job_description');
const cvCounter = document.getElementById('cvCounter');
const jdCounter = document.getElementById('jdCounter');
const submitButton = document.getElementById('submitButton');
const buttonIcon = document.getElementById('buttonIcon');
const buttonSpinner = document.getElementById('buttonSpinner');
const buttonText = document.getElementById('buttonText');
const loadingOverlay = document.getElementById('loadingOverlay');
const alertBox = document.getElementById('alertBox');
const rateLimitNotice = document.getElementById('rateLimitNotice');
const emptyState = document.getElementById('emptyState');
const resultContent = document.getElementById('resultContent');
const resultActions = document.getElementById('resultActions');
const downloadButton = document.getElementById('downloadButton');
const copyButton = document.getElementById('copyButton');
const printButton = document.getElementById('printButton');

let latestMarkdown = '';
let loading = false;
let rateLimited = false;
let rateLimitResetAt = null;
let rateLimitTimer = null;

function updateCounter(field, counter) {
    counter.textContent = `${field.value.length.toLocaleString()} characters`;
}

originalCv.addEventListener('input', () => updateCounter(originalCv, cvCounter));
jobDescription.addEventListener('input', () => updateCounter(jobDescription, jdCounter));

function escapeHtml(value) {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function formatInline(value) {
    let safe = escapeHtml(value);
    safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    safe = safe.replace(/__(.+?)__/g, '<strong>$1</strong>');
    safe = safe.replace(/(^|[^*])\*([^*]+)\*(?!\*)/g, '$1<em>$2</em>');
    return safe;
}

// Deliberately supports only a restricted Markdown subset.
// Input is escaped first, so AI-generated HTML cannot execute.
function renderMarkdown(markdown) {
    const lines = markdown.split(/\r?\n/);
    let html = '';
    let listOpen = false;

    const closeList = () => {
        if (listOpen) {
            html += '</ul>';
            listOpen = false;
        }
    };

    for (const sourceLine of lines) {
        const line = sourceLine.trim();

        if (!line) {
            closeList();
            html += '<div class="h-1"></div>';
            continue;
        }

        const heading = line.match(/^(#{1,4})\s+(.+)$/);
        if (heading) {
            closeList();
            const level = Math.min(heading[1].length, 4);
            html += `<h${level}>${formatInline(heading[2])}</h${level}>`;
            continue;
        }

        const listItem = line.match(/^(?:[-*•]|\d+\.)\s+(.+)$/);
        if (listItem) {
            if (!listOpen) {
                html += '<ul>';
                listOpen = true;
            }
            html += `<li>${formatInline(listItem[1])}</li>`;
            continue;
        }

        if (line === '---' || line === '***') {
            closeList();
            html += '<hr>';
            continue;
        }

        closeList();
        html += `<p>${formatInline(line)}</p>`;
    }

    closeList();
    return html;
}

function showAlert(message, type = 'error') {
    alertBox.className = 'mb-5 rounded-2xl border px-4 py-3 text-sm';

    if (type === 'success') {
        alertBox.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-800');
    } else if (type === 'warning') {
        alertBox.classList.add('border-amber-200', 'bg-amber-50', 'text-amber-900');
    } else {
        alertBox.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-800');
    }

    alertBox.textContent = message;
    alertBox.classList.remove('hidden');
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideAlert() {
    alertBox.classList.add('hidden');
    alertBox.textContent = '';
}

function formatDuration(totalSeconds) {
    const seconds = Math.max(0, Math.ceil(totalSeconds));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
    }

    return `${minutes}:${String(remainingSeconds).padStart(2, '0')}`;
}

function humanRetryText(totalSeconds) {
    const seconds = Math.max(0, Math.ceil(totalSeconds));
    const minutes = Math.max(1, Math.ceil(seconds / 60));

    if (minutes >= 60) {
        return 'about 1 hour';
    }

    return `${minutes} minute${minutes === 1 ? '' : 's'}`;
}

function updateSubmitState() {
    submitButton.disabled = loading || rateLimited;
    buttonSpinner.classList.toggle('hidden', !loading);
    buttonIcon.classList.toggle('hidden', loading);

    if (loading) {
        buttonText.textContent = 'Generating CV...';
    } else if (rateLimited && rateLimitResetAt !== null) {
        const secondsLeft = Math.max(0, (rateLimitResetAt - Date.now()) / 1000);
        buttonText.textContent = `Retry in ${formatDuration(secondsLeft)}`;
    } else {
        buttonText.textContent = 'Generate tailored CV';
    }
}

function setLoading(isLoading) {
    loading = isLoading;
    updateSubmitState();
    loadingOverlay.classList.toggle('hidden', !isLoading);
    loadingOverlay.classList.toggle('flex', isLoading);
    loadingOverlay.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
}

function clearRateLimitTimer() {
    if (rateLimitTimer !== null) {
        window.clearInterval(rateLimitTimer);
        rateLimitTimer = null;
    }
}

function setAvailableRateLimit(rateLimit) {
    clearRateLimitTimer();
    rateLimited = false;
    rateLimitResetAt = null;

    const limit = Number(rateLimit?.limit ?? 5);
    const remaining = Number(rateLimit?.remaining ?? limit);

    if (remaining === limit) {
        rateLimitNotice.textContent = `${limit} generation attempts are available for this IP during the next hour.`;
    } else {
        rateLimitNotice.textContent = `${remaining} of ${limit} generation attempts remain in the current one-hour window.`;
    }

    updateSubmitState();
}

function setBlockedRateLimit(rateLimit, announce = false) {
    clearRateLimitTimer();

    const resetTimestamp = rateLimit?.reset_at
        ? Date.parse(rateLimit.reset_at)
        : Date.now() + (Number(rateLimit?.retry_after ?? 3600) * 1000);

    if (!Number.isFinite(resetTimestamp) || resetTimestamp <= Date.now()) {
        setAvailableRateLimit({ limit: Number(rateLimit?.limit ?? 5), remaining: 5 });
        return;
    }

    rateLimited = true;
    rateLimitResetAt = resetTimestamp;

    const refreshCountdown = () => {
        const secondsLeft = Math.max(0, (rateLimitResetAt - Date.now()) / 1000);

        if (secondsLeft <= 0) {
            setAvailableRateLimit({ limit: Number(rateLimit?.limit ?? 5), remaining: Number(rateLimit?.limit ?? 5) });
            return;
        }

        rateLimitNotice.textContent = `This IP has used all ${Number(rateLimit?.limit ?? 5)} attempts. New attempts will be available in ${formatDuration(secondsLeft)}.`;
        updateSubmitState();
    };

    refreshCountdown();
    rateLimitTimer = window.setInterval(refreshCountdown, 1000);

    if (announce) {
        const secondsLeft = Math.max(0, (rateLimitResetAt - Date.now()) / 1000);
        showAlert(
            `This IP has reached the hourly limit. Please retry in ${humanRetryText(secondsLeft)}.`,
            'warning'
        );
    }
}

function applyRateLimit(rateLimit, announce = false) {
    if (!rateLimit || typeof rateLimit !== 'object') {
        return;
    }

    if (rateLimit.limited || Number(rateLimit.remaining) <= 0) {
        setBlockedRateLimit(rateLimit, announce);
    } else {
        setAvailableRateLimit(rateLimit);
    }
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    hideAlert();

    if (rateLimited) {
        const secondsLeft = rateLimitResetAt === null
            ? 3600
            : Math.max(0, (rateLimitResetAt - Date.now()) / 1000);
        showAlert(`The hourly limit has been reached. Please retry in ${humanRetryText(secondsLeft)}.`, 'warning');
        return;
    }

    if (!originalCv.value.trim() || !jobDescription.value.trim()) {
        showAlert('Paste both the original CV and the target job description.');
        return;
    }

    setLoading(true);

    try {
        const response = await fetch('process.php', {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        let data;
        try {
            data = await response.json();
        } catch (parseError) {
            throw new Error('The server returned an unreadable response. Check PHP error logs and configuration.');
        }

        if (!response.ok || !data.success) {
            if (response.status === 429 && data.rate_limit) {
                applyRateLimit(data.rate_limit, false);
                const retrySeconds = Number(data.rate_limit.retry_after ?? 3600);
                showAlert(
                    `${data.message || 'The hourly limit has been reached.'} Retry in ${humanRetryText(retrySeconds)}.`,
                    'warning'
                );
                return;
            }

            if (data.rate_limit) {
                applyRateLimit(data.rate_limit, false);
            }

            throw new Error(data.message || 'CV generation failed.');
        }

        latestMarkdown = data.tailored_cv;
        resultContent.innerHTML = renderMarkdown(latestMarkdown);
        resultContent.classList.remove('hidden');
        emptyState.classList.add('hidden');
        resultActions.classList.remove('hidden');
        resultActions.classList.add('flex');
        downloadButton.href = data.download_url;

        applyRateLimit(data.rate_limit, false);

        const remaining = Number(data.rate_limit?.remaining ?? 0);
        const successMessage = remaining > 0
            ? `Your tailored CV has been generated successfully. ${remaining} attempt${remaining === 1 ? '' : 's'} remain this hour.`
            : 'Your tailored CV has been generated successfully. You have used all 5 attempts; try again when the one-hour window resets.';

        showAlert(successMessage, 'success');

        if (window.innerWidth < 1280) {
            document.getElementById('resultCard').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    } catch (error) {
        showAlert(error instanceof Error ? error.message : 'An unexpected error occurred.');
    } finally {
        setLoading(false);
    }
});

copyButton.addEventListener('click', async () => {
    if (!latestMarkdown) return;

    try {
        await navigator.clipboard.writeText(latestMarkdown);
        const previousText = copyButton.textContent;
        copyButton.textContent = 'Copied';
        setTimeout(() => {
            copyButton.textContent = previousText;
        }, 1600);
    } catch (error) {
        showAlert('Could not copy automatically. Select the result and copy it manually.');
    }
});

let titleBeforePrint = document.title;

function prepareCleanPrint() {
    titleBeforePrint = document.title;
    // Browsers may use the page title in their optional print header.
    document.title = '\u200B';
}

function restoreTitleAfterPrint() {
    document.title = titleBeforePrint || '\u200B';
}

window.addEventListener('beforeprint', prepareCleanPrint);
window.addEventListener('afterprint', restoreTitleAfterPrint);

printButton.addEventListener('click', () => {
    prepareCleanPrint();
    window.print();
    window.setTimeout(restoreTitleAfterPrint, 1000);
});

applyRateLimit(window.CV_TAILOR_INITIAL_RATE_LIMIT, true);
