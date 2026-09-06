<script>
    (() => {
        let attempts = 0;
        let refreshInFlight = false;
        let forcedRefreshQueued = false;
        // Server-side pending freshness lasts five minutes. Six minutes of
        // polling guarantees a delayed but valid job can still replace this
        // panel with its terminal state.
        const maximumAttempts = 180;

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
