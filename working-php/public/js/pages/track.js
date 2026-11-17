/**
 * Guest tracking page (src/Views/guest/track.php): adds tracked documents without reloading and keeps their cards
 * current with a poll (backing off when the server answers 429 Too Many Requests).
 *
 * The camera scan of a tracking code is components/guest-qr-scanner.js; a scanned code arrives as `qrcamera:scanned`.
 */
DtsPage.onLoad('[data-page="guest-track"]', function initGuestTrackPage(trackedDocumentsContainer) {
    'use strict';

    // How often the cards are refreshed
    const POLLING_INTERVAL_MILLISECONDS = 60000;

    // Longest pause accepted from a Retry-After header, so a bad value cannot stop polling for hours
    const MAX_POLLING_PAUSE_SECONDS = 600;

    // Pause used when a 429 answer carries no usable Retry-After
    const DEFAULT_POLLING_PAUSE_SECONDS = 60;

    const trackForm = document.getElementById('track-another-form');
    const trackModal = document.getElementById('track-modal');
    const trackingCodeInput = document.getElementById('tracking_code_input');
    const trackErrorMessage = document.getElementById('track-error-message');
    const scannerModal = document.getElementById('qr-scanner-modal');

    // Time (ms since epoch) before which polling stays quiet, set when the server answers 429 Too Many Requests
    let pollingPausedUntil = 0;

    // Show the error in the live region and mark the field invalid (the field points at the message with aria-describedby)
    function displayError(message) {
        trackErrorMessage.textContent = message;
        trackErrorMessage.classList.remove('hidden');
        trackingCodeInput.setAttribute('aria-invalid', 'true');
    }

    // Hide the error again
    function clearError() {
        trackErrorMessage.classList.add('hidden');
        trackErrorMessage.textContent = '';
        trackingCodeInput.removeAttribute('aria-invalid');
    }

    // Read Retry-After from a 429 response, pause polling until it has passed, and return the seconds to wait
    function pausePollingAfterRateLimit(response) {
        const retryAfterSeconds = parseInt(response.headers.get('Retry-After'), 10);
        const requestedSeconds = Number.isFinite(retryAfterSeconds) && retryAfterSeconds > 0 ? retryAfterSeconds : DEFAULT_POLLING_PAUSE_SECONDS;
        const waitSeconds = Math.min(requestedSeconds, MAX_POLLING_PAUSE_SECONDS);
        pollingPausedUntil = Date.now() + waitSeconds * 1000;
        return waitSeconds;
    }

    // Replace the codes of the address bar (a failed code is removed again)
    function pushCodes(urlParams, codes) {
        urlParams.set('codes', codes.join(','));
        history.pushState(null, '', `?${urlParams.toString()}`);
    }

    // Say why a card could not be loaded, and remove the code from the address bar again
    function reportLoadFailure(response, trackingCode, urlParams, currentCodes) {
        if (response.status === 404) {
            displayError(`Document with tracking code ${trackingCode} not found.`);
        } else if (response.status === 429) {
            // Rate limited: say when to try again, and hold the polling back until then
            const waitSeconds = pausePollingAfterRateLimit(response);
            displayError(`Too many requests from your connection. Please wait ${waitSeconds} second(s) and try again.`);
        } else {
            displayError('Error tracking document. Please try again.');
        }

        const failedCodeIndex = currentCodes.indexOf(trackingCode);
        if (failedCodeIndex > -1) {
            currentCodes.splice(failedCodeIndex, 1);
            pushCodes(urlParams, currentCodes);
        }
    }

    // Asynchronously fetches and adds the document tracking card, preventing duplicate queries for the same tracking code.
    // The card is HTML rendered by the server (src/Views/guest/partials/document-card.php, every value escaped there).
    async function trackDocument(trackingCode) {
        clearError();
        if (!trackingCode) {
            displayError('Please enter a tracking code.');
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const currentCodes = urlParams.get('codes') ? urlParams.get('codes').split(',') : [];
        if (currentCodes.includes(trackingCode)) {
            displayError(`Document ${trackingCode} is already being tracked on this page.`);
            return;
        }

        currentCodes.push(trackingCode);
        pushCodes(urlParams, currentCodes);

        try {
            const response = await fetch(`/api/track/module/${encodeURIComponent(trackingCode)}`);
            if (!response.ok) {
                reportLoadFailure(response, trackingCode, urlParams, currentCodes);
                return;
            }
            const cardHtml = await response.text();

            // Remove the "No documents" alert if it exists
            trackedDocumentsContainer.querySelector('.bg-blue-50')?.remove();

            trackedDocumentsContainer.insertAdjacentHTML('beforeend', cardHtml);
            ModalDialog.close(trackModal);
            trackingCodeInput.value = '';
        } catch (error) {
            console.error('Error fetching document module:', error);
            displayError('Network error. Please try again.');
        }
    }

    // Refresh the card of one tracked document when its status or step changed; false when polling must stop (429)
    async function refreshCardIfChanged(card, update) {
        const isChanged = card.dataset.status !== update.status || card.dataset.currentStep != update.current_step;
        if (!isChanged) return true;

        const cardResponse = await fetch(`/api/track/module/${encodeURIComponent(update.tracking_code)}`);
        if (cardResponse.status === 429) {
            // Stop refreshing cards; the next poll after Retry-After picks the changes up
            pausePollingAfterRateLimit(cardResponse);
            return false;
        }
        if (cardResponse.ok) {
            card.outerHTML = await cardResponse.text();
        }
        return true;
    }

    // Periodically polls the server to check if any tracked document has changed status, automatically refreshing the card if it has.
    async function pollForUpdates() {
        const documentCards = Array.from(document.querySelectorAll('.document-card'));
        if (documentCards.length === 0) return;

        // Rate limited a moment ago: wait until Retry-After has passed (one second of tolerance, so a pause that ends
        // just as the next poll is due does not cost an extra polling interval)
        if (Date.now() < pollingPausedUntil - 1000) return;

        const trackingCodes = documentCards.map((card) => card.dataset.trackingCode);
        try {
            const response = await fetch(`/api/track/status?codes=${trackingCodes.join(',')}`);
            if (response.status === 429) {
                pausePollingAfterRateLimit(response);
                return;
            }
            if (!response.ok) return;

            const statuses = await response.json();
            for (const update of statuses) {
                const card = documentCards.find((candidate) => candidate.dataset.trackingCode === update.tracking_code);
                if (!card) continue;
                if (!(await refreshCardIfChanged(card, update))) return;
            }
        } catch (error) {
            console.error('Error during polling:', error);
        }
    }

    trackingCodeInput.addEventListener('input', clearError);

    if (trackForm) {
        trackForm.addEventListener('submit', function (event) {
            event.preventDefault();
            trackDocument(trackingCodeInput.value.trim());
        });
    }

    // A code scanned with the camera
    scannerModal.addEventListener('qrcamera:scanned', (event) => trackDocument(event.detail.text));

    // Real-time polling; the timer stops when the page is replaced
    const pollingTimer = setInterval(pollForUpdates, POLLING_INTERVAL_MILLISECONDS);
    return function stopPolling() {
        clearInterval(pollingTimer);
    };
});
