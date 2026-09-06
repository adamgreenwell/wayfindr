<script>
    (() => {
        let attempts = 0;
        const maximumAttempts = 60;

        const refresh = async () => {
            const panel = document.querySelector('[data-copilot-summary]');

            if (!panel || panel.dataset.state !== 'pending' || attempts >= maximumAttempts) {
                return;
            }

            attempts += 1;

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
            }

            window.setTimeout(refresh, 2000);
        };

        window.setTimeout(refresh, 1200);
    })();
</script>
