<script>
    (() => {
        let attempts = 0;
        let refreshInFlight = false;
        let forcedRefreshQueued = false;
        // Cover the five-minute queued freshness window plus the 85-second
        // running-job budget, with enough room for the terminal poll.
        const maximumAttempts = 210;

        const refresh = async (force = false) => {
            const panel = document.querySelector('[data-copilot-summary]');
            const pending = panel?.dataset.state === 'pending';

            if (!panel || (!pending && !force) || (pending && attempts >= maximumAttempts)) {
                return;
            }

            if (refreshInFlight) {
                forcedRefreshQueued = forcedRefreshQueued || force;

                return;
            }

            if (pending) {
                attempts += 1;
            }

            refreshInFlight = true;

            try {
                const response = await fetch(panel.dataset.statusUrl, {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.status === 204 || response.status === 404) {
                    panel.remove();

                    return;
                }

                if (!response.ok) {
                    throw new Error('Summary refresh failed.');
                }

                panel.outerHTML = await response.text();
            } catch (error) {
                // Keep the queued state intact. A normal page refresh can
                // recover it without turning a transient poll failure into a
                // false provider failure.
            } finally {
                refreshInFlight = false;

                if (forcedRefreshQueued) {
                    forcedRefreshQueued = false;
                    void refresh(true);

                    return;
                }

                const currentPanel = document.querySelector('[data-copilot-summary]');

                if (currentPanel?.dataset.state === 'pending') {
                    window.setTimeout(() => refresh(), 2000);
                }
            }
        };

        window.wayfindrConversationSummaryTranscriptUpdated = () => {
            const panel = document.querySelector('[data-copilot-summary]');

            if (!panel || panel.dataset.state !== 'ready') {
                return;
            }

            const staleNotice = panel.querySelector('[data-copilot-summary-stale]');

            if (staleNotice) {
                staleNotice.hidden = false;
            }

            attempts = 0;
            void refresh(true);
        };

        window.setTimeout(() => refresh(), 1200);
    })();
</script>
