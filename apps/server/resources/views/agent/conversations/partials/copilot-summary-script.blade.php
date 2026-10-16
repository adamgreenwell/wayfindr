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

            if (!panel || (pending && attempts >= maximumAttempts)) {
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

                if ([204, 403, 404, 410].includes(response.status)) {
                    panel.remove();

                    return;
                }

                if (!response.ok) {
                    throw new Error('Summary refresh failed.');
                }

                const markup = await response.text();

                if (panel.isConnected) {
                    panel.outerHTML = markup;
                }
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

                if (currentPanel) {
                    // Ready output is re-authorized too, so policy edits withdraw it.
                    window.setTimeout(() => refresh(), currentPanel.dataset.state === 'pending' ? 2000 : 10000);
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
